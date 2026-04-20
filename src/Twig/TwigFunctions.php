<?php

namespace Imanaging\ConceptionDocumentBundle\Twig;

use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocStyleInterface;
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

  public function getFunctions(): array
  {
    return [
      new TwigFunction('canShowBloc', [$this, 'canShowBloc']),
      new TwigFunction('renderBloc', [$this, 'renderBloc']),
      new TwigFunction('getImageBinary', [$this, 'getImageBinary']),
      new TwigFunction('personnalizeText', [$this, 'personnalizeText']),
      new TwigFunction('showCustomBlocEdition', [$this, 'showCustomBlocEdition']),
      new TwigFunction('isBlocLocked', [$this, 'isBlocLocked']),
      new TwigFunction('isNativeCheckboxBloc', [$this, 'isNativeCheckboxBloc']),
      new TwigFunction('isNativeCheckboxChecked', [$this, 'isNativeCheckboxChecked']),
      new TwigFunction('isBackgroundBloc', [$this, 'isBackgroundBloc']),
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

  public function showCustomBlocEdition(ConceptionBlocInterface $bloc)
  {
    return $this->conceptionPersonnalisationService->showCustomBlocEdition($bloc);
  }

  public function isBlocLocked(ConceptionBlocInterface $bloc): bool
  {
    $rootStyle = $this->getRootStyle($bloc);
    if (!($rootStyle instanceof ConceptionBlocStyleInterface)) {
      return false;
    }

    $properties = json_decode($rootStyle->getStyle(), true);
    if (!is_array($properties)) {
      return false;
    }

    return $this->isTruthy($properties['--bundle-lock'] ?? false);
  }

  public function isNativeCheckboxBloc(ConceptionBlocInterface $bloc): bool
  {
    if (!method_exists($bloc, 'getType') || !method_exists($bloc->getType(), 'getCode')) {
      return false;
    }

    if ($bloc->getType()->getCode() !== 'bloc_texte') {
      return false;
    }

    $rootStyle = $this->getRootStyle($bloc);
    if (!($rootStyle instanceof ConceptionBlocStyleInterface)) {
      return false;
    }

    $properties = json_decode($rootStyle->getStyle(), true);
    if (!is_array($properties)) {
      return false;
    }

    return $this->isTruthy($properties['--bundle-checkbox-native'] ?? false);
  }

  public function isNativeCheckboxChecked(ConceptionBlocInterface $bloc): bool
  {
    if (!$this->isNativeCheckboxBloc($bloc)) {
      return false;
    }

    $rootStyle = $this->getRootStyle($bloc);
    if (!($rootStyle instanceof ConceptionBlocStyleInterface)) {
      return false;
    }

    $properties = json_decode($rootStyle->getStyle(), true);
    if (!is_array($properties)) {
      return false;
    }

    return $this->isTruthy($properties['--bundle-checkbox-checked'] ?? false);
  }

  public function isBackgroundBloc(ConceptionBlocInterface $bloc): bool
  {
    if (!method_exists($bloc, 'getType') || !method_exists($bloc->getType(), 'getCode')) {
      return false;
    }
    if ($bloc->getType()->getCode() !== 'bloc_image') {
      return false;
    }

    $rootStyle = $this->getRootStyle($bloc);
    if ($rootStyle instanceof ConceptionBlocStyleInterface) {
      $properties = json_decode($rootStyle->getStyle(), true);
      if (is_array($properties) && $this->isTruthy($properties['--bundle-background'] ?? false)) {
        return true;
      }
    }

    return stripos($bloc->getLibelle(), 'fond de page') === 0;
  }

  private function getRootStyle(ConceptionBlocInterface $bloc): ?ConceptionBlocStyleInterface
  {
    if (!method_exists($bloc, 'getStyleByCode')) {
      return null;
    }

    $rootStyle = $bloc->getStyleByCode('root');
    if ($rootStyle instanceof ConceptionBlocStyleInterface) {
      return $rootStyle;
    }

    return null;
  }

  private function isTruthy(mixed $value): bool
  {
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
  }
}
