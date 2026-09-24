<?php

namespace giantbits\crelish\components\shortlinks;

use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\shortlinks\qr\QrLogo;
use giantbits\crelish\components\shortlinks\qr\QrMatrix;
use giantbits\crelish\components\shortlinks\qr\QrRenderer;
use giantbits\crelish\models\ShortLink;
use RuntimeException;
use Yii;
use ZipArchive;

/**
 * Builds the print bundle for a short link: plain and (if a logo is set)
 * logo variants plus a README.
 */
final class QrBundleService
{
  public const PLAIN_MIN_MM = 15;
  public const LOGO_MIN_MM = 25;

  public ?string $logoError = null;

  public function __construct(private readonly ?string $logoPath)
  {
  }

  public static function forLink(ShortLink $link): self
  {
    return new self(self::logoPathFor($link));
  }

  /**
   * The link's own logo asset if set, else the site-wide logo from config
   *
   * @param callable(string): ?string|null $assetUrl resolves an asset uuid to its site-relative URL
   */
  public static function logoPathFor(ShortLink $link, ?callable $assetUrl = null): ?string
  {
    if (!empty($link->logo_asset_uuid)) {
      $url = ($assetUrl ?? [CrelishBaseHelper::class, 'getAssetUrlById'])($link->logo_asset_uuid);

      if ($url) {
        return Yii::getAlias('@webroot') . '/' . ltrim($url, '/');
      }
    }

    return ShortLinkConfig::qrLogoPath();
  }

  /**
   * @return string[] downloadable file names, without README
   */
  public static function fileNames(ShortLink $link, bool $withLogo): array
  {
    $names = [];

    foreach (['svg', 'eps', 'pdf', 'png'] as $extension) {
      $names[] = "plain/{$link->code}.{$extension}";
    }

    if ($withLogo) {
      foreach (['svg', 'pdf', 'png'] as $extension) {
        $names[] = "logo/{$link->code}.{$extension}";
      }
    }

    return $names;
  }

  public function renderer(ShortLink $link, string $variant): QrRenderer
  {
    $payload = $link->getQrPayload();

    if ($variant !== 'logo') {
      return new QrRenderer(QrMatrix::encode($payload, 'M'), (float)$link->qr_size_mm, (int)$link->qr_quiet_zone, (string)$link->qr_color);
    }

    if ($this->logoPath === null) {
      throw new RuntimeException('No QR logo is configured.');
    }

    return new QrRenderer(QrMatrix::encode($payload, 'H'), (float)$link->qr_size_mm, (int)$link->qr_quiet_zone, (string)$link->qr_color, QrLogo::fromFile($this->logoPath));
  }

  /**
   * @return array<string,string> ZIP entry name => content
   */
  public function files(ShortLink $link): array
  {
    $this->logoError = null;
    $code = $link->code;

    $plain = $this->renderer($link, 'plain');
    $files = [
      "plain/{$code}.svg" => $plain->svg(),
      "plain/{$code}.eps" => $plain->eps(),
      "plain/{$code}.pdf" => $plain->pdf(),
      "plain/{$code}.png" => $plain->png(),
    ];

    if ($this->logoPath !== null) {
      try {
        $withLogo = $this->renderer($link, 'logo');
        $files["logo/{$code}.svg"] = $withLogo->svg();
        $files["logo/{$code}.pdf"] = $withLogo->pdf();
        $files["logo/{$code}.png"] = $withLogo->png();
      } catch (RuntimeException $e) {
        $this->logoError = $e->getMessage();
        Yii::warning("QR logo for short link '{$code}' skipped: " . $e->getMessage(), 'shortlink');
      }
    }

    $files['README.txt'] = $this->readme($link, isset($files["logo/{$code}.png"]));

    return $files;
  }

  /**
   * @return string path of a temporary ZIP file; the caller deletes it
   */
  public function zip(ShortLink $link): string
  {
    $files = $this->files($link);
    $path = tempnam(sys_get_temp_dir(), 'crelish-qr-');
    $zip = new ZipArchive();

    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      throw new RuntimeException('Could not create the QR code ZIP file.');
    }

    foreach ($files as $name => $content) {
      $zip->addFromString($name, $content);
    }

    $zip->close();

    return $path;
  }

  private function readme(ShortLink $link, bool $hasLogo): string
  {
    $lines = [
      "QR code for: {$link->systitle}",
      'Short URL:   ' . $link->getShortUrl(),
      'QR content:  ' . $link->getQrPayload(),
      '',
      "Size: {$link->qr_size_mm} mm including the {$link->qr_quiet_zone}-module white quiet zone.",
      'Place the files as they are and do not crop the white border.',
      '',
      'plain/  SVG, EPS and PDF (vector) and PNG (at least ' . QrRenderer::PNG_MIN_DPI . ' dpi).',
      '        Readable from ' . self::PLAIN_MIN_MM . ' mm.',
    ];

    if ($hasLogo) {
      $lines[] = 'logo/   SVG, PDF (vector) and PNG with the logo in the centre.';
      $lines[] = '        Use only at ' . self::LOGO_MIN_MM . ' mm or larger; for smaller print use plain/.';
      $lines[] = '        EPS cannot embed the logo; use the PDF instead.';
    } elseif ($this->logoError !== null) {
      $lines[] = 'logo/ was not created: ' . $this->logoError;
    }

    return implode("\n", $lines) . "\n";
  }
}
