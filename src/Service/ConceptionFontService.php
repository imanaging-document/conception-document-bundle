<?php

namespace Imanaging\ConceptionDocumentBundle\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class ConceptionFontService
{
  private const FONT_EXTENSIONS = ['ttf', 'otf', 'woff', 'woff2'];
  private const MANIFEST_FILENAME = 'manifest.json';
  private ?string $fontFaceCssCache = null;
  /**
   * @var array<string, string>
   */
  private array $fontFaceCssByFamilyCache = [];

  public function __construct(
    private readonly string $uploadPath
  )
  {
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function listFamilies(): array
  {
    return $this->scanFamilies(true);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function getFamily(string $familyId): ?array
  {
    $directory = $this->getFamilyDirectory($familyId);
    if (!is_dir($directory)) {
      return null;
    }

    $manifest = $this->getManifestForDirectory($directory);

    return [
      'id' => basename($directory),
      'path' => $directory,
      'manifest' => $manifest,
      'variants' => $manifest['variants'],
      'usages' => $this->countSvgUsages($this->getSearchTerms($manifest)),
    ];
  }

  /**
   * @param UploadedFile[] $fontFiles
   */
  public function uploadFamily(string $family, array $aliases, array $fontFiles): void
  {
    $family = trim($family);
    if ($family === '') {
      throw new \InvalidArgumentException('Le nom de la famille de police est obligatoire.');
    }

    $validFiles = array_values(array_filter($fontFiles, static fn ($file): bool => $file instanceof UploadedFile));
    if (count($validFiles) === 0) {
      throw new \InvalidArgumentException('Veuillez sélectionner au moins un fichier de police.');
    }

    $familyId = $this->slugify($family);
    $familyDirectory = $this->getFamilyDirectory($familyId);
    if (!is_dir($familyDirectory) && !mkdir($familyDirectory, 0755, true) && !is_dir($familyDirectory)) {
      throw new \RuntimeException('Impossible de créer le répertoire de police '.$familyDirectory);
    }

    $manifest = $this->getManifestForDirectory($familyDirectory, $family);
    $manifest['family'] = $family;
    $manifest['aliases'] = $this->normalizeAliases(array_merge($manifest['aliases'], $aliases, [$family]));

    foreach ($validFiles as $fontFile) {
      $extension = strtolower((string)$fontFile->getClientOriginalExtension());
      if ($extension === '') {
        $extension = strtolower((string)$fontFile->guessExtension());
      }

      if (!in_array($extension, self::FONT_EXTENSIONS, true)) {
        throw new \InvalidArgumentException('Extension non supportée pour '.$fontFile->getClientOriginalName().' (ttf, otf, woff, woff2 uniquement).');
      }

      $safeFilename = $this->sanitizeFilename(pathinfo($fontFile->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$extension;
      $fontFile->move($familyDirectory, $safeFilename);
      $manifest['variants'] = $this->upsertVariant($manifest['variants'], [
        'file' => $safeFilename,
        'weight' => $this->guessFontWeight($safeFilename),
        'style' => $this->guessFontStyle($safeFilename),
      ]);
    }

    $this->saveManifest($familyId, $manifest);
    $this->fontFaceCssCache = null;
    $this->fontFaceCssByFamilyCache = [];
  }

  /**
   * @param array<string, mixed> $manifestData
   */
  public function updateManifest(string $familyId, array $manifestData): void
  {
    $family = trim((string)($manifestData['family'] ?? ''));
    if ($family === '') {
      throw new \InvalidArgumentException('Le nom de la famille est obligatoire.');
    }

    $directory = $this->getFamilyDirectory($familyId);
    if (!is_dir($directory)) {
      throw new \InvalidArgumentException('Famille de police introuvable.');
    }

    $currentManifest = $this->getManifestForDirectory($directory, $family);
    $variants = [];
    foreach (($manifestData['variants'] ?? []) as $variant) {
      $file = basename((string)($variant['file'] ?? ''));
      if ($file === '' || !is_file($directory.'/'.$file)) {
        continue;
      }

      $weight = (int)($variant['weight'] ?? 400);
      if (!in_array($weight, [100, 200, 300, 400, 500, 600, 700, 800, 900], true)) {
        $weight = 400;
      }

      $style = strtolower(trim((string)($variant['style'] ?? 'normal')));
      if (!in_array($style, ['normal', 'italic', 'oblique'], true)) {
        $style = 'normal';
      }

      $variants[] = [
        'file' => $file,
        'weight' => $weight,
        'style' => $style,
      ];
    }

    $manifest = [
      'family' => $family,
      'aliases' => $this->normalizeAliases($this->splitAliases((string)($manifestData['aliases'] ?? ''))),
      'variants' => count($variants) > 0 ? $variants : $currentManifest['variants'],
    ];

    $this->saveManifest($familyId, $manifest);
    $this->fontFaceCssCache = null;
    $this->fontFaceCssByFamilyCache = [];
  }

  public function deleteFamily(string $familyId): void
  {
    $directory = $this->getFamilyDirectory($familyId);
    if (!is_dir($directory)) {
      return;
    }

    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
      if ($file instanceof \SplFileInfo && $file->isDir()) {
        rmdir($file->getPathname());
      } elseif ($file instanceof \SplFileInfo) {
        unlink($file->getPathname());
      }
    }

    rmdir($directory);
    $this->fontFaceCssCache = null;
    $this->fontFaceCssByFamilyCache = [];
  }

  public function getFontFaceCss(): string
  {
    if ($this->fontFaceCssCache !== null) {
      return $this->fontFaceCssCache;
    }

    $this->fontFaceCssCache = $this->buildFontFaceCss();

    return $this->fontFaceCssCache;
  }

  /**
   * @param string[] $fontFamilies
   */
  public function getFontFaceCssForFamilies(array $fontFamilies): string
  {
    $requestedFontKeys = [];
    foreach ($fontFamilies as $fontFamily) {
      $fontFamily = trim((string)$fontFamily);
      if ($fontFamily !== '') {
        $requestedFontKeys[$this->normalizeFontFamilyKey($fontFamily)] = true;
      }
    }

    if (count($requestedFontKeys) === 0) {
      return '';
    }

    ksort($requestedFontKeys);
    $cacheKey = implode('|', array_keys($requestedFontKeys));
    if (array_key_exists($cacheKey, $this->fontFaceCssByFamilyCache)) {
      return $this->fontFaceCssByFamilyCache[$cacheKey];
    }

    $this->fontFaceCssByFamilyCache[$cacheKey] = $this->buildFontFaceCss($requestedFontKeys);

    return $this->fontFaceCssByFamilyCache[$cacheKey];
  }

  /**
   * @param array<string, true>|null $requestedFontKeys
   */
  private function buildFontFaceCss(?array $requestedFontKeys = null): string
  {
    $fontFaces = [];
    foreach ($this->scanFamilies(false) as $family) {
      $manifest = $family['manifest'];
      foreach ($manifest['variants'] as $variant) {
        $families = $this->getVariantFamilies($manifest, $variant);
        if ($requestedFontKeys !== null) {
          $families = array_values(array_filter(
            $families,
            fn (string $fontFamily): bool => isset($requestedFontKeys[$this->normalizeFontFamilyKey($fontFamily)])
          ));
        }

        if (count($families) === 0) {
          continue;
        }

        $fontPath = $family['path'].'/'.$variant['file'];
        if (!is_file($fontPath)) {
          continue;
        }

        $fontContent = file_get_contents($fontPath);
        if ($fontContent === false) {
          continue;
        }

        $extension = strtolower(pathinfo($fontPath, PATHINFO_EXTENSION));
        $fontData = base64_encode($fontContent);

        foreach ($families as $fontFamily) {
          $fontFaces[] = sprintf(
            "@font-face{font-family:'%s';src:url('data:%s;base64,%s') format('%s');font-weight:%d;font-style:%s;}",
            str_replace("'", "\\'", $fontFamily),
            $this->getFontMimeType($extension),
            $fontData,
            $this->getFontFormat($extension),
            (int)$variant['weight'],
            $variant['style']
          );
        }
      }
    }

    return implode('', array_unique($fontFaces));
  }

  /**
   * @return string[]
   */
  public function splitAliases(string $aliases): array
  {
    return preg_split('/[\r\n,;]+/', $aliases, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function scanFamilies(bool $withUsages): array
  {
    $this->ensureFontsDirectoryExists();
    $families = [];
    $directories = glob($this->getFontsDirectory().'/*', GLOB_ONLYDIR) ?: [];

    foreach ($directories as $directory) {
      $manifest = $this->getManifestForDirectory($directory);
      $families[] = [
        'id' => basename($directory),
        'path' => $directory,
        'manifest' => $manifest,
        'variants' => $manifest['variants'],
        'usages' => $withUsages ? $this->countSvgUsages($this->getSearchTerms($manifest)) : 0,
      ];
    }

    usort($families, static function (array $a, array $b): int {
      return strcasecmp((string)$a['manifest']['family'], (string)$b['manifest']['family']);
    });

    return $families;
  }

  private function ensureFontsDirectoryExists(): void
  {
    $directory = $this->getFontsDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
      throw new \RuntimeException('Impossible de créer le répertoire des polices '.$directory);
    }
  }

  private function getFontsDirectory(): string
  {
    return rtrim($this->uploadPath, '/').'/fonts';
  }

  private function normalizeFontFamilyKey(string $fontFamily): string
  {
    return strtolower(trim($fontFamily));
  }

  private function getFamilyDirectory(string $familyId): string
  {
    return $this->getFontsDirectory().'/'.$this->slugify($familyId);
  }

  /**
   * @return array{family:string, aliases:array<int, string>, variants:array<int, array{file:string, weight:int, style:string}>}
   */
  private function getManifestForDirectory(string $directory, ?string $fallbackFamily = null): array
  {
    $manifestPath = $directory.'/'.self::MANIFEST_FILENAME;
    if (is_file($manifestPath)) {
      $manifest = json_decode((string)file_get_contents($manifestPath), true);
      if (is_array($manifest)) {
        return [
          'family' => trim((string)($manifest['family'] ?? $fallbackFamily ?? basename($directory))),
          'aliases' => $this->normalizeAliases($manifest['aliases'] ?? []),
          'variants' => $this->normalizeVariants($directory, $manifest['variants'] ?? []),
        ];
      }
    }

    $family = $fallbackFamily ?? $this->normalizeFontFamily(basename($directory));

    return [
      'family' => $family,
      'aliases' => [$family],
      'variants' => $this->detectVariants($directory),
    ];
  }

  /**
   * @param array<int, array<string, mixed>> $variants
   * @return array<int, array{file:string, weight:int, style:string}>
   */
  private function normalizeVariants(string $directory, array $variants): array
  {
    $normalizedVariants = [];
    foreach ($variants as $variant) {
      $file = basename((string)($variant['file'] ?? ''));
      if ($file === '' || !is_file($directory.'/'.$file)) {
        continue;
      }

      $normalizedVariants = $this->upsertVariant($normalizedVariants, [
        'file' => $file,
        'weight' => (int)($variant['weight'] ?? $this->guessFontWeight($file)),
        'style' => (string)($variant['style'] ?? $this->guessFontStyle($file)),
      ]);
    }

    if (count($normalizedVariants) === 0) {
      return $this->detectVariants($directory);
    }

    return $normalizedVariants;
  }

  /**
   * @return array<int, array{file:string, weight:int, style:string}>
   */
  private function detectVariants(string $directory): array
  {
    $variants = [];
    foreach (glob($directory.'/*') ?: [] as $filePath) {
      if (!is_file($filePath)) {
        continue;
      }

      $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
      if (!in_array($extension, self::FONT_EXTENSIONS, true)) {
        continue;
      }

      $filename = basename($filePath);
      $variants[] = [
        'file' => $filename,
        'weight' => $this->guessFontWeight($filename),
        'style' => $this->guessFontStyle($filename),
      ];
    }

    usort($variants, static function (array $a, array $b): int {
      return [$a['weight'], $a['style'], $a['file']] <=> [$b['weight'], $b['style'], $b['file']];
    });

    return $variants;
  }

  /**
   * @param array<int, array{file:string, weight:int, style:string}> $variants
   * @param array{file:string, weight:int, style:string} $newVariant
   * @return array<int, array{file:string, weight:int, style:string}>
   */
  private function upsertVariant(array $variants, array $newVariant): array
  {
    foreach ($variants as $index => $variant) {
      if ($variant['file'] === $newVariant['file']) {
        $variants[$index] = $newVariant;
        return $variants;
      }
    }

    $variants[] = $newVariant;
    return $variants;
  }

  /**
   * @param array<string, mixed> $manifest
   * @param array<string, mixed> $variant
   * @return string[]
   */
  private function getVariantFamilies(array $manifest, array $variant): array
  {
    $families = array_merge([$manifest['family']], $manifest['aliases']);
    $weight = (int)$variant['weight'];
    $style = (string)$variant['style'];

    foreach ($manifest['aliases'] as $alias) {
      if ($weight >= 700 && $style !== 'normal') {
        $families[] = $alias.'-BoldItalic';
        $families[] = $alias.'-Bold-Italic';
      } elseif ($weight >= 700) {
        $families[] = $alias.'-Bold';
      } elseif ($style !== 'normal') {
        $families[] = $alias.'-Italic';
      }
    }

    return $this->normalizeAliases($families);
  }

  /**
   * @param string[] $aliases
   * @return string[]
   */
  private function normalizeAliases(array $aliases): array
  {
    $normalizedAliases = [];
    foreach ($aliases as $alias) {
      $alias = trim((string)$alias);
      if ($alias !== '') {
        $normalizedAliases[] = $alias;
      }
    }

    return array_values(array_unique($normalizedAliases));
  }

  /**
   * @param array<string, mixed> $manifest
   */
  private function saveManifest(string $familyId, array $manifest): void
  {
    $directory = $this->getFamilyDirectory($familyId);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
      throw new \RuntimeException('Impossible de créer le répertoire de police '.$directory);
    }

    file_put_contents(
      $directory.'/'.self::MANIFEST_FILENAME,
      json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
  }

  /**
   * @param string[] $searchTerms
   */
  private function countSvgUsages(array $searchTerms): int
  {
    if (count($searchTerms) === 0 || !is_dir($this->uploadPath)) {
      return 0;
    }

    $usages = 0;
    $svgFiles = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator(rtrim($this->uploadPath, '/'), \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($svgFiles as $svgFile) {
      if (!($svgFile instanceof \SplFileInfo) || !$svgFile->isFile() || strtolower($svgFile->getExtension()) !== 'svg') {
        continue;
      }

      $content = file_get_contents($svgFile->getPathname());
      if ($content === false) {
        continue;
      }

      foreach ($searchTerms as $searchTerm) {
        if (stripos($content, $searchTerm) !== false) {
          $usages++;
          break;
        }
      }
    }

    return $usages;
  }

  /**
   * @param array<string, mixed> $manifest
   * @return string[]
   */
  private function getSearchTerms(array $manifest): array
  {
    return $this->normalizeAliases(array_merge([$manifest['family']], $manifest['aliases']));
  }

  private function slugify(string $value): string
  {
    $normalized = class_exists(\Transliterator::class)
      ? (string)\Transliterator::create('Any-Latin; Latin-ASCII')->transliterate($value)
      : (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $slug = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $normalized), '-'));
    if ($slug === '') {
      throw new \InvalidArgumentException('Nom de famille de police invalide.');
    }

    return $slug;
  }

  private function sanitizeFilename(string $filename): string
  {
    $filename = $this->slugify($filename);

    return $filename !== '' ? $filename : uniqid('font-', true);
  }

  private function normalizeFontFamily(string $name): string
  {
    $name = preg_replace('/[-_]+/', ' ', $name) ?? $name;
    return ucwords(trim($name));
  }

  private function guessFontWeight(string $fontName): int
  {
    $fontName = pathinfo($fontName, PATHINFO_FILENAME);
    if (preg_match('/(?:bold|black|heavy|semibold|demibold|[-_ ]b$|b$|z$)/i', $fontName)) {
      return 700;
    }

    return 400;
  }

  private function guessFontStyle(string $fontName): string
  {
    $fontName = pathinfo($fontName, PATHINFO_FILENAME);
    if (preg_match('/(?:italic|oblique|[-_ ]i$|ii$|li$|z$)/i', $fontName)) {
      return 'italic';
    }

    return 'normal';
  }

  private function getFontFormat(string $extension): string
  {
    return match ($extension) {
      'otf' => 'opentype',
      'woff' => 'woff',
      'woff2' => 'woff2',
      default => 'truetype',
    };
  }

  private function getFontMimeType(string $extension): string
  {
    return match ($extension) {
      'otf' => 'font/otf',
      'woff' => 'font/woff',
      'woff2' => 'font/woff2',
      default => 'font/ttf',
    };
  }
}
