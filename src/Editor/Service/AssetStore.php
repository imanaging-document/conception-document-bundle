<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AssetStore
{
    public function __construct(private readonly string $uploadPath, private readonly int $maxBytes = 20971520)
    {
    }
    public function importFile(string $path): array
    {
        if (!is_file($path) || filesize($path) > $this->maxBytes) {
            throw new \DomainException('Image absente ou trop volumineuse (20 Mo maximum).');
        }
        $bytes = file_get_contents($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extensions = ['image/png' => 'png','image/jpeg' => 'jpg','image/webp' => 'webp','image/svg+xml' => 'svg'];
        if (!isset($extensions[$mime])) {
            throw new \DomainException('Formats acceptés : PNG, JPEG, WebP, SVG.');
        }
        $width = $height = 0;
        if ($mime === 'image/svg+xml') {
            $bytes = (new SvgImageSanitizer())->sanitize($bytes);
            if ($bytes === false || !str_contains($bytes, '<svg')) {
                throw new \DomainException('SVG invalide.');
            }
            [$width,$height] = $this->svgDimensions($bytes);
        } else {
            $dimensions = getimagesizefromstring($bytes);
            if (!$dimensions || $dimensions[0] * $dimensions[1] > 40000000) {
                throw new \DomainException('Image invalide ou supérieure à 40 millions de pixels.');
            }
            [$width,$height] = $dimensions;
        }
        $hash = hash('sha256', $bytes);
        $name = $hash.'.'.$extensions[$mime];
        $dir = rtrim($this->uploadPath, '/').'/editor-assets';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Stockage des images inaccessible.');
        }
        if (!is_file($dir.'/'.$name)) {
            $temp = tempnam($dir, '.upload-');
            try {
                if (file_put_contents($temp, $bytes) === false || !rename($temp, $dir.'/'.$name)) {
                    throw new \RuntimeException('Écriture image impossible.');
                }
            } finally {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
        }
        return ['id' => $name,'mime' => $mime,'width' => $width,'height' => $height,'path' => '/editor-assets/'.$name];
    }
    private function svgDimensions(string $bytes): array
    {
        $dom = new \DOMDocument();
        $dom->loadXML($bytes, LIBXML_NONET);
        $root = $dom->documentElement;
        $length = static function (string $value): float {
            if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)(px|pt|pc|mm|cm|in)?$/i', trim($value), $match)) {
                return 0;
            }
            return (float)$match[1] * match(strtolower($match[2] ?? 'px')) {
                'pt' => 96 / 72, 'pc' => 16, 'mm' => 96 / 25.4, 'cm' => 96 / 2.54, 'in' => 96, default => 1,
            };
        };
        $width = $length($root->getAttribute('width'));
        $height = $length($root->getAttribute('height'));
        $viewBox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
        if (count($viewBox) === 4 && is_numeric($viewBox[2]) && is_numeric($viewBox[3]) && (float)$viewBox[2] > 0 && (float)$viewBox[3] > 0) {
            $ratio = (float)$viewBox[2] / (float)$viewBox[3];
            if ($width <= 0) $width = $height > 0 ? $height * $ratio : (float)$viewBox[2];
            if ($height <= 0) $height = $width / $ratio;
        }
        return [$width, $height];
    }
    public function read(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{64}\.(png|jpg|webp|svg)$/', $id)) {
            throw new \DomainException('Référence image invalide.');
        }
        $path = rtrim($this->uploadPath, '/').'/editor-assets/'.$id;
        if (!is_file($path)) {
            throw new \DomainException('Image introuvable.');
        }
        $bytes = file_get_contents($path);
        if (!hash_equals(substr($id, 0, 64), hash('sha256', $bytes))) {
            throw new \DomainException('L’empreinte du fichier image a changé.');
        }
        return $bytes;
    }
    public function dataUri(array $asset): string
    {
        $bytes = $this->read($asset['id']);
        $mime = ['png' => 'image/png','jpg' => 'image/jpeg','webp' => 'image/webp','svg' => 'image/svg+xml'][pathinfo($asset['id'], PATHINFO_EXTENSION)];
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
    public function legacyPath(string $relative): string
    {
        $root = realpath($this->uploadPath);
        $path = realpath(rtrim($this->uploadPath, '/').'/'.ltrim($relative, '/'));
        if (!$root || !$path || !str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            throw new \DomainException('Chemin image hors du stockage.');
        }
        return $path;
    }
}
