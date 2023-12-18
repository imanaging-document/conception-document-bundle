<?php

namespace Imanaging\ConceptionDocumentBundle;

use Imanaging\ConceptionDocumentBundle\DependencyInjection\ImanagingConceptionDocumentExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class ImanagingConceptionDocumentBundle extends Bundle
{
  public function getContainerExtension() : ?ImanagingConceptionDocumentExtension
  {
    if (null === $this->extension) {
      $this->extension = new ImanagingConceptionDocumentExtension();
    }
    return $this->extension;
  }
}