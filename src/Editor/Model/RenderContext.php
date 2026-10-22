<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Model;

use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionDocumentInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionTemplateInterface;

final class RenderContext
{
    public function __construct(public readonly ConceptionDocumentInterface $item, public readonly \DateTimeImmutable $referenceDate, public readonly string $dataFingerprint = '', public readonly ?ConceptionTemplateInterface $template = null)
    {
    }
}
