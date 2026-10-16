<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

use Imanaging\ConceptionDocumentBundle\Editor\Contract\BlockTypeAdapterInterface;

final class BlockAdapterRegistry
{
    private array $adapters;
    public function __construct(iterable $adapters = [])
    {
        $this->adapters = [...$adapters];
    }
    public function find(string $type): ?BlockTypeAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($type)) {
                return $adapter;
            }
        }
        return null;
    }
}
