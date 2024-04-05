<?php

namespace Imanaging\ConceptionDocumentBundle;

use Doctrine\ORM\EntityManagerInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionDocumentInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionPageInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionPersonnalisationServiceInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionTemplateInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionTemplateTypeInterface;
use mikehaertl\wkhtmlto\Pdf;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class ConceptionDocument
{
  private EntityManagerInterface $em;
  private string $outputPath;
  private string $wkhtmltopdfPath;
  private Environment $twig;
  private ConceptionPersonnalisationServiceInterface $conceptionPersonnalisationService;

  /**
   * ConceptionDocument constructor.
   * @param EntityManagerInterface $em
   * @param $outputPath
   */
  public function __construct(EntityManagerInterface $em, $outputPath, $wkhtmltopdfPath, Environment $twig,
                              ConceptionPersonnalisationServiceInterface $conceptionPersonnalisationService)
  {
    $this->em = $em;
    $this->outputPath = $outputPath;
    $this->wkhtmltopdfPath = $wkhtmltopdfPath;
    $this->twig = $twig;
    $this->conceptionPersonnalisationService = $conceptionPersonnalisationService;
  }

  public function showSearchEntityForm(ConceptionTemplateTypeInterface $typeTemplate): Response
  {
    return new Response($this->conceptionPersonnalisationService->showSearchEntityForm($typeTemplate));
  }

  /**
   * @param $entity
   * @param ConceptionTemplateInterface $conceptionTemplate
   * @param bool $sandBox
   * @return array
   * @throws LoaderError
   * @throws RuntimeError
   * @throws SyntaxError
   */
  public function genererPdf(ConceptionDocumentInterface $conceptionDocument, ConceptionTemplateInterface $conceptionTemplate, bool $sandBox=false){
    if (!is_dir($this->outputPath)){
      mkdir($this->outputPath, 0755, true);
    }

    $filePath = $this->outputPath.'/'.$conceptionDocument->getConceptionDocumentPdfFilename($sandBox);
    if (!file_exists($filePath)){
      $options = [
        'no-outline',         // Make Chrome not complain
        'margin-top'    => 0,
        'margin-right'  => 0,
        'margin-bottom' => 0,
        'margin-left'   => 0,
        'page-size'   => 'A4',
        'dpi'   => 96,
        'disable-smart-shrinking',
        'zoom' => 1,
        'binary' => $this->wkhtmltopdfPath,
        'ignoreWarnings' => true,
        'commandOptions' => [
          'useExec' => true,      // Can help on Windows systems
          'procEnv' => [
            // Check the output of 'locale -a' on your system to find supported languages
            'LANG' => 'en_US.utf-8',
          ]
        ],
      ];
      $pdf = new Pdf($options);
      foreach ($conceptionTemplate->getPages() as $page){
        if ($page instanceof ConceptionPageInterface){
          if ($this->conceptionPersonnalisationService->canShowElementConceptionTemplate($page->getConditions(), $conceptionDocument)){
            $html = $this->twig->render('@ImanagingConceptionDocument/ConceptionDocument/document.html.twig', [
              'page' => $page,
              'conception_document' => $conceptionDocument,
              'entity_id' => $conceptionDocument->getId(),
              'preshow' => false]);
            $pdf->addPage($html);
          }
        }
      }

      if (!$pdf->saveAs($filePath)) {
        return ['success' => false, 'error_message' => 'Impossible de générer le PDF : '.$pdf->getError()];
      } else {
        if (file_exists($filePath)){
          return ['success' => true, 'filepath' => $filePath];
        } else {
          return ['success' => false, 'error_message' => 'PDF introuvable '.$filePath];
        }
      }
    } else {
      return ['success' => true, 'filepath' => $filePath];
    }
  }
}