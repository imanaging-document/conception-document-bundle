<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionTemplateInterface;
use Imanaging\ConceptionDocumentBundle\Editor\Model\DocumentDefinition;

final class TemplateStateStore
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly LegacySnapshotAdapter $adapter, private readonly ContentPolicy $policy, private readonly DocumentHtmlBuilder $html, private readonly BlockAdapterRegistry $blockAdapters = new BlockAdapterRegistry())
    {
    }
    public function state(ConceptionTemplateInterface $template): array
    {
        $key = $this->adapter->templateKey($template);
        $db = $this->em->getConnection();
        $state = $db->fetchAssociative('SELECT * FROM conception_editor_state WHERE template_key = ?', [$key]);
        if (!$state) {
            $projection = $this->adapter->snapshot($template);
            $definition = new DocumentDefinition($projection->data + ['fontResources' => $this->html->fontCss()]);
            $revision = bin2hex(random_bytes(16));
            try {
                $db->transactional(function () use ($db, $key, $revision, $projection, $definition) {
                    $db->insert('conception_editor_state', ['template_key' => $key,'version' => 1,'current_revision' => $revision,'projection_hash' => $projection->hash()]);
                    $db->insert('conception_editor_revision', ['id' => $revision,'template_key' => $key,'version' => 1,'definition' => json_encode($definition, JSON_THROW_ON_ERROR),'created_at' => gmdate(DATE_ATOM)]);
                });
            } catch (UniqueConstraintViolationException $e) {
            }
            $state = $db->fetchAssociative('SELECT * FROM conception_editor_state WHERE template_key = ?', [$key]);
        }
        $definition = $this->revision($state['current_revision'], $key);
        if (!isset($definition->data['fontResources'])) {
            $enriched = new DocumentDefinition($definition->data + ['fontResources' => $this->html->fontCss()]);
            $revision = bin2hex(random_bytes(16));
            $next = (int)$state['version'] + 1;
            $db->transactional(function () use ($db, $state, $key, $enriched, $revision, $next) {
                if ($db->executeStatement('UPDATE conception_editor_state SET version = ?, current_revision = ? WHERE template_key = ? AND version = ?', [$next,$revision,$key,$state['version']]) !== 1) {
                    throw new \UnexpectedValueException('Modification concurrente du modèle.');
                }
                $db->insert('conception_editor_revision', ['id' => $revision,'template_key' => $key,'version' => $next,'definition' => json_encode($enriched, JSON_THROW_ON_ERROR),'created_at' => gmdate(DATE_ATOM)]);
            });
            $state = $db->fetchAssociative('SELECT * FROM conception_editor_state WHERE template_key = ?', [$key]);
        }
        return $state;
    }
    public function hasState(ConceptionTemplateInterface $template): bool
    {
        $db = $this->em->getConnection();
        if (!$db->createSchemaManager()->tablesExist(['conception_editor_state'])) {
            return false;
        }
        return (bool)$db->fetchOne('SELECT 1 FROM conception_editor_state WHERE template_key = ?', [$this->adapter->templateKey($template)]);
    }
    public function current(ConceptionTemplateInterface $template): DocumentDefinition
    {
        $state = $this->state($template);
        return $this->revision($state['current_revision'], $state['template_key']);
    }
    public function revision(string $id, string $templateKey): DocumentDefinition
    {
        $data = $this->em->getConnection()->fetchOne('SELECT definition FROM conception_editor_revision WHERE id = ? AND template_key = ?', [$id,$templateKey]);
        if (!$data) {
            throw new \DomainException('Version documentaire introuvable.');
        }
        return DocumentDefinition::fromArray(json_decode($data, true, 512, JSON_THROW_ON_ERROR));
    }
    public function document(ConceptionTemplateInterface $template, string $user): array
    {
        $state = $this->state($template);
        $key = $state['template_key'];
        $draft = $this->em->getConnection()->fetchAssociative('SELECT * FROM conception_editor_draft WHERE id = ?', [$this->draftId($key, $user)]);
        return ['version' => (int)$state['version'],'revision' => $state['current_revision'],'baseVersion' => $draft ? (int)$draft['base_version'] : (int)$state['version'],'sequence' => $draft ? (int)$draft['sequence'] : 0,'draft' => (bool)$draft,'definition' => $draft ? json_decode($draft['definition'], true, 512, JSON_THROW_ON_ERROR) : $this->revision($state['current_revision'], $key)->data];
    }
    public function saveDraft(ConceptionTemplateInterface $template, string $user, array $payload): array
    {
        $state = $this->state($template);
        $definition = DocumentDefinition::fromArray($payload['definition'] ?? []);
        $base = $this->revision($state['current_revision'], $state['template_key']);
        $this->validateChanges($base, $definition, $state['template_key']);
        if ((int)($payload['baseVersion'] ?? 0) !== (int)$state['version']) {
            throw new \UnexpectedValueException('Le modèle a changé. Votre brouillon est conservé ; rechargez ou restaurez une version.');
        }
        $id = $this->draftId($state['template_key'], $user);
        $db = $this->em->getConnection();
        $sequence = (int)($payload['sequence'] ?? 0);
        $expected = (int)($payload['expectedSequence'] ?? ($sequence - 1));
        if ($sequence < 1 || $sequence !== $expected + 1) {
            throw new \UnexpectedValueException('Révision de brouillon invalide.');
        }
        $row = ['template_key' => $state['template_key'],'user_key' => hash('sha256', $user),'base_version' => $state['version'],'sequence' => $sequence,'definition' => json_encode($definition, JSON_THROW_ON_ERROR),'updated_at' => gmdate(DATE_ATOM)];
        $existing = $db->fetchAssociative('SELECT sequence FROM conception_editor_draft WHERE id = ?', [$id]);
        if ($existing) {
            if ($expected !== (int)$existing['sequence']) {
                throw new \UnexpectedValueException('Un brouillon plus récent existe déjà.');
            }
            $changed = $db->executeStatement('UPDATE conception_editor_draft SET definition = ?, sequence = ?, updated_at = ?, base_version = ? WHERE id = ? AND sequence = ?', [$row['definition'],$sequence,$row['updated_at'],$state['version'],$id,$existing['sequence']]);
            if ($changed !== 1) {
                throw new \UnexpectedValueException('Le brouillon a changé dans un autre onglet.');
            }
        } else {
            if ($expected !== 0) {
                throw new \UnexpectedValueException('Le brouillon a changé dans un autre onglet.');
            }try {
                $db->insert('conception_editor_draft', ['id' => $id] + $row);
            } catch (UniqueConstraintViolationException $e) {
                throw new \UnexpectedValueException('Le brouillon a changé dans un autre onglet.');
            }
        }
        return ['sequence' => $sequence,'savedAt' => $row['updated_at']];
    }
    public function publish(ConceptionTemplateInterface $template, string $user, array $payload): array
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $state = $this->state($template);
            $definition = DocumentDefinition::fromArray($payload['definition'] ?? []);
            if (isset($payload['expectedSequence'])) {
                $sequence = (int)($db->fetchOne('SELECT sequence FROM conception_editor_draft WHERE id = ?', [$this->draftId($state['template_key'], $user)]) ?: 0);
                if ((int)$payload['expectedSequence'] !== $sequence) {
                    throw new \UnexpectedValueException('Un autre onglet a modifié le brouillon. Rechargez ou exportez vos modifications.');
                }
            }

            if ((int)($payload['baseVersion'] ?? 0) !== (int)$state['version']) {
                throw new \UnexpectedValueException('Le modèle a été modifié par un autre utilisateur.');
            }
            if ($this->adapter->snapshot($template)->hash() !== $state['projection_hash']) {
                throw new \UnexpectedValueException('La conception a été modifiée dans le mode avancé. Rechargez le modèle.');
            }
            $this->validateChanges($this->revision($state['current_revision'], $state['template_key']), $definition, $state['template_key']);
            $next = (int)$state['version'] + 1;
            $revision = bin2hex(random_bytes(16));
            if ($db->executeStatement('UPDATE conception_editor_state SET version = ?, current_revision = ? WHERE template_key = ? AND version = ?', [$next,$revision,$state['template_key'],$state['version']]) !== 1) {
                throw new \UnexpectedValueException('Enregistrement concurrent : rechargez le modèle.');
            }
            $this->adapter->apply($template, $definition, isset($definition->data['restoredFrom']));
            $this->em->flush();
            // Newly created entities now have IDs; preserve editor keys while recording their projection IDs.
            $data = $definition->data;
            unset($data['restoredFrom']);
            $pages = $template->getPages()->toArray();
            usort($pages, fn ($a, $b) => $a->getOrdre() <=> $b->getOrdre());
            foreach ($data['pages'] as $i => &$p) {
                $p['id'] = $pages[$i]->getId();
                $blocs = $pages[$i]->getBlocs()->toArray();
                usort($blocs, fn ($a, $b) => $a->getOrdre() <=> $b->getOrdre());
                foreach ($p['blocks'] as $j => &$b) {
                    $b['id'] = $blocs[$j]->getId();
                }unset($b);
            }unset($p);
            $definition = new DocumentDefinition($data);
            $db->insert('conception_editor_revision', ['id' => $revision,'template_key' => $state['template_key'],'version' => $next,'definition' => json_encode($definition, JSON_THROW_ON_ERROR),'created_at' => gmdate(DATE_ATOM)]);
            $db->update('conception_editor_state', ['projection_hash' => $this->adapter->snapshot($template)->hash()], ['template_key' => $state['template_key']]);
            $db->delete('conception_editor_draft', ['id' => $this->draftId($state['template_key'], $user)]);
            $db->commit();
            return ['version' => $next,'revision' => $revision,'definition' => $definition->data,'baseVersion' => $next,'sequence' => 0,'draft' => false];
        } catch (\Throwable $e) {
            $db->rollBack();
            $this->em->clear();
            throw $e;
        }
    }
    public function versions(ConceptionTemplateInterface $template): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT id, version, created_at FROM conception_editor_revision WHERE template_key = ? AND version > 0 ORDER BY version DESC', [$this->state($template)['template_key']]);
    }
    public function resetLegacy(ConceptionTemplateInterface $template): void
    {
        $db = $this->em->getConnection();
        $state = $this->state($template);
        $source = $this->adapter->snapshot($template);
        if ($source->hash() === $state['projection_hash']) {
            return;
        }
        $current = $this->revision($state['current_revision'], $state['template_key'])->data;
        $fresh = $source->data;
        $projectionHash = $source->hash();
        foreach (['mode','printProfile','fontResources'] as $meta) {
            if (isset($current[$meta])) {
                $fresh[$meta] = $current[$meta];
            }
        }
        if ($fresh['pageSpec']['format'] === $current['pageSpec']['format'] || ($current['printProfile']['mode'] ?? '') === 'booklet') {
            $fresh['pageSpec'] = $current['pageSpec'];
        }
        $previous = [];
        foreach ($current['pages'] as $p) {
            foreach ($p['blocks'] as $b) {
                $previous[$b['id']] = $b;
            }
        }
        foreach ($fresh['pages'] as &$p) {
            foreach ($p['blocks'] as &$b) {
                foreach (['fit','crop','zone','extension'] as $meta) {
                    if (isset($previous[$b['id']][$meta])) {
                        $b[$meta] = $previous[$b['id']][$meta];
                    }
                }
            }
        }unset($b,$p);
        $source = new DocumentDefinition($fresh);
        $revision = bin2hex(random_bytes(16));
        $next = (int)$state['version'] + 1;
        $db->transactional(function () use ($db, $state, $source, $revision, $next, $projectionHash) {
            if ($db->executeStatement('UPDATE conception_editor_state SET version = ?, current_revision = ?, projection_hash = ? WHERE template_key = ? AND version = ?', [$next,$revision,$projectionHash,$state['template_key'],$state['version']]) !== 1) {
                throw new \UnexpectedValueException('Modification concurrente.');
            }
            $db->insert('conception_editor_revision', ['id' => $revision,'template_key' => $state['template_key'],'version' => $next,'definition' => json_encode($source, JSON_THROW_ON_ERROR),'created_at' => gmdate(DATE_ATOM)]);
        });
    }
    public function importAsDraft(ConceptionTemplateInterface $template, string $user, DocumentDefinition $imported, int $baseVersion, int $sequence): array
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $state = $this->state($template);
            if ($baseVersion !== (int)$state['version']) {
                throw new \UnexpectedValueException('Le modèle a changé. Rechargez avant d’importer.');
            }
            $data = $imported->data;
            unset($data['restoredFrom']);
            $data['fontResources'] ??= $this->html->fontCss();
            $revision = bin2hex(random_bytes(16));
            $db->insert('conception_editor_revision', ['id' => $revision,'template_key' => $state['template_key'],'version' => 0,'definition' => json_encode($data, JSON_THROW_ON_ERROR),'created_at' => gmdate(DATE_ATOM)]);
            $result = $this->restore($template, $user, $revision, $baseVersion, $sequence);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
    /** Restoring a revision creates a recoverable draft and never mutates the published model. */
    public function restore(ConceptionTemplateInterface $template, string $user, string $revision, int $baseVersion, int $sequence): array
    {
        $state = $this->state($template);
        if ($baseVersion !== (int)$state['version']) {
            throw new \UnexpectedValueException('Le modèle a changé. Rechargez avant de restaurer.');
        }
        $base = $this->revision($state['current_revision'], $state['template_key']);
        $data = $this->restoredDefinition($base, $this->revision($revision, $state['template_key']))->data;
        $data['restoredFrom'] = $revision;
        $this->saveDraft($template, $user, ['definition' => $data,'baseVersion' => $baseVersion,'sequence' => $sequence]);
        return $this->document($template, $user);
    }
    private function restoredDefinition(DocumentDefinition $current, DocumentDefinition $historical): DocumentDefinition
    {
        $pages = [];
        $blocks = [];
        foreach ($current->data['pages'] as $p) {
            $pages[$p['id'] ?? ''] = $p;
            foreach ($p['blocks'] as $b) {
                $blocks[$b['id'] ?? ''] = $p['id'];
            }
        }
        $data = $historical->data;
        foreach ($data['pages'] as &$p) {
            if (!isset($pages[$p['id'] ?? ''])) {
                $p['id'] = null;
            }
            foreach ($p['blocks'] as &$b) {
                if (!isset($blocks[$b['id'] ?? '']) || $blocks[$b['id']] !== $p['id']) {
                    $b['id'] = null;
                }
            }
            unset($b);
        }unset($p);
        return new DocumentDefinition($data);
    }
    public function validateExport(ConceptionTemplateInterface $template, DocumentDefinition $definition, int $version): void
    {
        $key = $this->adapter->templateKey($template);
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM conception_editor_revision WHERE template_key = ? AND version = ?', [$key,$version]);
        if (!$id) {
            throw new \DomainException('Version de départ introuvable.');
        }
        $this->validateChanges($this->revision($id, $key), $definition, $key);
    }
    public function validateDefinition(ConceptionTemplateInterface $template, DocumentDefinition $definition): void
    {
        $state = $this->state($template);
        $this->validateChanges($this->revision($state['current_revision'], $state['template_key']), $definition, $state['template_key']);
    }
    private function draftId(string $key, string $user): string
    {
        return hash('sha256', $key.':'.$user);
    }
    private function validateChanges(DocumentDefinition $base, DocumentDefinition $next, string $templateKey): void
    {
        if (isset($next->data['restoredFrom'])) {
            $base = $this->restoredDefinition($base, $this->revision($next->data['restoredFrom'], $templateKey));
        }
        if (($next->data['fontResources'] ?? null) !== ($base->data['fontResources'] ?? null)) {
            throw new \DomainException('Les ressources de police du modèle sont protégées.');
        }
        $old = [];
        $pages = [];
        $parents = [];
        foreach ($base->data['pages'] as $p) {
            $pages[$p['id'] ?? $p['key']] = $p;
            foreach ($p['blocks'] as $b) {
                $old[$b['id'] ?? $b['key']] = $b;
                $parents[$b['id'] ?? $b['key']] = $p['id'] ?? $p['key'];
            }
        }
        foreach ($next->data['pages'] as $page) {
            $priorPage = isset($page['id']) ? ($pages[$page['id']] ?? null) : ($pages[$page['key']] ?? null);
            if (isset($page['id']) && !$priorPage) {
                throw new \DomainException('Page étrangère au modèle.');
            }
            if (($page['conditions'] ?? []) !== ($priorPage['conditions'] ?? [])) {
                throw new \DomainException('Modifiez les conditions dans le mode avancé.');
            }
            foreach ($page['blocks'] as $block) {
                $prior = isset($block['id']) ? ($old[$block['id']] ?? null) : ($old[$block['key']] ?? null);
                if (isset($block['id']) && !$prior) {
                    throw new \DomainException('Bloc étranger au modèle.');
                }
                if ($prior && ($parents[$block['id'] ?? $block['key']] !== ($page['id'] ?? $page['key']))) {
                    throw new \DomainException('Un bloc ne peut pas changer de page dans cette opération.');
                }
                if ($prior && ($block['type'] !== $prior['type'] || ($block['conditions'] ?? []) !== ($prior['conditions'] ?? []))) {
                    throw new \DomainException('Type et conditions du bloc protégés.');
                }
                if ($custom = $this->blockAdapters->find($block['type'])) {
                    $custom->validate($block['extension'] ?? []);
                } elseif (($block['extension'] ?? []) !== ($prior['extension'] ?? [])) {
                    throw new \DomainException('Données métier protégées.');
                }
                if (!$prior && !in_array($block['type'], ['bloc_texte','bloc_image','formes_predefinies','bloc_qrcode'], true)) {
                    throw new \DomainException('Ce type de bloc se crée dans le mode avancé.');
                }
                $locked = in_array(strtolower((string)($prior['style']['--bundle-lock'] ?? '')), ['1','true','on','yes'], true);
                if ($locked && in_array(strtolower((string)($block['style']['--bundle-lock'] ?? '')), ['1','true','on','yes'], true) && $block !== $prior) {
                    $unlocked = $block;
                    $unlocked['style']['--bundle-lock'] = $prior['style']['--bundle-lock'];
                    if ($unlocked !== $prior) {
                        throw new \DomainException('Déverrouillez ce bloc avant de le modifier.');
                    }
                }
                if (!$prior || (($block['text'] ?? '') !== ($prior['text'] ?? '') || ($block['raw'] ?? true) !== ($prior['raw'] ?? true))) {
                    $this->policy->validateHtml($block['text'] ?? '');
                }
                if ($prior && ($block['styles'] ?? []) !== ($prior['styles'] ?? [])) {
                    throw new \DomainException('Les styles métier se modifient dans le mode avancé.');
                }
                $changed = $block['style'];
                if ($prior) {
                    foreach ($changed as $k => $v) {
                        if (($prior['style'][$k] ?? null) === $v) {
                            unset($changed[$k]);
                        }
                    }
                }$this->policy->styles($changed);
            }
        }
    }
}
