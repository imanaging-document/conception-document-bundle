<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Contract;

use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocInterface;
use Imanaging\ConceptionDocumentBundle\Editor\Model\RenderContext;

/** Host adapters are tagged conception_document.block_adapter through autoconfiguration. */
interface BlockTypeAdapterInterface
{
    public function supports(string $type): bool;
    /** Additional portable data, stored in the block's extension property. */
    public function snapshot(ConceptionBlocInterface $block): array;
    public function validate(array $data): void;
    public function apply(ConceptionBlocInterface $block, array $data): void;
    public function render(array $block, RenderContext $context): string;
}
