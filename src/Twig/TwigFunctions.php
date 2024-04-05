<?php

namespace Imanaging\ConceptionDocumentBundle\Twig;

use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionDocumentInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionPersonnalisationServiceInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TwigFunctions extends AbstractExtension
{
  private ConceptionPersonnalisationServiceInterface $conceptionPersonnalisationService;
  private string $uploadPath;

  public function __construct(ConceptionPersonnalisationServiceInterface $conceptionPersonnalisationService, $uploadPath)
  {
    $this->conceptionPersonnalisationService = $conceptionPersonnalisationService;
    $this->uploadPath = $uploadPath;
  }

  public function getFunctions() : array
  {
    return [
      new TwigFunction('canShowBloc', [$this, 'canShowBloc']),
      new TwigFunction('renderBloc', [$this, 'renderBloc']),
      new TwigFunction('getImageBinary', [$this, 'getImageBinary']),
      new TwigFunction('personnalizeText', [$this, 'personnalizeText']),
    ];
  }

  public function canShowBloc($conditions, ConceptionDocumentInterface $conceptionDocument)
  {
    return $this->conceptionPersonnalisationService->canShowElementConceptionTemplate($conditions, $conceptionDocument);
  }

  public function renderBloc(ConceptionBlocInterface $bloc, ConceptionDocumentInterface $conceptionDocument)
  {
    return $this->conceptionPersonnalisationService->renderBloc($bloc, $conceptionDocument);
  }

  public function getImageBinary($relativePath)
  {
    $filePath = $this->uploadPath.$relativePath;
    if (file_exists($filePath)){
      if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) == 'svg'){
        $prefix = 'data:image/svg+xml;base64,';
      } elseif(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) == 'jpg'){
        $prefix = 'data:image/jpg;base64,';
      } elseif(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) == 'jpeg'){
        $prefix = 'data:image/jpeg;base64,';
      } else{
        $prefix = 'data:image/png;base64,';
      }
      return $prefix.base64_encode(file_get_contents($filePath));
    } else {
      return '';
    }
  }

  public function personnalizeText(string $text, ConceptionDocumentInterface $conceptionDocument)
  {
    return $this->conceptionPersonnalisationService->personnalizeText($text, $conceptionDocument);
  }
}
