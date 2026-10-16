<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Model;

/** The portable, HTML-based document format. Entity names are never used to instantiate classes. */
final class DocumentDefinition implements \JsonSerializable
{
    public const SCHEMA_VERSION = 1;

    public function __construct(public readonly array $data)
    {
        self::validate($data);
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function validate(array $data): void
    {
        if (($data['schemaVersion'] ?? null) !== self::SCHEMA_VERSION || !is_array($data['pages'] ?? null) || !$data['pages'] || count($data['pages']) > 500) {
            throw new \InvalidArgumentException('Définition documentaire invalide (1 à 500 pages).');
        }
        if (!is_string($data['label'] ?? null) || mb_strlen($data['label']) > 255) {
            throw new \InvalidArgumentException('Libellé documentaire invalide.');
        }
        $spec = $data['pageSpec'] ?? [];
        if (!in_array($spec['format'] ?? '', ['A4', 'A3'], true) || !in_array($spec['orientation'] ?? '', ['portrait', 'landscape'], true)) {
            throw new \InvalidArgumentException('Format de page invalide.');
        }
        if (!in_array($data['mode'] ?? '', ['free', 'flow'], true) || !in_array($data['printProfile']['mode'] ?? '', ['simple', 'booklet'], true)) {
            throw new \InvalidArgumentException('Mode de composition invalide.');
        }
        if (($data['printProfile']['mode'] ?? '') === 'booklet' && ($spec['format'] !== 'A4' || $spec['orientation'] !== 'portrait')) {
            throw new \InvalidArgumentException('Le livret A3 se compose en pages A4 portrait.');
        }
        $size = $spec['format'] === 'A3' ? [297, 420] : [210, 297];
        if ($spec['orientation'] === 'landscape') {
            $size = array_reverse($size);
        }
        $margins = $spec['margins'] ?? [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (!is_numeric($margins[$side] ?? null) || !is_finite((float)$margins[$side]) || $margins[$side] < 0) {
                throw new \InvalidArgumentException('Marge invalide.');
            }
        }
        if ($margins['left'] + $margins['right'] >= $size[0] || $margins['top'] + $margins['bottom'] >= $size[1]) {
            throw new \InvalidArgumentException('Les marges ne laissent aucune zone utile.');
        }
        $keys = $pageIds = $blockIds = [];
        foreach ($data['pages'] as $page) {
            if (!is_array($page) || !is_array($page['blocks'] ?? null) || count($page['blocks']) > 2000 || !is_string($page['label'] ?? null)) {
                throw new \InvalidArgumentException('Page invalide.');
            }
            self::unique($page, $keys, $pageIds);
            foreach ($page['blocks'] as $block) {
                if (!is_array($block) || !is_string($block['type'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]+$/', $block['type']) || !is_array($block['style'] ?? null) || !is_string($block['label'] ?? null) || !is_string($block['text'] ?? '')) {
                    throw new \InvalidArgumentException('Bloc invalide.');
                }
                self::unique($block, $keys, $blockIds);
                if (!in_array($block['zone'] ?? 'body', ['body', 'header', 'footer'], true)) {
                    throw new \InvalidArgumentException('Zone de bloc invalide.');
                }
                if (!in_array($block['fit'] ?? 'contain', ['contain', 'cover'], true)) {
                    throw new \InvalidArgumentException('Mode de cadrage invalide.');
                }
                if (isset($block['crop']) && !preg_match('/^(?:100|\d{1,2})(?:\.\d+)?% (?:100|\d{1,2})(?:\.\d+)?%$/', $block['crop'])) {
                    throw new \InvalidArgumentException('Cadrage invalide.');
                }
                if ($block['type'] === 'bloc_image' && (!is_array($block['asset'] ?? null) || !preg_match('/^[a-f0-9]{64}\.(png|jpg|webp|svg)$/', $block['asset']['id'] ?? ''))) {
                    throw new \InvalidArgumentException('Image invalide.');
                }
            }
        }
    }

    private static function unique(array $element, array &$keys, array &$ids): void
    {
        $key = $element['key'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $key) || isset($keys[$key])) {
            throw new \InvalidArgumentException('Clé invalide ou dupliquée.');
        }
        $keys[$key] = true;
        if (isset($element['id'])) {
            if (!is_int($element['id']) || $element['id'] < 1 || isset($ids[$element['id']])) {
                throw new \InvalidArgumentException('Identifiant invalide ou dupliqué.');
            }
            $ids[$element['id']] = true;
        }
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }
    public function hash(): string
    {
        return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    public function dimensions(): array
    {
        $size = $this->data['pageSpec']['format'] === 'A3' ? [297.0, 420.0] : [210.0, 297.0];
        return $this->data['pageSpec']['orientation'] === 'landscape' ? array_reverse($size) : $size;
    }
    public function isBooklet(): bool
    {
        return $this->data['printProfile']['mode'] === 'booklet';
    }
}
