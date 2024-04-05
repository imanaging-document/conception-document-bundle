<?php
/**
 * Created by PhpStorm.
 * User: Remi
 * Date: 29/05/2017
 * Time: 09:52
 */

namespace Imanaging\ConceptionDocumentBundle\Interfaces;

interface ConceptionBlocTypeInterface
{
  public function getId(): int;

  public function setId(int $id);

  public function getCode(): string;

  public function setCode(string $code);

  public function getLibelle(): string;

  public function setLibelle(string $libelle);

  public function getEntity(): string;

  public function setEntity(string $entity);
}