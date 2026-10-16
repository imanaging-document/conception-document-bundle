<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Contract;

use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionTemplateInterface;

interface PersonalizationFieldProviderInterface
{
    /** @return array<array{token:string,label:string}> */
    public function fields(ConceptionTemplateInterface $template): array;
}
