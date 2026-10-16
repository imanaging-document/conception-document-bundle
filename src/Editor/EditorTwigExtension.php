<?php

namespace Imanaging\ConceptionDocumentBundle\Editor;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class EditorTwigExtension extends AbstractExtension
{
    public function __construct(private readonly bool $enabled)
    {
    }
    public function getFunctions(): array
    {
        return [new TwigFunction('conceptionEditorEnabled', fn () => $this->enabled)];
    }
}
