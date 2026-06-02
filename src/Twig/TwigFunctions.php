<?php

namespace Imanaging\ConceptionDocumentBundle\Twig;

use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocStyleInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionDocumentInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionPersonnalisationServiceInterface;
use Imanaging\ConceptionDocumentBundle\Service\ConceptionFontService;
use Imanaging\ConceptionDocumentBundle\Service\ConceptionQrCodeService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TwigFunctions extends AbstractExtension
{
  private ConceptionPersonnalisationServiceInterface $conceptionPersonnalisationService;
  private string $uploadPath;

  public function __construct(
    ConceptionPersonnalisationServiceInterface $conceptionPersonnalisationService,
    $uploadPath,
    private readonly ConceptionFontService $conceptionFontService,
    private readonly ConceptionQrCodeService $conceptionQrCodeService
  )
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
      new TwigFunction('getConceptionFontFaceCss', [$this, 'getConceptionFontFaceCss']),
      new TwigFunction('isQrCodeBloc', [$this, 'isQrCodeBloc']),
      new TwigFunction('getQrCodeDataUri', [$this, 'getQrCodeDataUri']),
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
        return $prefix.base64_encode($this->getSvgContentWithEmbeddedFonts($filePath));
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

  public function getConceptionFontFaceCss(): string
  {
    return $this->conceptionFontService->getFontFaceCss();
  }

  public function isQrCodeBloc(ConceptionBlocInterface $bloc): bool
  {
    if (!method_exists($bloc, 'getType') || !method_exists($bloc->getType(), 'getCode')) {
      return false;
    }

    $typeCode = $bloc->getType()->getCode();
    if ($typeCode === 'bloc_qrcode') {
      return true;
    }

    if ($typeCode !== 'bloc_texte') {
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

    return $this->isTruthy($properties['--bundle-qrcode'] ?? false);
  }

  public function getQrCodeDataUri(string $data): string
  {
    return $this->conceptionQrCodeService->getDataUri($data);
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

  private function getSvgContentWithEmbeddedFonts(string $filePath): string
  {
    $svgContent = file_get_contents($filePath);
    if ($svgContent === false) {
      return '';
    }

    $fontFaceCss = $this->conceptionFontService->getFontFaceCssForFamilies(
      $this->extractSvgFontFamilies($svgContent),
      false
    );
    if ($fontFaceCss === '' || str_contains($svgContent, 'data-conception-fonts="embedded"')) {
      return $svgContent;
    }

    $fontStyle = '<style data-conception-fonts="embedded">'.$fontFaceCss.'</style>';
    if (preg_match('/<defs\b[^>]*>/i', $svgContent)) {
      return (string)preg_replace('/(<defs\b[^>]*>)/i', '$1'.$fontStyle, $svgContent, 1);
    }

    return (string)preg_replace('/(<svg\b[^>]*>)/i', '$1<defs>'.$fontStyle.'</defs>', $svgContent, 1);
  }

  /**
   * @return string[]
   */
  private function extractSvgFontFamilies(string $svgContent): array
  {
    $fontStacks = [];
    if (preg_match_all('/font-family\s*:\s*([^;}]+)/i', $svgContent, $cssMatches)) {
      foreach ($cssMatches[1] as $fontStack) {
        $fontStacks[] = trim((string)$fontStack);
      }
    }

    if (preg_match_all('/font-family\s*=\s*([\'"])(.*?)\1/i', $svgContent, $attributeMatches)) {
      foreach ($attributeMatches[2] as $fontStack) {
        $fontStacks[] = trim((string)$fontStack);
      }
    }

    $fontFamilies = [];
    foreach ($fontStacks as $fontStack) {
      foreach (explode(',', $fontStack) as $fontFamily) {
        $fontFamily = trim($fontFamily);
        $fontFamily = trim($fontFamily, "\"' \t\n\r\0\x0B");
        if ($fontFamily !== '') {
          $fontFamilies[] = $fontFamily;
        }
      }
    }

    return array_values(array_unique($fontFamilies));
  }
}
