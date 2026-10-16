<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Contract;

use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionTemplateInterface;

interface BlockPresetProviderInterface
{
    /** @return array<array{label:string,html:string}> */
    public function presets(ConceptionTemplateInterface $template): array;
}
