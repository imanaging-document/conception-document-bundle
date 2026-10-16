<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

use Imanaging\ConceptionDocumentBundle\Editor\Model\DocumentDefinition;
use Symfony\Component\Yaml\Yaml;

/** Versioned ZIP/YAML transport; reads entries without extracting paths supplied by an archive. */
final class DocumentArchive
{
    public function __construct(private readonly AssetStore $assets, private readonly ContentPolicy $policy)
    {
    }

    public function write(DocumentDefinition $definition): string
    {
        $path = tempnam(sys_get_temp_dir(), 'conception-export-');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Création ZIP impossible.');
        }
        try {
            $zip->addFromString('conception.yaml', Yaml::dump(['archiveVersion' => 2,'document' => $definition->data], 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
            $included = [];
            foreach ($definition->data['pages'] as $page) {
                foreach ($page['blocks'] as $block) {
                    if (!isset($block['asset']) || isset($included[$block['asset']['id']])) {
                        continue;
                    }
                    $id = $block['asset']['id'];
                    $zip->addFromString('assets/'.$id, $this->assets->read($id));
                    $included[$id] = true;
                }
            }
        } finally {
            $zip->close();
        }
        return $path;
    }

    public function read(string $path): DocumentDefinition
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \DomainException('Archive ZIP invalide.');
        }
        try {
            $size = 0;
            if ($zip->numFiles > 2500) {
                throw new \DomainException('Archive trop volumineuse.');
            }
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entry = $zip->statIndex($i);
                $size += $entry['size'];
                if ($size > 100 * 1024 * 1024 || str_contains($entry['name'], '..') || str_contains($entry['name'], '\\') || str_starts_with($entry['name'], '/')) {
                    throw new \DomainException('Archive trop volumineuse ou chemin interdit.');
                }
            }
            $yaml = $zip->getFromName('conception.yaml');
            if ($yaml === false || strlen($yaml) > 12 * 1024 * 1024) {
                throw new \DomainException('conception.yaml absent ou trop volumineux.');
            }
            $data = Yaml::parse($yaml);
            if (!is_array($data)) {
                throw new \DomainException('Définition YAML invalide.');
            }
            $legacy = !isset($data['archiveVersion']);
            if (($data['archiveVersion'] ?? null) === 2) {
                $definition = $data['document'] ?? [];
            } elseif (isset($data['archiveVersion'])) {
                throw new \DomainException('Version d’archive non prise en charge.');
            } else {
                $definition = $this->legacy($data, $zip);
            }
            foreach ($definition['pages'] ?? [] as &$page) {
                $page['id'] = null;
                foreach ($page['blocks'] as &$block) {
                    $block['id'] = null;
                    $this->policy->validateHtml($block['text'] ?? '');
                    $this->policy->styles($block['style']);
                    foreach ($block['styles'] ?? [] as $style) {
                        $this->policy->styles($style['style']);
                    }
                    if (isset($block['asset']) && !$legacy) {
                        $bytes = $zip->getFromName('assets/'.$block['asset']['id']);
                        if ($bytes === false) {
                            throw new \DomainException('Image absente de l’archive.');
                        }
                        $asset = $this->importBytes($bytes);
                        if (!hash_equals($block['asset']['id'], $asset['id'])) {
                            throw new \DomainException('L’empreinte de l’image ne correspond pas au fichier.');
                        }
                        $block['asset'] = $asset;
                    }
                }unset($block);
            }unset($page);
            if (isset($definition['fontResources'])) {
                $this->policy->fontResources($definition['fontResources']);
            }
            return new DocumentDefinition($definition);
        } finally {
            $zip->close();
        }
    }

    private function importBytes(string $bytes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'conception-image-');
        try {
            file_put_contents($file, $bytes);
            return $this->assets->importFile($file);
        } finally {
            unlink($file);
        }
    }

    private function legacy(array $data, \ZipArchive $zip): array
    {
        $format = strtoupper($data['document_format'] ?? 'A4') === 'A3' ? 'A3' : 'A4';
        $pages = [];
        $conditions = static fn ($list) => array_map(static fn ($c) => ['type' => $c['type_condition'],'comparison' => $c['type_comparaison'],'value' => $c['valeur_comparaison']], $list ?? []);
        foreach ($data['pages'] ?? [] as $p) {
            $blocks = [];
            foreach ($p['blocs'] ?? [] as $b) {
                $styles = array_map(static fn ($s) => ['code' => $s['code'],'name' => $s['nom'],'style' => json_decode($s['style'], true, 512, JSON_THROW_ON_ERROR)], $b['styles'] ?? []);
                $root = [];
                foreach ($styles as $style) {
                    if ($style['code'] === 'root') {
                        $root = $style['style'];
                    }
                }
                $type = str_replace('bloc_forme', 'formes_predefinies', $b['type']);
                $block = ['key' => 'b'.bin2hex(random_bytes(10)),'id' => null,'type' => $type,'label' => $b['libelle'],'order' => $b['ordre'] ?? 1,'text' => $b['texte'] ?? $b['qrcode_content'] ?? '','raw' => $b['mode_raw'] ?? false,'styles' => $styles,'style' => $root,'conditions' => $conditions($b['conditions'] ?? [])];
                if (isset($b['type_forme'])) {
                    $block['shape'] = $b['type_forme'];
                }
                if ($type === 'bloc_image') {
                    $filename = $b['filename'] ?? '';
                    if (basename($filename) !== $filename) {
                        throw new \DomainException('Nom d’image invalide.');
                    }
                    $bytes = $zip->getFromName($filename);
                    if ($bytes === false) {
                        throw new \DomainException('Image absente de l’archive.');
                    }
                    $block['asset'] = $this->importBytes($bytes);
                }
                $blocks[] = $block;
            }
            $pages[] = ['key' => 'p'.bin2hex(random_bytes(10)),'id' => null,'label' => $p['libelle'],'blocks' => $blocks,'conditions' => $conditions($p['conditions'] ?? [])];
        }
        return ['schemaVersion' => 1,'label' => $data['libelle'],'pageSpec' => ['format' => $format,'orientation' => $format === 'A3' ? 'landscape' : 'portrait','margins' => ['top' => 15,'right' => 15,'bottom' => 15,'left' => 15]],'mode' => 'free','printProfile' => ['mode' => 'simple','rotateBack' => false],'pages' => $pages];
    }
}
