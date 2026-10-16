<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

use Imanaging\ConceptionDocumentBundle\Editor\Model\{DocumentDefinition,RenderContext};
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionPersonnalisationServiceInterface;
use Imanaging\ConceptionDocumentBundle\Service\{ConceptionFontService,ConceptionQrCodeService};

final class DocumentHtmlBuilder
{
    public function __construct(private readonly LegacySnapshotAdapter $adapter, private readonly ConceptionPersonnalisationServiceInterface $personalization, private readonly AssetStore $assets, private readonly ConceptionFontService $fonts, private readonly ConceptionQrCodeService $qr, private readonly BlockAdapterRegistry $blockAdapters, private readonly string $editorAssetsPath)
    {
    }
    /** Interactive renders keep overflow diagnostics; editing always reads the unpersonalized definition. */
    public function build(DocumentDefinition $definition, ?RenderContext $context = null, bool $source = false): string
    {
        [$w,$h] = $definition->dimensions();
        $pages = '';
        $data = $definition->data;
        $flow = $data['mode'] === 'flow';
        $m = array_replace(['top' => 15,'right' => 15,'bottom' => 15,'left' => 15], $data['pageSpec']['margins'] ?? []);
        foreach ($data['pages'] as $page) {
            if ($context && !$this->personalization->canShowElementConceptionTemplate($this->adapter->hydrateConditions($page['conditions'] ?? []), $context->item)) {
                continue;
            }
            $blocks = '';
            $header = '';
            $footer = '';
            foreach ($page['blocks'] as $block) {
                if ($context && !$this->personalization->canShowElementConceptionTemplate($this->adapter->hydrateConditions($block['conditions'] ?? []), $context->item)) {
                    continue;
                }
                $fragment = $this->block($block, $context, $source, $flow);
                if ($flow && ($block['zone'] ?? 'body') === 'header') {
                    $header .= $fragment;
                } elseif ($flow && ($block['zone'] ?? 'body') === 'footer') {
                    $footer .= $fragment;
                } else {
                    $blocks .= $fragment;
                }
            }
            if ($flow) {
                $blocks = ($header ? '<div class="repeated-header">'.$header.'</div>' : '').($footer ? '<div class="repeated-footer">'.$footer.'</div>' : '').'<div class="flow-body">'.$blocks.'</div>';
            }
            $pages .= '<section class="document-sheet'.($flow ? ' flow-source' : '').'" data-page-key="'.$this->escape($page['key']).'">'.$blocks.'</section>';
        }
        if ($pages === '') {
            throw new \DomainException('Aucune page visible pour cet item de personnalisation.');
        }
        $fontCss = $data['fontResources'] ?? $this->fontCss();
        $css = "@page{size:{$w}mm {$h}mm;margin:0}html,body{margin:0;padding:0;background:#fff;color:#111;font:12pt Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}*,*:before,*:after{box-sizing:border-box}p{margin:0 0 .6em}table{border-collapse:collapse;color:inherit;font:inherit}td,th{padding:2px;vertical-align:top}ul,ol{margin:0 0 .6em;padding-left:1.5em}img{max-width:none}a{color:#155ac0}h1,h2,h3,h4{margin:0 0 .5em;break-after:avoid}thead{display:table-header-group}tr{break-inside:avoid}p{orphans:2;widows:2}.document-sheet{position:relative;width:{$w}mm;height:{$h}mm;overflow:hidden;background:white;break-after:page}.document-sheet:last-of-type{break-after:auto}.editor-block{margin:0;min-height:1px}.legacy-block{line-height:1.5}.legacy-block p{margin-top:0;margin-bottom:1rem}.legacy-block ul,.legacy-block ol{margin-top:0;margin-bottom:1rem;padding-left:2rem}.field-chip{background:#e6edff;color:#204d99;border-radius:2px;padding:0 2px}[data-page-break]{break-before:page}mark{background:#fff176}.ProseMirror{outline:2px solid #20a270;min-height:1em;white-space:pre-wrap;overflow-wrap:break-word}.variable-chip{background:#e3ecff;color:#2452a5;border-radius:3px;padding:0 2px}.selectedCell:after{position:absolute;inset:0;background:#245bd622;pointer-events:none;content:''}.column-resize-handle{position:absolute;right:-2px;top:0;bottom:0;width:4px;background:#245bd6}td,th{position:relative}";
        $runtime = '';
        if ($flow) {
            $css .= "@page{margin:{$m['top']}mm {$m['right']}mm {$m['bottom']}mm {$m['left']}mm;@top-center{content:element(doc-header)}@bottom-center{content:element(doc-footer)}}.flow-source{width:auto;height:auto;overflow:visible;break-after:page}.flow-source:last-of-type{break-after:auto}.flow-body>.editor-block,.repeated-header>.editor-block,.repeated-footer>.editor-block{position:static!important;left:auto!important;top:auto!important;width:100%!important;height:auto!important}.repeated-header{position:running(doc-header)}.repeated-footer{position:running(doc-footer)}.flow-body>.editor-block[data-block-type=bloc_image]{width:var(--flow-image-width,100%)!important;height:var(--flow-image-height,30mm)!important}.repeated-header>.editor-block[data-block-type=bloc_image],.repeated-footer>.editor-block[data-block-type=bloc_image]{width:var(--flow-image-width,100%)!important;height:var(--flow-image-height,10mm)!important}.page-number:after{content:counter(page)}.page-total:after{content:counter(pages)}.pagedjs_pages{margin:0!important;padding:0!important}.pagedjs_page{margin:0!important;box-shadow:none!important;break-after:page}.pagedjs_page:last-child{break-after:auto}";
            $paged = rtrim($this->editorAssetsPath, '/').'/paged.js';
            if (!is_file($paged)) {
                throw new \RuntimeException('Paged.js absent : exécutez le build Encore de l’application et vérifiez editor_assets_path.');
            }
            $runtime = '<script>window.PagedConfig={auto:false};</script><script>'.file_get_contents($paged).'</script>';
        }
        $nonce = base64_encode(random_bytes(24));
        $runtime = str_replace('<script>', '<script nonce="'.$nonce.'">', $runtime);
        $ready = ($flow ? 'for(const [selector,limit] of [[".repeated-header",'.($m['top'] * 96 / 25.4).'],[".repeated-footer",'.($m['bottom'] * 96 / 25.4).']]){for(const el of document.querySelectorAll(selector)){if(el.getBoundingClientRect().height>limit+1)throw new Error("En-tête ou pied plus haut que la marge réservée.");}}const usefulHeight='.($h - $m['top'] - $m['bottom']).'*96/25.4;for(const el of document.querySelectorAll(".flow-body>.editor-block img,.flow-body tr,.repeated-header,.repeated-footer")){if(el.getBoundingClientRect().height>usefulHeight)throw new Error("Un élément dépasse la hauteur utile d’une page.");}await Promise.race([window.PagedPolyfill.preview(),new Promise((_,reject)=>setTimeout(()=>reject(new Error("Pagination trop longue : vérifiez les éléments plus hauts qu’une page.")),20000))]);' : '').'window.__documentReady=true;if(parent!==window)parent.postMessage({type:"conception-document-ready"},"*");';
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src &apos;none&apos;; script-src &apos;nonce-'.$nonce.'&apos;; style-src &apos;unsafe-inline&apos;; img-src data:; font-src data:; base-uri &apos;none&apos;; form-action &apos;none&apos;"><style>'.$fontCss.$css.'</style></head><body>'.$pages.$runtime.'<script nonce="'.$nonce.'">(async()=>{try{await document.fonts.ready;await Promise.all(Array.from(document.images).map(i=>i.decode()));'.($flow ? '' : $this->freeLayoutValidation($source)).$ready.'}catch(e){window.__documentError=e instanceof Error?e.message:String(e);if(parent!==window)parent.postMessage({type:"conception-document-error",message:window.__documentError},"*");else throw new Error("Document invalide : "+window.__documentError);}})();</script></body></html>';
    }
    private function freeLayoutValidation(bool $interactive): string
    {
        $failure = $interactive ? 'el.dataset.overflow="true";' : 'throw new Error("Débordement du bloc « "+(el.dataset.blockLabel||el.dataset.blockKey)+" » : vérifiez sa position, ses dimensions ou son contenu.");';
        return <<<'JS'
for (const el of document.querySelectorAll(".editor-block")) {
    const page = el.closest(".document-sheet").getBoundingClientRect();
    const outside = r => r.width > 0 && r.height > 0 &&
        (r.left < page.left - 1 || r.top < page.top - 1 || r.right > page.right + 1 || r.bottom > page.bottom + 1);
    const nodes = [el, ...el.querySelectorAll("*")];
    const rectangles = [];
    for (const node of nodes) {
        const css = getComputedStyle(node);
        const background = css.backgroundColor !== "rgba(0, 0, 0, 0)" && css.backgroundColor !== "transparent";
        const border = ["Top", "Right", "Bottom", "Left"].some(side => parseFloat(css["border" + side + "Width"]) > 0);
        if (node.tagName === "IMG" || background || border || css.backgroundImage !== "none") rectangles.push(...node.getClientRects());
    }
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        const node = walker.currentNode;
        if (!node.textContent.trim() || ["STYLE", "SCRIPT"].includes(node.parentElement.tagName)) continue;
        const range = document.createRange();
        range.selectNodeContents(node);
        rectangles.push(...range.getClientRects());
    }
    // Empty margins and transparent frames can extend beyond the page without clipping any ink.
    const outsidePage = rectangles.some(outside);
    const clipsContent = [el, ...el.querySelectorAll("*")].some(node => {
        const css = getComputedStyle(node);
        return (["hidden", "clip", "auto", "scroll"].includes(css.overflowY) && node.scrollHeight > node.clientHeight + 2) ||
            (["hidden", "clip", "auto", "scroll"].includes(css.overflowX) && node.scrollWidth > node.clientWidth + 2);
    });
    if (outsidePage || clipsContent) {
JS
        .$failure.
        <<<'JS'
    } else if (outside(el.getBoundingClientRect()) || el.scrollHeight > el.clientHeight + 2 || el.scrollWidth > el.clientWidth + 2) {
        // Legacy blocks allow visible text outside their nominal frame. Preserve it and report it.
        el.dataset.overflow = "visible";
    }
}
JS;
    }
    private function block(array $b, ?RenderContext $context, bool $source, bool $flow): string
    {
        $blockStyle = $b['style'];
        $imageAutoWidth = $b['type'] === 'bloc_image' && ($blockStyle['width'] ?? '') === 'auto';
        $imageAutoHeight = $b['type'] === 'bloc_image' && ($blockStyle['height'] ?? '') === 'auto';
        if ($flow && $b['type'] === 'bloc_image') {
            $blockStyle['--flow-image-height'] = $blockStyle['height'] ?? '30mm';
            if ($imageAutoWidth || $imageAutoHeight) {
                $blockStyle['--flow-image-width'] = $imageAutoWidth ? 'max-content' : ($blockStyle['width'] ?? '100%');
            }
        }
        if ($imageAutoWidth) {
            $blockStyle['width'] = 'max-content';
        }
        $style = $this->style($blockStyle);
        $key = $this->escape($b['key']);
        $type = $b['type'];
        $content = '';
        $tag = 'div';
        if ($context && ($custom = $this->blockAdapters->find($type))) {
            $content = $custom->render($b, $context);
        } elseif ($type === 'bloc_image') {
            $imageWidth = $imageAutoWidth ? 'auto' : '100%';
            $imageHeight = $imageAutoHeight ? 'auto' : ($imageAutoWidth ? ($b['style']['height'] ?? 'auto') : '100%');
            $content = '<img alt="'.$this->escape($b['label']).'" src="'.$this->assets->dataUri($b['asset']).'" style="display:block;width:'.$imageWidth.';height:'.$this->escape($imageHeight).';object-fit:'.($b['fit'] ?? $b['style']['object-fit'] ?? 'contain').';object-position:'.$this->escape($b['crop'] ?? $b['style']['object-position'] ?? '50% 50%').'">';
        } elseif ($type === 'bloc_qrcode' || !empty($b['style']['--bundle-qrcode'])) {
            $text = $context ? $this->personalization->personnalizeText($b['text'], $context->item) : $b['text'];
            $uri = $this->qr->getDataUri($text);
            $content = '<img alt="QR code" src="'.$this->escape($uri ?? '').'" style="display:block;width:100%;height:100%">';
        } elseif ($type === 'bloc_texte') {
            if (in_array(strtolower((string)($b['style']['--bundle-checkbox-native'] ?? '')), ['1','true','yes','on'], true)) {
                $content = !empty($b['style']['--bundle-checkbox-checked']) ? 'X' : '';
            } else {
                $text = $context ? $this->personalization->personnalizeText($b['text'], $context->item) : $b['text'];
                // Match legacy text rendering: white-space controls line breaks, never add a second break.
                $content = ($b['raw'] ?? true) ? $text : $this->escape($text);
                if (!($b['raw'] ?? true) && !empty($b['styles'])) {
                    // The legacy Twig wrapper contributes one leading/trailing line in pre-line text.
                    $content = "\n".$content."\n";
                }
                if ($flow) {
                    $content = strtr($content, ['{page_number}' => '<span class="page-number"></span>','{page_total}' => '<span class="page-total"></span>']);
                }
            }
        } elseif ($type !== 'formes_predefinies') {
            if ($context) {
                $native = $b;
                // The outer block owns appearance and geometry; the native root only carries its children.
                $native['style'] = ['position' => 'relative','left' => '0','top' => '0','width' => '100%','height' => 'auto'];
                $bloc = $this->adapter->hydrateBlock($native);
                $content = $this->personalization->renderBloc($bloc, $context->item);
                if (method_exists($bloc, 'getCss')) {
                    // Native hosts define address rows, anomaly lists, etc. through their scoped CSS.
                    $content = '<style>'.str_replace('<', '\\3C ', (string)$bloc->getCss()).'</style>'.$content;
                }
            } else {
                $content = '<span style="font-size:10pt;color:#667">'.$this->escape($b['label']).' · bloc personnalisé</span>';
            }
        }
        $zone = in_array($b['zone'] ?? '', ['header','footer'], true) ? ' zone-'.$b['zone'] : '';
        $legacy = !empty($b['styles']) ? ' legacy-block' : '';
        return '<'.$tag.' class="editor-block'.$zone.$legacy.'" data-block-key="'.$key.'" data-block-type="'.$this->escape($type).'" data-block-label="'.$this->escape($b['label']).'" style="'.$style.'">'.$content.'</'.$tag.'>';
    }
    public function fontFamilies(): array
    {
        return array_values(array_map(fn ($family) => $family['manifest']['family'] ?? $family['family'] ?? $family['id'], $this->fonts->listFamilies()));
    }
    public function fontCss(): string
    {
        $css = '';
        $dir = rtrim($this->editorAssetsPath, '/').'/fonts';
        if (!is_dir($dir)) {
            throw new \RuntimeException('Polices documentaires absentes : exécutez le build Encore de l’application et vérifiez editor_assets_path.');
        }
        foreach (['Arial' => 'arimo','Times New Roman' => 'tinos','Georgia' => 'gelasio','Montserrat' => 'montserrat','Libre Barcode 128' => 'libre-barcode-128'] as $family => $file) {
            foreach ([400,700] as $weight) {
                foreach (['normal','italic'] as $variant) {
                    $path = $dir.'/'.$file.'-latin-'.$weight.'-'.$variant.'.woff2';
                    if (is_file($path)) {
                        $css .= "@font-face{font-family:'$family';src:url(data:font/woff2;base64,".base64_encode(file_get_contents($path)).");font-weight:$weight;font-style:$variant;font-display:block}";
                    }
                }
            }
        }
        return $css.(string)preg_replace_callback("#url\\(['\"]?[^'\")]*?/conception-document/fonts/([^/]+)/files/([^'\")]+)['\"]?\\)#", function ($m) {
            $path = $this->fonts->getFontFilePath(rawurldecode($m[1]), rawurldecode($m[2]));
            if (!$path) {
                throw new \DomainException('Fichier de police introuvable.');
            }return "url('data:font/woff2;base64,".base64_encode(file_get_contents($path))."')";
        }, $this->fonts->getFontFaceCss());
    }
    private function style(array $style): string
    {
        $out = '';
        foreach ($style as $k => $v) {
            $out .= $k.':'.$v.';';
        }return $this->escape($out);
    }
    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
