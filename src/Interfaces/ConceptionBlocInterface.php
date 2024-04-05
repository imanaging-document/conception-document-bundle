<?php
/**
 * Created by PhpStorm.
 * User: Remi
 * Date: 29/05/2017
 * Time: 09:52
 */

namespace Imanaging\ConceptionDocumentBundle\Interfaces;

interface ConceptionBlocInterface
{
  public function getId(): int;

  public function setId(int $id);

  public function getLibelle(): string;

  public function setLibelle(string $libelle);

  public function getOrdre(): int;

  public function setOrdre(int $ordre);

  public function getType(): ConceptionBlocTypeInterface;

  public function setType(ConceptionBlocTypeInterface $type);

  public function getPage(): ConceptionPageInterface;

  public function setPage(ConceptionPageInterface $page);
  
  public function getConditions();
}