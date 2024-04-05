<?php
/**
 * Created by PhpStorm.
 * User: Remi
 * Date: 29/05/2017
 * Time: 09:52
 */

namespace Imanaging\ConceptionDocumentBundle\Interfaces;

interface ConceptionBlocStyleInterface
{
  public function getId(): int;

  public function setId(int $id);

  public function getCode(): string;

  public function setCode(string $code);

  public function getNom(): string;

  public function setNom(string $nom);

  public function getStyle(): string;

  public function setStyle(string $style);

  public function getBloc(): ConceptionBlocInterface;

  public function setBloc(ConceptionBlocInterface $bloc);

  public function getDecodedStyle();

  public function __construct(ConceptionBlocInterface $bloc, $code, $nom, $style = '{}');
}