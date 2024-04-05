<?php
/**
 * Created by PhpStorm.
 * User: Remi
 * Date: 29/05/2017
 * Time: 09:52
 */

namespace Imanaging\ConceptionDocumentBundle\Interfaces;

interface ConceptionPageInterface
{
  public function getId(): int;

  public function setId(int $id);

  public function getLibelle(): string;

  public function setLibelle(string $libelle);

  public function getOrdre(): int;

  public function setOrdre(int $ordre);

  public function getTemplate(): ConceptionTemplateInterface;

  public function setTemplate(ConceptionTemplateInterface $template);

  public function getBlocs();

  public function setBlocs($blocs);
  
  public function getConditions();
}