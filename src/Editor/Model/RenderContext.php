<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Model;

use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionDocumentInterface;

final class RenderContext
{
    public function __construct(public readonly ConceptionDocumentInterface $item, public readonly \DateTimeImmutable $referenceDate, public readonly string $dataFingerprint = '')
    {
    }
}
