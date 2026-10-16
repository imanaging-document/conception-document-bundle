<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Contract;

use Imanaging\ConceptionDocumentBundle\Editor\Model\{DocumentDefinition,RenderContext,RenderResult};

interface DocumentRendererInterface
{
    public function render(DocumentDefinition $definition, RenderContext $context): RenderResult;
}
