<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Model;

final class RenderResult
{
    public function __construct(public readonly string $readingPdf, public readonly string $printingPdf, public readonly int $logicalPages, public readonly int $physicalPages, public readonly string $fingerprint, public readonly array $diagnostics = [])
    {
    }
}
