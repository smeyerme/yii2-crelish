<?php

namespace giantbits\crelish\components\shortlinks\qr;

use FPDF;

/**
 * FPDF with a single-path rectangle fill, so adjacent modules render without
 * hairline seams in viewers and RIPs.
 */
final class QrPdf extends FPDF
{
  /**
   * @param array<array{0:float,1:float,2:float,3:float}> $rects [x, y, width, height] in user units, origin top-left
   */
  public function fillRects(array $rects): void
  {
    $operators = [];

    foreach ($rects as [$x, $y, $width, $height]) {
      $operators[] = sprintf('%.3F %.3F %.3F %.3F re', $x * $this->k, ($this->h - $y) * $this->k, $width * $this->k, -$height * $this->k);
    }

    if ($operators !== []) {
      $this->_out(implode("\n", $operators) . ' f');
    }
  }
}
