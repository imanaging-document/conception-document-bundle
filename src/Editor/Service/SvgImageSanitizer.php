<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

/** A deliberately static SVG subset: no scripts, entities, references, animation or embedded HTML. */
final class SvgImageSanitizer
{
    public function sanitize(string $bytes): string
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) {
            throw new \DomainException('Les déclarations DTD et entités ne sont pas admises dans une image SVG.');
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $valid = $dom->loadXML($bytes, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$valid || !$dom->documentElement || $dom->documentElement->localName !== 'svg' || $dom->documentElement->namespaceURI !== 'http://www.w3.org/2000/svg') {
            throw new \DomainException('SVG invalide.');
        }
        $allowed = ['svg','g','path','rect','line','circle','ellipse','polyline','polygon','defs','linearGradient','radialGradient','stop','clipPath','mask','title','desc','text','tspan'];
        $attributes = ['id','viewBox','width','height','x','y','x1','x2','y1','y2','cx','cy','r','rx','ry','d','points','transform','fill','fill-rule','fill-opacity','stroke','stroke-width','stroke-linecap','stroke-linejoin','stroke-miterlimit','stroke-dasharray','stroke-dashoffset','stroke-opacity','opacity','offset','stop-color','stop-opacity','gradientUnits','gradientTransform','spreadMethod','fx','fy','clip-path','clip-rule','mask','maskUnits','preserveAspectRatio','font-family','font-size','font-weight','font-style','text-anchor','dominant-baseline','dx','dy','style'];
        $count = 0;
        $walk = function (\DOMNode $parent, int $depth) use (&$walk, &$count, $allowed, $attributes): void {
            if ($depth > 64 || ++$count > 10000) {
                throw new \DomainException('Image SVG trop complexe.');
            }
            foreach (iterator_to_array($parent->childNodes) as $node) {
                if (!$node instanceof \DOMElement) {
                    if (!$node instanceof \DOMText) {
                        $parent->removeChild($node);
                    } continue;
                }
                if ($node->namespaceURI !== 'http://www.w3.org/2000/svg' || !in_array($node->localName, $allowed, true)) {
                    $parent->removeChild($node);
                    continue;
                }
                foreach (iterator_to_array($node->attributes) as $attribute) {
                    $value = $attribute->value;
                    if ($attribute->namespaceURI || !in_array($attribute->name, $attributes, true) || preg_match('/[<>\\\\]|(?:javascript|data|https?|file):|@|expression|behavior/i', $value) || preg_match('/url\s*\(\s*(?!#[a-zA-Z0-9_-]+\s*\))/i', $value)) {
                        $node->removeAttributeNode($attribute);
                    } elseif ($attribute->name === 'style') {
                        $declarations = [];
                        foreach (explode(';', $value) as $declaration) {
                            [$name,$content] = array_pad(explode(':', $declaration, 2), 2, '');
                            if (in_array(trim($name), $attributes, true) && !in_array(trim($name), ['style','d','points','id'], true)) {
                                $declarations[] = trim($name).':'.trim($content);
                            }
                        }
                        $node->setAttribute('style', implode(';', $declarations));
                    }
                }
                $walk($node, $depth + 1);
            }
        };
        $walk($dom, 0);
        return $dom->saveXML($dom->documentElement);
    }
}
