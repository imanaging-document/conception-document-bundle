<?php

namespace Imanaging\ConceptionDocumentBundle\Service;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;

class ConceptionQrCodeService
{
  public function getDataUri(string $data, array $options = []): string
  {
    $data = trim($data);
    if ($data === '') {
      return '';
    }

    $size = max(60, (int)($options['size'] ?? 300));
    $margin = max(0, (int)($options['margin'] ?? 1));

    $qrCode = new QrCode(
      data: $data,
      encoding: new Encoding((string)($options['encoding'] ?? 'UTF-8')),
      errorCorrectionLevel: $this->getErrorCorrectionLevel((string)($options['error_correction'] ?? 'medium')),
      size: $size,
      margin: $margin,
      roundBlockSizeMode: RoundBlockSizeMode::None,
      foregroundColor: $this->getColor((string)($options['foreground_color'] ?? '#000000')),
      backgroundColor: $this->getColor((string)($options['background_color'] ?? '#ffffff'))
    );

    $writer = new SvgWriter();
    $result = $writer->write($qrCode, null, null, [
      SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
      SvgWriter::WRITER_OPTION_EXCLUDE_SVG_WIDTH_AND_HEIGHT => true,
    ]);

    return $result->getDataUri();
  }

  private function getErrorCorrectionLevel(string $level): ErrorCorrectionLevel
  {
    return match (strtolower(trim($level))) {
      'low', 'l' => ErrorCorrectionLevel::Low,
      'quartile', 'q' => ErrorCorrectionLevel::Quartile,
      'high', 'h' => ErrorCorrectionLevel::High,
      default => ErrorCorrectionLevel::Medium,
    };
  }

  private function getColor(string $color): Color
  {
    $color = trim($color);
    if (!preg_match('/^#?([0-9a-f]{6})$/i', $color, $matches)) {
      return new Color(0, 0, 0);
    }

    $hex = $matches[1];
    return new Color(
      hexdec(substr($hex, 0, 2)),
      hexdec(substr($hex, 2, 2)),
      hexdec(substr($hex, 4, 2))
    );
  }
}
