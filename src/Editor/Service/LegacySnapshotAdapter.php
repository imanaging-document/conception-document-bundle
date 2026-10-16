<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

use Doctrine\ORM\EntityManagerInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\{ConceptionTemplateInterface,ConceptionPageInterface,ConceptionBlocInterface,ConceptionBlocTypeInterface,ConceptionBlocStyleInterface,ConceptionConditionInterface};
use Imanaging\ConceptionDocumentBundle\Editor\Model\DocumentDefinition;

final class LegacySnapshotAdapter
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly AssetStore $assets, private readonly BlockAdapterRegistry $blockAdapters = new BlockAdapterRegistry())
    {
    }
    public function templateKey(ConceptionTemplateInterface $template): string
    {
        return hash('sha256', $this->em->getClassMetadata(get_class($template))->getName().':'.$template->getId());
    }
    public function snapshot(ConceptionTemplateInterface $template): DocumentDefinition
    {
        $pages = [];
        $orderedPages = $template->getPages()->toArray();
        usort($orderedPages, fn ($a, $b) => $a->getOrdre() <=> $b->getOrdre());
        foreach ($orderedPages as $page) {
            $blocks = [];
            $orderedBlocks = $page->getBlocs()->toArray();
            usort($orderedBlocks, fn ($a, $b) => $a->getOrdre() <=> $b->getOrdre());
            foreach ($orderedBlocks as $bloc) {
                $styles = [];
                foreach ($bloc->getStyles() as $style) {
                    $styles[] = ['code' => $style->getCode(),'name' => $style->getNom(),'style' => $style->getDecodedStyle()];
                }
                $block = ['key' => 'b'.$bloc->getId(),'id' => $bloc->getId(),'class' => $this->em->getClassMetadata(get_class($bloc))->getName(),'type' => $bloc->getType()->getCode(),'label' => $bloc->getLibelle(),'order' => $bloc->getOrdre(),'style' => $bloc->getStyleByCode('root')?->getDecodedStyle() ?? [],'styles' => $styles,'conditions' => $this->conditions($bloc->getConditions()),'text' => method_exists($bloc, 'getTexte') ? $bloc->getTexte() : '','raw' => method_exists($bloc, 'isModeRaw') ? $bloc->isModeRaw() : false];
                if (method_exists($bloc, 'getTypeForme')) {
                    $block['shape'] = $bloc->getTypeForme();
                }
                if ($block['type'] === 'bloc_image') {
                    $block['asset'] = $this->assets->importFile($this->assets->legacyPath($bloc->getPath()));
                }
                if ($custom = $this->blockAdapters->find($block['type'])) {
                    $block['extension'] = $custom->snapshot($bloc);
                }
                $blocks[] = $block;
            }
            $pages[] = ['key' => 'p'.$page->getId(),'id' => $page->getId(),'label' => $page->getLibelle(),'conditions' => $this->conditions($page->getConditions()),'blocks' => $blocks];
        }
        if (!$pages) {
            $pages[] = ['key' => 'new-page','id' => null,'label' => 'Page 1','conditions' => [],'blocks' => []];
        }
        $format = strtoupper($template->getDocumentFormat()) === 'A3' ? 'A3' : 'A4';
        return new DocumentDefinition(['schemaVersion' => 1,'label' => $template->getLibelle(),'pageSpec' => ['format' => $format,'orientation' => $format === 'A3' ? 'landscape' : 'portrait','margins' => ['top' => 15,'right' => 15,'bottom' => 15,'left' => 15]],'mode' => 'free','printProfile' => ['mode' => 'simple','rotateBack' => false],'pages' => $pages]);
    }
    public function conditions(iterable $conditions): array
    {
        $result = [];
        foreach ($conditions as $c) {
            $result[] = ['type' => $c->getTypeCondition(),'comparison' => $c->getTypeComparaison(),'value' => $c->getValeurComparaison()];
        } return $result;
    }
    public function hydrateConditions(array $conditions): array
    {
        $class = $this->em->getRepository(ConceptionConditionInterface::class)->getClassName();
        $result = [];
        foreach ($conditions as $data) {
            $c = new $class();
            $c->setTypeCondition($data['type']);
            $c->setTypeComparaison($data['comparison']);
            $c->setValeurComparaison($data['value']);
            $result[] = $c;
        } return $result;
    }
    public function hydrateBlock(array $data, bool $temporaryId = true): ConceptionBlocInterface
    {
        $type = $this->em->getRepository(ConceptionBlocTypeInterface::class)->findOneBy(['code' => $data['type']]);
        if (!$type) {
            throw new \DomainException('Type de bloc introuvable : '.$data['type']);
        }
        $class = $type->getEntity();
        $bloc = new $class();
        if ($temporaryId) {
            $bloc->setId($data['id'] ?? abs(crc32($data['key'])));
        } $bloc->setType($type);
        $bloc->setLibelle($data['label']);
        $bloc->setOrdre($data['order'] ?? 1);
        if (method_exists($bloc, 'setTexte')) {
            $bloc->setTexte($data['text'] ?? '');
            $bloc->setModeRaw($data['raw'] ?? true);
        }
        if (isset($data['shape'])) {
            $bloc->setTypeForme($data['shape']);
        }
        $styleClass = $this->em->getRepository(ConceptionBlocStyleInterface::class)->getClassName();
        foreach ($data['styles'] ?? [] as $style) {
            if ($style['code'] === 'root') {
                continue;
            }
            $bloc->getStyles()->add(new $styleClass($bloc, $style['code'], $style['name'], json_encode($style['style'], JSON_THROW_ON_ERROR)));
        }
        $bloc->getStyles()->add(new $styleClass($bloc, 'root', 'root', json_encode($data['style'], JSON_THROW_ON_ERROR)));
        if ($custom = $this->blockAdapters->find($data['type'])) {
            $custom->validate($data['extension'] ?? []);
            $custom->apply($bloc, $data['extension'] ?? []);
        }
        return $bloc;
    }
    /** Publish the projection without replacing existing entity IDs or changing active state. */
    public function apply(ConceptionTemplateInterface $template, DocumentDefinition $definition, bool $restoreConditions = false): void
    {
        $existingPages = [];
        foreach ($template->getPages() as $p) {
            $existingPages[$p->getId()] = $p;
        }
        $kept = [];
        foreach ($definition->data['pages'] as $order => $data) {
            $page = isset($data['id']) ? ($existingPages[$data['id']] ?? null) : null;
            if (!$page) {
                $class = $this->em->getRepository(ConceptionPageInterface::class)->getClassName();
                $page = new $class();
                $page->setTemplate($template);
                $template->getPages()->add($page);
                $this->em->persist($page);
            }
            if ($restoreConditions && isset($data['id'])) {
                foreach ($page->getConditions()->toArray() as $c) {
                    $page->getConditions()->removeElement($c);
                    $this->em->remove($c);
                }
            }
            if (!isset($data['id']) || $restoreConditions) {
                foreach ($this->hydrateConditions($data['conditions'] ?? []) as $c) {
                    $c->setPage($page);
                    $page->getConditions()->add($c);
                    $this->em->persist($c);
                }
            }
            $page->setOrdre($order + 1);
            $page->setLibelle($data['label']);
            $kept[] = $page;
            $existing = [];
            foreach ($page->getBlocs() as $bloc) {
                $existing[$bloc->getId()] = $bloc;
            }
            $keep = [];
            foreach ($data['blocks'] as $index => $block) {
                $bloc = isset($block['id']) ? ($existing[$block['id']] ?? null) : null;
                if (!$bloc) {
                    $bloc = $this->hydrateBlock($block, false);
                    $bloc->setPage($page);
                    $page->getBlocs()->add($bloc);
                    foreach ($this->hydrateConditions($block['conditions'] ?? []) as $c) {
                        $c->setBloc($bloc);
                        $bloc->getConditions()->add($c);
                        $this->em->persist($c);
                    }$this->em->persist($bloc);
                    foreach ($bloc->getStyles() as $s) {
                        $this->em->persist($s);
                    }
                }
                if ($restoreConditions && isset($block['id'])) {
                    foreach ($bloc->getConditions()->toArray() as $c) {
                        $bloc->getConditions()->removeElement($c);
                        $this->em->remove($c);
                    }foreach ($this->hydrateConditions($block['conditions'] ?? []) as $c) {
                        $c->setBloc($bloc);
                        $bloc->getConditions()->add($c);
                        $this->em->persist($c);
                    }
                }
                $keep[] = $bloc;
                $bloc->setOrdre($index + 1);
                $bloc->setLibelle($block['label']);
                if (method_exists($bloc, 'setTexte')) {
                    $bloc->setTexte($block['text'] ?? '');
                    $bloc->setModeRaw($block['raw'] ?? true);
                }
                if (isset($block['asset'])) {
                    $bloc->setPath('/editor-assets/'.$block['asset']['id']);
                }
                if (isset($block['shape'])) {
                    $bloc->setTypeForme($block['shape']);
                }
                if ($custom = $this->blockAdapters->find($block['type'])) {
                    $custom->validate($block['extension'] ?? []);
                    $custom->apply($bloc, $block['extension'] ?? []);
                }
                $root = $bloc->getStyleByCode('root');
                if (!$root) {
                    $class = $this->em->getRepository(ConceptionBlocStyleInterface::class)->getClassName();
                    $root = new $class($bloc, 'root', 'root');
                    $bloc->getStyles()->add($root);
                    $this->em->persist($root);
                }
                $root->setStyle(json_encode($block['style'], JSON_THROW_ON_ERROR));
            }
            foreach ($existing as $bloc) {
                if (!in_array($bloc, $keep, true)) {
                    $this->removeBlock($page, $bloc);
                }
            }
        }
        foreach ($existingPages as $page) {
            if (!in_array($page, $kept, true)) {
                foreach ($page->getBlocs()->toArray() as $bloc) {
                    $this->removeBlock($page, $bloc);
                }foreach ($page->getConditions() as $c) {
                    $this->em->remove($c);
                }$template->getPages()->removeElement($page);
                $this->em->remove($page);
            }
        }
        $template->setLibelle($definition->data['label']);
        $template->setDocumentFormat($definition->isBooklet() ? 'A3' : $definition->data['pageSpec']['format']);
    }
    private function removeBlock(ConceptionPageInterface $page, ConceptionBlocInterface $bloc): void
    {
        foreach ($bloc->getStyles() as $s) {
            $this->em->remove($s);
        }foreach ($bloc->getConditions() as $c) {
            $this->em->remove($c);
        }$page->getBlocs()->removeElement($bloc);
        $this->em->remove($bloc);
    }
}
