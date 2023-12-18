<?php

namespace Imanaging\ConceptionDocumentBundle;

use Doctrine\ORM\EntityManagerInterface;

class ConceptionDocument
{
  private EntityManagerInterface $em;
  private string $outputPath;

  /**
   * ConceptionDocument constructor.
   * @param EntityManagerInterface $em
   * @param $outputPath
   */
  public function __construct(EntityManagerInterface $em, $outputPath)
  {
    $this->em = $em;
    $this->outputPath = $outputPath;
  }

  public function test()
  {

  }
}