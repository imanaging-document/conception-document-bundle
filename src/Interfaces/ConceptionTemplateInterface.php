<?php
/**
 * Created by PhpStorm.
 * User: Remi
 * Date: 29/05/2017
 * Time: 09:52
 */

namespace Imanaging\ConceptionDocumentBundle\Interfaces;

interface ConceptionTemplateInterface
{
  public function getId(): int;

  public function setId(int $id);

  public function getLibelle(): string;

  public function setLibelle(string $libelle);

  public function getType(): ConceptionTemplateTypeInterface;

  public function setType(ConceptionTemplateTypeInterface $type);

  public function isActif(): bool;

  public function setActif(bool $actif);

  public function getPages();

  public function setPages($pages);
}