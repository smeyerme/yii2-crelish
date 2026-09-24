<?php

namespace giantbits\crelish\components\shortlinks\qr;

use Imagick;
use ImagickException;
use RuntimeException;

/**
 * A PNG, JPEG or SVG logo, flattened onto white.
 *
 * Flattening is required because FPDF cannot embed PNGs with an alpha
 * channel, and the logo sits on a white knockout anyway. SVG logos are
 * rasterised with Imagick for that flattened `png`/`file` pair (used by PDF
 * and PNG output), but the original markup is kept in `svg` so the SVG
 * export can embed it as a vector instead.
 */
final class QrLogo
{
  /** Longer side, in pixels, an SVG logo is rasterised to */
  private const SVG_RASTER_SIZE = 1200;

  private function __construct(
    public readonly string $file,
    public readonly string $png,
    public readonly int $width,
    public readonly int $height,
    public readonly ?string $svg = null,
  ) {
  }

  public static function fromFile(string $path): self
  {
    $info = is_file($path) ? @getimagesize($path) : false;

    // getimagesize() also recognises SVG on newer PHP versions (IMAGETYPE_SVG);
    // that case is handled below, alongside PHP versions where it returns false.
    if ($info !== false && $info[2] === IMAGETYPE_PNG) {
      return self::fromRaster($path, $info, fn($path) => @imagecreatefrompng($path));
    }

    if ($info !== false && $info[2] === IMAGETYPE_JPEG) {
      return self::fromRaster($path, $info, fn($path) => @imagecreatefromjpeg($path));
    }

    if (!is_file($path) || !is_readable($path)) {
      throw new RuntimeException(sprintf('Logo file "%s" is missing or not an image.', basename($path)));
    }

    $content = (string)file_get_contents($path);

    if (!preg_match('/<svg\b/i', $content)) {
      throw new RuntimeException('The QR logo must be a PNG, JPEG or SVG file.');
    }

    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
      throw new RuntimeException('SVG logos with DOCTYPE/ENTITY declarations are not supported.');
    }

    if (self::hasExternalReference($content)) {
      throw new RuntimeException('SVG logos must not reference external files or URLs.');
    }

    return self::fromSvg($content);
  }

  /**
   * True if any href/xlink:href attribute points somewhere other than an
   * in-document fragment (#…) or a data: URI, e.g. <image>/<use> pulling in
   * a local file or a remote URL when Imagick parses the SVG.
   */
  private static function hasExternalReference(string $svg): bool
  {
    if (!preg_match_all('/(?:xlink:href|href)\s*=\s*(["\'])(.*?)\1/i', $svg, $matches)) {
      return false;
    }

    foreach ($matches[2] as $value) {
      $value = trim($value);

      if ($value === '' || str_starts_with($value, '#') || preg_match('/^data:/i', $value)) {
        continue;
      }

      return true;
    }

    return false;
  }

  private static function fromRaster(string $path, array $info, callable $loader): self
  {
    $source = $loader($path);

    if ($source === false) {
      throw new RuntimeException(sprintf('Logo file "%s" could not be read.', basename($path)));
    }

    [$width, $height] = [$info[0], $info[1]];
    [$file, $png] = self::flatten($source, $width, $height);

    return new self($file, $png, $width, $height);
  }

  private static function fromSvg(string $svg): self
  {
    if (!extension_loaded('imagick') || Imagick::queryFormats('SVG') === []) {
      throw new RuntimeException('SVG logos need the PHP Imagick extension with SVG support.');
    }

    try {
      $image = new Imagick();
      $image->setBackgroundColor('white');
      $image->setResolution(600, 600);
      $image->readImageBlob($svg);

      $flattened = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
      $image->destroy();
      $image = $flattened;
      $image->setImageFormat('png');

      $scale = self::SVG_RASTER_SIZE / max($image->getImageWidth(), $image->getImageHeight());
      $image->scaleImage((int)round($image->getImageWidth() * $scale), (int)round($image->getImageHeight() * $scale));

      $rasterised = $image->getImageBlob();
      $image->destroy();
    } catch (ImagickException $e) {
      throw new RuntimeException('The SVG logo could not be rasterised: ' . $e->getMessage());
    }

    // Imagick's PNG output is not guaranteed to be 8-bit truecolor without
    // alpha (FPDF cannot embed anything else), so run it through the same
    // GD white-canvas flattening as PNG/JPEG logos.
    $source = imagecreatefromstring($rasterised);

    if ($source === false) {
      throw new RuntimeException('The rasterised SVG logo could not be read.');
    }

    $width = imagesx($source);
    $height = imagesy($source);
    [$file, $png] = self::flatten($source, $width, $height);

    return new self($file, $png, $width, $height, $svg);
  }

  /**
   * @return array{0:string,1:string} [temp file path, PNG bytes], both 8-bit truecolor without alpha
   */
  private static function flatten($source, int $width, int $height): array
  {
    $canvas = imagecreatetruecolor($width, $height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

    ob_start();
    imagepng($canvas);
    $png = (string)ob_get_clean();

    $file = tempnam(sys_get_temp_dir(), 'crelish-qr-logo-');
    file_put_contents($file, $png);

    return [$file, $png];
  }

  public function __destruct()
  {
    if (is_file($this->file)) {
      @unlink($this->file);
    }
  }
}
