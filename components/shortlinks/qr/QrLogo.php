<?php

namespace giantbits\crelish\components\shortlinks\qr;

use RuntimeException;

/**
 * A PNG or JPEG logo, flattened onto white.
 *
 * Flattening is required because FPDF cannot embed PNGs with an alpha channel,
 * and the logo sits on a white knockout anyway.
 */
final class QrLogo
{
  private function __construct(
    public readonly string $file,
    public readonly string $png,
    public readonly int $width,
    public readonly int $height,
  ) {
  }

  public static function fromFile(string $path): self
  {
    $info = is_file($path) ? @getimagesize($path) : false;

    if ($info === false) {
      throw new RuntimeException(sprintf('Logo file "%s" is missing or not an image.', basename($path)));
    }

    $source = match ($info[2]) {
      IMAGETYPE_PNG => @imagecreatefrompng($path),
      IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
      default => throw new RuntimeException('The QR logo must be a PNG or JPEG file.'),
    };

    if ($source === false) {
      throw new RuntimeException(sprintf('Logo file "%s" could not be read.', basename($path)));
    }

    [$width, $height] = [$info[0], $info[1]];
    $canvas = imagecreatetruecolor($width, $height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

    ob_start();
    imagepng($canvas);
    $png = (string)ob_get_clean();

    $file = tempnam(sys_get_temp_dir(), 'crelish-qr-logo-');
    file_put_contents($file, $png);

    return new self($file, $png, $width, $height);
  }

  public function __destruct()
  {
    if (is_file($this->file)) {
      @unlink($this->file);
    }
  }
}
