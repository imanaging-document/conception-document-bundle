<?php
/**
 * Created by PhpStorm.
 * User: Remi
 * Date: 02/04/2024
 * Time: 16:10
 */

namespace Imanaging\ConceptionDocumentBundle\Interfaces;
use Imanaging\ConceptionDocumentBundle\ConceptionDocument;

interface ConceptionPersonnalisationServiceInterface
{
  public function canShowElementConceptionTemplate($conditions, ConceptionDocumentInterface $conceptionDocument): bool;

  public function renderBloc(ConceptionBlocInterface $bloc, ConceptionDocumentInterface $conceptionDocument): string;

  public function personnalizeText(string $text, ConceptionDocumentInterface $conceptionDocument): string;

  public function showSearchEntityForm(ConceptionTemplateTypeInterface $templateType): mixed;
}