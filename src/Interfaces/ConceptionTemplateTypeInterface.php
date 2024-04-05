<?php
/**
 * Created by PhpStorm.
 * User: Rémi
 * Date: 21/12/2023
 * Time: 15:43
 */

namespace Imanaging\ConceptionDocumentBundle\Interfaces;

interface ConceptionTemplateTypeInterface
{
  public function getId(): int;

  public function setId(int $id);

  public function getCode(): string;

  public function setCode(string $code);

  public function getLibelle(): string;

  public function setLibelle(string $libelle);

  public function getTargetEntity(): string;

  public function setTargetEntity(string $targetEntity);

  public function getBlocsType();
}