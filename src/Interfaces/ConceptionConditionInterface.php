<?php
/**
 * Created by PhpStorm.
 * User: Remi
 * Date: 29/05/2017
 * Time: 09:52
 */

namespace Imanaging\ConceptionDocumentBundle\Interfaces;

interface ConceptionConditionInterface
{
  public function getId(): int;

  public function setId(int $id);

  public function getTypeCondition(): string;

  public function setTypeCondition(string $typeCondition);

  public function getTypeComparaison(): string;

  public function setTypeComparaison(string $typeComparaison);

  public function getValeurComparaison(): string;

  public function setValeurComparaison(string $valeurComparaison);

  public function getPage();

  public function setPage($page);

  public function getBloc();

  public function setBloc($bloc);

  public static function getTypesConditions();

  public static function getTypesComparaisons();
}