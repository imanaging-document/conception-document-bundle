<?php

namespace Imanaging\ConceptionDocumentBundle;

use Doctrine\ORM\EntityManagerInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocTypeInterface;
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
  private const DEFAULT_DOCUMENT_FORMAT = ConceptionTemplateInterface::DOCUMENT_FORMAT_A4;
  private const AVAILABLE_DOCUMENT_FORMATS = [
    ConceptionTemplateInterface::DOCUMENT_FORMAT_A4,
    ConceptionTemplateInterface::DOCUMENT_FORMAT_A3,
  ];

  private EntityManagerInterface $em;
  private string $outputPath;
  private string $wkhtmltopdfPath;
  private Environment $twig;
  private ConceptionPersonnalisationServiceInterface $conceptionPersonnalisationService;

  /**
   * ConceptionDocument constructor.
   * @param EntityManagerInterface $em
   * @param $outputPath
   * @param $wkhtmltopdfPath
   * @param Environment $twig
   * @param ConceptionPersonnalisationServiceInterface $conceptionPersonnalisationService
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

  public function getRandomEntityId(ConceptionTemplateTypeInterface $typeTemplate): ?int
  {
    return $this->conceptionPersonnalisationService->getRandomEntityId($typeTemplate);
  }

  public function showAddTypeBlocPartial(string $codeTypeBloc): Response
  {
    return new Response($this->conceptionPersonnalisationService->showAddTypeBlocPartial($codeTypeBloc));
  }
  
  public function addBlocCustom(ConceptionBlocTypeInterface $typeBloc, ConceptionPageInterface $page, array $params): bool
  {
    return $this->conceptionPersonnalisationService->addBlocCustom($typeBloc, $page, $params);
  }

  public function getCustomConceptionDocument(ConceptionTemplateTypeInterface $conceptionTemplateType, int $entityId): mixed
  {
    return $this->conceptionPersonnalisationService->getCustomConceptionDocument($conceptionTemplateType, $entityId);
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
    $filePath = $this->outputPath.'/'.$conceptionDocument->getConceptionDocumentPdfFilename($sandBox);

    $dir = dirname($filePath);
    if (!is_dir($dir)){
      mkdir($dir, 0755, true);
    }

    if (file_exists($filePath)){
      return ['success' => true, 'filepath' => $filePath];
    }

    return $this->generatePdf($filePath, $conceptionDocument, $conceptionTemplate);
  }

  /**
   * Génère le HTML concaténé de toutes les pages visibles d'une conception.
   * Le résultat est prêt à être transmis à un moteur HTML->PDF (ex: Gotenberg).
   *
   * @throws LoaderError
   * @throws RuntimeError
   * @throws SyntaxError
   */
  public function getConceptionHtml(
    ConceptionDocumentInterface $conceptionDocument,
    ConceptionTemplateInterface $conceptionTemplate
  ): string {
    $documentFormat = $this->getDocumentFormat($conceptionTemplate);
    [$pageWidthMm, $pageHeightMm] = $this->getDocumentDimensionsMm($documentFormat);
    $visiblePages = $this->getVisiblePages($conceptionTemplate, $conceptionDocument);

    $pagesHtml = '';
    foreach ($visiblePages as $page) {
      $pageContent = $this->twig->render('@ImanagingConceptionDocument/ConceptionDocument/document-page-content.html.twig', [
        'page' => $page,
        'conception_document' => $conceptionDocument,
        'entity_id' => $conceptionDocument->getId(),
        'preshow' => false,
      ]);
      $pagesHtml .= '<div class="page">'.$pageContent.'</div>';
    }

    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8" /></head><body>'.
      $pagesHtml.
      '<style>'.
      '@import url(\'https://fonts.googleapis.com/css2?family=Montserrat:wght@100;200;300;400;500;600;700;800&display=swap\');'.
      '@import url(\'https://fonts.googleapis.com/css2?family=Libre+Barcode+128&display=swap\');'.
      'html, body { margin: 0; padding: 0; background: #fff; }'.
      '.page { position: relative; width: '.$pageWidthMm.'mm; height: '.$pageHeightMm.'mm; overflow: hidden; page-break-after: always; }'.
      '.page:last-child { page-break-after: auto; }'.
      '.document-sheet { position: relative; width: 100%; height: 100%; overflow: hidden; box-sizing: border-box; background: #fff; font-family: Arial, Helvetica, sans-serif; }'.
      '</style></body></html>';
  }

  protected function generatePdf(
    string $filePath,
    ConceptionDocumentInterface $conceptionDocument,
    ConceptionTemplateInterface $conceptionTemplate
  ): array {
    return $this->generatePdfWithWkhtml($filePath, $conceptionDocument, $conceptionTemplate);
  }

  private function generatePdfWithWkhtml(
    string $filePath,
    ConceptionDocumentInterface $conceptionDocument,
    ConceptionTemplateInterface $conceptionTemplate
  ): array {
    $documentFormat = $this->getDocumentFormat($conceptionTemplate);
    $documentOrientation = $this->getDocumentOrientation($documentFormat);

    $options = [
      'no-outline',
      'margin-top'    => 0,
      'margin-right'  => 0,
      'margin-bottom' => 0,
      'margin-left'   => 0,
      'page-size'   => $documentFormat,
      'orientation' => $documentOrientation,
      'dpi'   => 96,
      'disable-smart-shrinking',
      'zoom' => 1,
      'binary' => $this->wkhtmltopdfPath,
      'ignoreWarnings' => true,
      'commandOptions' => [
        'useExec' => true,
        'procEnv' => [
          'LANG' => 'en_US.utf-8',
        ]
      ],
    ];
    $pdf = new Pdf($options);
    $visiblePages = $this->getVisiblePages($conceptionTemplate, $conceptionDocument);
    foreach ($visiblePages as $page){
      $html = $this->twig->render('@ImanagingConceptionDocument/ConceptionDocument/document.html.twig', [
        'page' => $page,
        'conception_document' => $conceptionDocument,
        'entity_id' => $conceptionDocument->getId(),
        'page_format' => $documentFormat,
        'preshow' => false
      ]);
      $pdf->addPage($html);
    }

    if (!$pdf->saveAs($filePath)) {
      return ['success' => false, 'error_message' => 'Impossible de générer le PDF (wkhtml) : '.$pdf->getError()];
    }

    if (file_exists($filePath)){
      return ['success' => true, 'filepath' => $filePath];
    }

    return ['success' => false, 'error_message' => 'PDF introuvable '.$filePath];
  }

  /**
   * @return ConceptionPageInterface[]
   */
  private function getVisiblePages(ConceptionTemplateInterface $conceptionTemplate, ConceptionDocumentInterface $conceptionDocument): array
  {
    $visiblePages = [];
    foreach ($conceptionTemplate->getPages() as $page) {
      if (!($page instanceof ConceptionPageInterface)) {
        continue;
      }
      if ($this->conceptionPersonnalisationService->canShowElementConceptionTemplate($page->getConditions(), $conceptionDocument)) {
        $visiblePages[] = $page;
      }
    }

    return $visiblePages;
  }

  private function getDocumentFormat(ConceptionTemplateInterface $conceptionTemplate): string
  {
    $documentFormat = strtoupper(trim($conceptionTemplate->getDocumentFormat()));
    if (in_array($documentFormat, self::AVAILABLE_DOCUMENT_FORMATS, true)) {
      return $documentFormat;
    }

    return self::DEFAULT_DOCUMENT_FORMAT;
  }

  private function getDocumentOrientation(string $documentFormat): string
  {
    if ($documentFormat === ConceptionTemplateInterface::DOCUMENT_FORMAT_A3) {
      return 'Landscape';
    }

    return 'Portrait';
  }

  /**
   * @return array{0:int,1:int} [widthMm, heightMm]
   */
  private function getDocumentDimensionsMm(string $documentFormat): array
  {
    if ($documentFormat === ConceptionTemplateInterface::DOCUMENT_FORMAT_A3) {
      return [420, 297];
    }

    return [210, 297];
  }
}
