<?php

namespace giantbits\crelish\components\shortlinks\qr;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use InvalidArgumentException;

/**
 * The module grid of a QR code, without quiet zone.
 */
final class QrMatrix
{
  /**
   * @param bool[][] $rows [y][x] => dark
   */
  private function __construct(
    public readonly int $size,
    private readonly array $rows,
  ) {
  }

  public static function encode(string $data, string $errorCorrection): self
  {
    $level = match ($errorCorrection) {
      'M' => ErrorCorrectionLevel::M(),
      'H' => ErrorCorrectionLevel::H(),
      default => throw new InvalidArgumentException("Unsupported error correction level {$errorCorrection}"),
    };

    $matrix = Encoder::encode($data, $level)->getMatrix();
    $size = $matrix->getWidth();
    $rows = [];

    for ($y = 0; $y < $size; $y++) {
      for ($x = 0; $x < $size; $x++) {
        $rows[$y][$x] = $matrix->get($x, $y) === 1;
      }
    }

    return new self($size, $rows);
  }

  public function isDark(int $x, int $y): bool
  {
    return $this->rows[$y][$x];
  }
}
