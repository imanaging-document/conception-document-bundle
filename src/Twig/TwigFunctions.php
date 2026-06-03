<?php

namespace Imanaging\ConceptionDocumentBundle\Twig;

use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocStyleInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionDocumentInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionPageInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionPersonnalisationServiceInterface;
use Imanaging\ConceptionDocumentBundle\Service\ConceptionFontService;
use Imanaging\ConceptionDocumentBundle\Service\ConceptionQrCodeService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TwigFunctions extends AbstractExtension
{
  private const GENERIC_FONT_FAMILIES = [
    'cursive',
    'emoji',
    'fantasy',
    'fangsong',
    'math',
    'monospace',
    'sans-serif',
    'serif',
    'system-ui',
    'ui-monospace',
    'ui-rounded',
    'ui-sans-serif',
    'ui-serif',
  ];

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
        return $prefix.base64_encode(file_get_contents($filePath));
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

  public function getConceptionFontFaceCss(?ConceptionPageInterface $page = null, ?ConceptionDocumentInterface $conceptionDocument = null): string
  {
    if (!($page instanceof ConceptionPageInterface)) {
      return '';
    }

    $fontFamilies = $this->getVisibleTextBlocFontFamilies($page, $conceptionDocument);
    if (count($fontFamilies) === 0) {
      return '';
    }

    return $this->conceptionFontService->getFontFaceCssForFamilies($fontFamilies);
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

  /**
   * @return string[]
   */
  private function getVisibleTextBlocFontFamilies(ConceptionPageInterface $page, ?ConceptionDocumentInterface $conceptionDocument): array
  {
    $fontFamilies = [];
    foreach ($page->getBlocs() as $bloc) {
      if (!($bloc instanceof ConceptionBlocInterface)) {
        continue;
      }
      if (!method_exists($bloc, 'getType') || $bloc->getType()->getCode() !== 'bloc_texte') {
        continue;
      }
      if ($conceptionDocument instanceof ConceptionDocumentInterface && !$this->canShowBloc($bloc->getConditions(), $conceptionDocument)) {
        continue;
      }
      if (!method_exists($bloc, 'getStyles')) {
        continue;
      }

      foreach ($bloc->getStyles() as $style) {
        if (!($style instanceof ConceptionBlocStyleInterface)) {
          continue;
        }

        $fontFamilies = array_merge($fontFamilies, $this->extractFontFamiliesFromStyle($style));
      }
    }

    return array_values(array_unique($fontFamilies));
  }

  /**
   * @return string[]
   */
  private function extractFontFamiliesFromStyle(ConceptionBlocStyleInterface $style): array
  {
    $fontFamilies = [];
    $properties = $style->getDecodedStyle();
    if (is_array($properties)) {
      foreach ($properties as $property => $value) {
        $normalizedProperty = strtolower(str_replace(['-', '_'], '', (string)$property));
        if ($normalizedProperty === 'fontfamily') {
          $fontFamilies = array_merge($fontFamilies, $this->splitFontFamilyStack((string)$value));
        }
      }
    }

    $rawStyle = $style->getStyle();
    if (preg_match_all('/font-family\s*:\s*([^;}]+)/i', $rawStyle, $matches)) {
      foreach ($matches[1] as $fontStack) {
        $fontFamilies = array_merge($fontFamilies, $this->splitFontFamilyStack((string)$fontStack));
      }
    }

    return array_values(array_unique($fontFamilies));
  }

  /**
   * @return string[]
   */
  private function splitFontFamilyStack(string $fontStack): array
  {
    $fontFamilies = [];
    foreach (explode(',', $fontStack) as $fontFamily) {
      $fontFamily = trim($fontFamily);
      $fontFamily = trim($fontFamily, "\"' \t\n\r\0\x0B");
      if ($fontFamily === '' || in_array(strtolower($fontFamily), self::GENERIC_FONT_FAMILIES, true)) {
        continue;
      }

      $fontFamilies[] = $fontFamily;
    }

    return array_values(array_unique($fontFamilies));
  }
}
