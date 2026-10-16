<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

final class ContentPolicy
{
    public function __construct(private readonly int $maxTextLength = 1000000)
    {
    }
    public function validateHtml(string $html): void
    {
        if (mb_strlen($html) > $this->maxTextLength) {
            throw new \DomainException('Le texte dépasse la limite autorisée.');
        }
        // Reject dangerous constructs; never silently strip legacy content.
        if (preg_match('~<(script|iframe|object|embed|svg|math|style|link|meta|img)\b|\son[a-z]+\s*=|(?:javascript|vbscript)\s*:|expression\s*\(|url\s*\(|@import|[\\\\]~i', $html)) {
            throw new \DomainException('Ce HTML contient des éléments non autorisés dans un bloc texte.');
        }
        // A parser-based second pass rejects encoded executable links and CSS.
        $dom = new \DOMDocument();
        $old = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        $allowed = ['html','body','section','article','figure','figcaption','header','footer','div','span','p','h1','h2','h3','h4','h5','h6','a','table','thead','tbody','tfoot','tr','td','th','ul','ol','li','b','i','u','s','strike','strong','em','br','sub','sup','mark','font','colgroup','col','code','pre','blockquote','hr'];
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (!in_array(strtolower($element->tagName), $allowed, true)) {
                throw new \DomainException('Élément HTML non autorisé : '.$element->tagName);
            }
            foreach ($element->attributes as $attr) {
                if (str_starts_with(strtolower($attr->name), 'on') || in_array($attr->name, ['srcdoc','src'], true)) {
                    throw new \DomainException('Attribut HTML interdit.');
                }
                if ($attr->name === 'href' && !preg_match('~^(https?://|mailto:|tel:|#|\{[a-zA-Z0-9_]+\}$)~i', trim($attr->value))) {
                    throw new \DomainException('Lien non autorisé.');
                }
                if ($attr->name === 'style') {
                    $this->styleString($attr->value);
                }
            }
        }
    }
    public function styles(array $styles): void
    {
        foreach ($styles as $property => $value) {
            if (!is_string($property) || !preg_match('/^(--bundle-[a-z-]+|[a-z][a-z-]*)$/', $property) || !is_scalar($value)) {
                throw new \DomainException('Style invalide.');
            }
            if (preg_match('~[<>{}\\\\]|url\s*\(|expression|javascript|@import|behavior~i', (string)$value)) {
                throw new \DomainException('Valeur de style non autorisée.');
            }
        }
    }
    public function fontResources(string $css): void
    {
        $remaining = preg_replace_callback('/@font-face\s*\{([^{}]*)\}/', function ($match) {
            foreach (preg_split('/;(?!base64,)/', $match[1]) as $part) {
                if (trim($part) === '') {
                    continue;
                }
                [$property,$value] = array_pad(explode(':', $part, 2), 2, '');
                $property = trim($property);
                $value = trim($value);
                $valid = match($property) {
                    'font-family' => (bool)preg_match('/^[\'\"][a-zA-Z0-9 ,_-]+[\'\"]$/', $value),
                    'src' => (bool)preg_match('~^url\([\'\"]?data:font/(?:woff2?|ttf|otf);base64,[A-Za-z0-9+/=]+[\'\"]?\)(?:\s+format\([\'\"](?:woff2?|truetype|opentype)[\'\"]\))?$~', $value),
                    'font-weight' => (bool)preg_match('/^(?:[1-9]00|normal|bold)$/', $value),
                    'font-style' => in_array($value, ['normal','italic','oblique'], true),
                    'font-display' => in_array($value, ['block','swap','auto'], true),
                    default => false,
                };
                if (!$valid) {
                    throw new \DomainException('Ressource de police non autonome ou invalide.');
                }
            }
            return '';
        }, $css);
        if (trim($remaining) !== '') {
            throw new \DomainException('Styles de police non autorisés.');
        }
    }
    private function styleString(string $style): void
    {
        foreach (explode(';', $style) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }
            [$key,$value] = array_pad(explode(':', $declaration, 2), 2, '');
            $this->styles([trim($key) => trim($value)]);
        }
    }
}
