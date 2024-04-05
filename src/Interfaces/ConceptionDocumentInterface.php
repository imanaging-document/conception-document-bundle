<?php
/**
 * Created by PhpStorm.
 * User: Remi
 * Date: 02/04/2024
 * Time: 16:10
 */

namespace Imanaging\ConceptionDocumentBundle\Interfaces;

interface ConceptionDocumentInterface
{
  public function getId(): int;

  public function getConceptionDocumentPdfFilename(bool $sandBox=false): string;
}