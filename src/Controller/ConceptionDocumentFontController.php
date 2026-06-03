<?php

namespace Imanaging\ConceptionDocumentBundle\Controller;

use Imanaging\ConceptionDocumentBundle\Service\ConceptionFontService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Twig\Environment;

class ConceptionDocumentFontController extends AbstractController
{
  public function __construct(
    private readonly ConceptionFontService $conceptionFontService,
    private readonly Environment $twig,
    private readonly string $basePath
  )
  {
  }

  public function index(): Response
  {
    return new Response($this->twig->render('@ImanagingConceptionDocument/ConceptionDocument/fonts/index.html.twig', [
      'families' => $this->conceptionFontService->listFamilies(),
      'font_css' => $this->conceptionFontService->getFontFaceCss(),
      'basePath' => $this->basePath,
    ]));
  }

  public function upload(Request $request): Response
  {
    try {
      $this->conceptionFontService->uploadFamily(
        (string)$request->request->get('family', ''),
        $this->conceptionFontService->splitAliases((string)$request->request->get('aliases', '')),
        (array)$request->files->get('font_files', [])
      );
      $this->addFlash('success', 'Police importée avec succès.');
    } catch (\Throwable $e) {
      $this->addFlash('error', $e->getMessage());
    }

    return $this->redirectToRoute('conception_document_fonts');
  }

  public function updateManifest(string $familyId, Request $request): Response
  {
    try {
      $this->conceptionFontService->updateManifest($familyId, $request->request->all());
      $this->addFlash('success', 'Police mise à jour avec succès.');
    } catch (\Throwable $e) {
      $this->addFlash('error', $e->getMessage());
    }

    return $this->redirectToRoute('conception_document_fonts');
  }

  public function delete(string $familyId): Response
  {
    try {
      $this->conceptionFontService->deleteFamily($familyId);
      $this->addFlash('success', 'Police supprimée avec succès.');
    } catch (\Throwable $e) {
      $this->addFlash('error', $e->getMessage());
    }

    return $this->redirectToRoute('conception_document_fonts');
  }

  public function serveFile(string $familyId, string $filename): Response
  {
    $fontPath = $this->conceptionFontService->getFontFilePath($familyId, $filename);
    if ($fontPath === null) {
      throw $this->createNotFoundException('Police introuvable.');
    }

    $response = new BinaryFileResponse($fontPath);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($fontPath));
    $response->setPublic();
    $response->setMaxAge(31536000);
    $response->setSharedMaxAge(31536000);
    $response->headers->addCacheControlDirective('immutable');

    return $response;
  }
}
