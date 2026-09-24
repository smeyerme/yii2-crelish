<?php

namespace giantbits\crelish\components\shortlinks\qr;

use LogicException;

/**
 * Draws a QR matrix at an exact physical size as SVG, EPS, PDF and PNG.
 *
 * Geometry is in module units: the code is $matrix->size modules wide plus
 * $quietZone light modules on each side. The size in millimetres covers the
 * whole image including the quiet zone, so the file can be placed as-is.
 */
final class QrRenderer
{
  public const PNG_MIN_DPI = 300;

  /** Share of the code width (without quiet zone) the logo knockout may take */
  public const LOGO_SHARE = 0.22;

  /** @var array{0:int,1:int,2:int,3:int}|null [x, y, width, height] in code modules */
  private ?array $knockout = null;

  public function __construct(
    private readonly QrMatrix $matrix,
    private readonly float $sizeMm,
    private readonly int $quietZone,
    private readonly string $color,
    private readonly ?QrLogo $logo = null,
  ) {
    if ($logo !== null) {
      $this->knockout = $this->computeKnockout($logo);
    }
  }

  /**
   * @return array{0:int,1:int,2:int,3:int}|null
   */
  public function knockout(): ?array
  {
    return $this->knockout;
  }

  /**
   * Horizontal runs of dark modules outside the knockout
   *
   * @return array<array{0:int,1:int,2:int}> [x, y, length] in code modules
   */
  public function darkRuns(): array
  {
    $runs = [];
    $size = $this->matrix->size;

    for ($y = 0; $y < $size; $y++) {
      $start = null;

      for ($x = 0; $x <= $size; $x++) {
        $dark = $x < $size && $this->matrix->isDark($x, $y) && !$this->inKnockout($x, $y);

        if ($dark && $start === null) {
          $start = $x;
        } elseif (!$dark && $start !== null) {
          $runs[] = [$start, $y, $x - $start];
          $start = null;
        }
      }
    }

    return $runs;
  }

  public function svg(): string
  {
    $total = $this->totalModules();
    $quiet = $this->quietZone;
    $path = '';

    foreach ($this->darkRuns() as [$x, $y, $length]) {
      $path .= sprintf('M%d %dh%dv1h-%dz', $x + $quiet, $y + $quiet, $length, $length);
    }

    $size = self::number($this->sizeMm);
    $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
      . sprintf('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%smm" height="%smm" viewBox="0 0 %d %d" shape-rendering="crispEdges">', $size, $size, $total, $total) . "\n"
      . sprintf('<rect width="%d" height="%d" fill="#ffffff"/>', $total, $total) . "\n"
      . sprintf('<path fill="%s" d="%s"/>', $this->color, $path) . "\n";

    if ($this->logo !== null) {
      [$x, $y, $width, $height] = $this->logoBox();
      $svg .= sprintf(
        '<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="xMidYMid meet" xlink:href="data:image/png;base64,%s"/>' . "\n",
        self::number($x + $quiet), self::number($y + $quiet), self::number($width), self::number($height), base64_encode($this->logo->png)
      );
    }

    return $svg . "</svg>\n";
  }

  public function eps(): string
  {
    if ($this->logo !== null) {
      throw new LogicException('EPS output cannot embed a logo; use the PDF.');
    }

    $total = $this->totalModules();
    $points = $this->sizeMm / 25.4 * 72;
    $scale = self::number($points / $total);
    [$red, $green, $blue] = self::rgb($this->color);

    $lines = [
      '%!PS-Adobe-3.0 EPSF-3.0',
      '%%BoundingBox: 0 0 ' . (int)ceil($points) . ' ' . (int)ceil($points),
      '%%HiResBoundingBox: 0 0 ' . self::number($points) . ' ' . self::number($points),
      '%%Creator: crelish short links',
      '%%EndComments',
      'gsave',
      "{$scale} {$scale} scale",
      '1 1 1 setrgbcolor',
      "0 0 {$total} {$total} rectfill",
      sprintf('%s %s %s setrgbcolor', self::number($red / 255), self::number($green / 255), self::number($blue / 255)),
      'newpath',
    ];

    foreach ($this->darkRuns() as [$x, $y, $length]) {
      $left = $x + $this->quietZone;
      $bottom = $total - ($y + $this->quietZone) - 1;
      $lines[] = "{$left} {$bottom} moveto {$length} 0 rlineto 0 1 rlineto -{$length} 0 rlineto closepath";
    }

    array_push($lines, 'fill', 'grestore', 'showpage', '%%EOF');

    return implode("\n", $lines) . "\n";
  }

  public function pdf(): string
  {
    $module = $this->moduleMm();
    $quiet = $this->quietZone;

    $pdf = new QrPdf('P', 'mm', [$this->sizeMm, $this->sizeMm]);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetCreator('crelish short links');
    $pdf->AddPage();
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(0, 0, $this->sizeMm, $this->sizeMm, 'F');

    [$red, $green, $blue] = self::rgb($this->color);
    $pdf->SetFillColor($red, $green, $blue);
    $pdf->fillRects(array_map(
      static fn(array $run) => [($run[0] + $quiet) * $module, ($run[1] + $quiet) * $module, $run[2] * $module, $module],
      $this->darkRuns()
    ));

    if ($this->logo !== null) {
      [$x, $y, $width, $height] = $this->logoBox();
      $pdf->Image($this->logo->file, ($x + $quiet) * $module, ($y + $quiet) * $module, $width * $module, $height * $module, 'PNG');
    }

    return $pdf->Output('S');
  }

  public function png(): string
  {
    $total = $this->totalModules();
    $modulePx = (int)ceil($this->sizeMm / 25.4 * self::PNG_MIN_DPI / $total);
    $pixels = $modulePx * $total;
    $dpi = (int)round($pixels / ($this->sizeMm / 25.4));
    $quiet = $this->quietZone;

    $image = imagecreatetruecolor($pixels, $pixels);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    [$red, $green, $blue] = self::rgb($this->color);
    $foreground = imagecolorallocate($image, $red, $green, $blue);

    foreach ($this->darkRuns() as [$x, $y, $length]) {
      imagefilledrectangle(
        $image,
        ($x + $quiet) * $modulePx,
        ($y + $quiet) * $modulePx,
        ($x + $quiet + $length) * $modulePx - 1,
        ($y + $quiet + 1) * $modulePx - 1,
        $foreground
      );
    }

    if ($this->logo !== null) {
      [$x, $y, $width, $height] = $this->logoBox();
      $source = imagecreatefromstring($this->logo->png);
      imagecopyresampled(
        $image, $source,
        (int)round(($x + $quiet) * $modulePx), (int)round(($y + $quiet) * $modulePx), 0, 0,
        (int)round($width * $modulePx), (int)round($height * $modulePx), $this->logo->width, $this->logo->height
      );
    }

    imageresolution($image, $dpi, $dpi);
    ob_start();
    imagepng($image);

    return (string)ob_get_clean();
  }

  private function totalModules(): int
  {
    return $this->matrix->size + 2 * $this->quietZone;
  }

  private function moduleMm(): float
  {
    return $this->sizeMm / $this->totalModules();
  }

  /**
   * @return array{0:int,1:int,2:int,3:int}
   */
  private function computeKnockout(QrLogo $logo): array
  {
    $size = $this->matrix->size;
    $width = self::matchParity(max(3, (int)round($size * self::LOGO_SHARE)), $size);
    $height = self::matchParity(max(3, (int)ceil(($width - 1) * $logo->height / $logo->width) + 1), $size);
    $height = min($height, $width);

    return [intdiv($size - $width, 2), intdiv($size - $height, 2), $width, $height];
  }

  /**
   * Logo placement inside the knockout, keeping half a module of white around it
   *
   * @return array{0:float,1:float,2:float,3:float} [x, y, width, height] in code modules
   */
  private function logoBox(): array
  {
    [$kx, $ky, $kw, $kh] = $this->knockout;
    $scale = min(($kw - 1) / $this->logo->width, ($kh - 1) / $this->logo->height);
    $width = $this->logo->width * $scale;
    $height = $this->logo->height * $scale;

    return [$kx + ($kw - $width) / 2, $ky + ($kh - $height) / 2, $width, $height];
  }

  private function inKnockout(int $x, int $y): bool
  {
    if ($this->knockout === null) {
      return false;
    }

    [$kx, $ky, $kw, $kh] = $this->knockout;

    return $x >= $kx && $x < $kx + $kw && $y >= $ky && $y < $ky + $kh;
  }

  /**
   * Same parity as the code size keeps the knockout centred on the module grid
   */
  private static function matchParity(int $value, int $size): int
  {
    return ($size - $value) % 2 === 0 ? $value : $value + 1;
  }

  /**
   * @return array{0:int,1:int,2:int}
   */
  private static function rgb(string $hex): array
  {
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
  }

  private static function number(float $value): string
  {
    return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
  }
}
