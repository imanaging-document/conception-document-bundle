<?php

namespace Imanaging\ConceptionDocumentBundle\Interfaces;

/** Optional template-aware personalization; existing consumers keep the historical interface. */
interface ConceptionTemplatePersonnalisationServiceInterface extends ConceptionPersonnalisationServiceInterface
{
  public function personnalizeTextForTemplate(
    string $text,
    ConceptionDocumentInterface $document,
    ConceptionTemplateInterface $template
  ): string;
}
