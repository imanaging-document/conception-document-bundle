<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

use Imanaging\ConceptionDocumentBundle\Editor\Contract\DocumentRendererInterface;
use Imanaging\ConceptionDocumentBundle\Editor\Model\{DocumentDefinition, RenderContext};
use Imanaging\ConceptionDocumentBundle\Interfaces\{ConceptionDocumentInterface, ConceptionTemplateInterface};

/** Entry point shared by the editor and the historical generation API. */
final class DocumentGateway
{
    public function __construct(
        private readonly TemplateStateStore $store,
        private readonly LegacySnapshotAdapter $adapter,
        private readonly DocumentHtmlBuilder $html,
        private readonly DocumentRendererInterface $renderer,
        private readonly bool $enabled,
    ) {
    }

    public function definition(ConceptionTemplateInterface $template): DocumentDefinition
    {
        return $this->store->hasState($template) ? $this->store->current($template) : $this->adapter->snapshot($template);
    }

    public function supports(ConceptionTemplateInterface $template): bool
    {
        return $this->enabled || $this->store->hasState($template);
    }

    /** Capture once when starting a batch; keep the returned revision for every retry. */
    public function freeze(ConceptionTemplateInterface $template): array
    {
        $state = $this->store->state($template);
        $definition = $this->store->revision($state['current_revision'], $state['template_key']);
        return ['templateKey' => $state['template_key'], 'revision' => $state['current_revision'], 'hash' => $definition->hash()];
    }

    public function html(ConceptionDocumentInterface $item, ConceptionTemplateInterface $template): string
    {
        return $this->html->build($this->definition($template), new RenderContext($item, new \DateTimeImmutable()));
    }

    public function generateAtPath(string $path, ConceptionDocumentInterface $item, ConceptionTemplateInterface $template, ?array $frozen = null, ?\DateTimeImmutable $referenceDate = null): array
    {
        try {
            $definition = $frozen ? $this->store->revision($frozen['revision'], $frozen['templateKey']) : $this->definition($template);
            if ($frozen && !hash_equals($frozen['hash'], $definition->hash())) {
                throw new \DomainException('La version figée du modèle est incohérente.');
            }
            $result = $this->renderer->render($definition, new RenderContext($item, $referenceDate ?? new \DateTimeImmutable()));
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('Répertoire PDF inaccessible.');
            }
            $temporary = tempnam($directory, '.document-');
            try {
                if (!copy($result->printingPdf, $temporary) || !rename($temporary, $path)) {
                    throw new \RuntimeException('Publication du PDF impossible.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
                // Preview artefacts remain available to their viewer; these production artefacts have been copied.
                foreach (array_unique([$result->readingPdf, $result->printingPdf]) as $artifact) {
                    if (is_file($artifact)) {
                        unlink($artifact);
                    }
                }
            }
            return ['success' => true, 'filepath' => $path, 'logical_pages' => $result->logicalPages, 'physical_pages' => $result->physicalPages, 'definition_hash' => $definition->hash()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error_message' => $e->getMessage()];
        }
    }
}
