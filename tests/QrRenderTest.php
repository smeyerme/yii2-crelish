<?php

/**
 * QR rendering: matrix size, exact physical size in all formats, logo
 * knockout, transparent logos in PDF.
 *
 * Run with:  php tests/QrRenderTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\shortlinks\qr\QrLogo;
use giantbits\crelish\components\shortlinks\qr\QrMatrix;
use giantbits\crelish\components\shortlinks\qr\QrRenderer;

const PAYLOAD = 'HTTPS://FORUM-HOLZBAU.COM/GO/IHF26/Q';

/**
 * A 400x200 transparent PNG with an opaque green rectangle
 */
function transparentLogo(): string
{
    $image = imagecreatetruecolor(400, 200);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 50, 50, 350, 150, imagecolorallocate($image, 47, 111, 79));
    $path = tempnam(sys_get_temp_dir(), 'qr-test-logo-') . '.png';
    imagepng($image, $path);

    return $path;
}

function pngDpi(string $png): ?int
{
    $position = strpos($png, 'pHYs');
    if ($position === false) {
        return null;
    }

    return (int)round(unpack('N', substr($png, $position + 4, 4))[1] * 0.0254);
}

echo "Matrix\n";
check('uppercase payload uses a version 2 code', 25, QrMatrix::encode(PAYLOAD, 'M')->size);
check('lowercase payload needs a bigger code', 29, QrMatrix::encode(strtolower(PAYLOAD), 'M')->size);
check('level H is larger', 33, QrMatrix::encode(PAYLOAD, 'H')->size);
$matrix = QrMatrix::encode(PAYLOAD, 'M');
check('finder pattern corner is dark', true, $matrix->isDark(0, 0));
check('finder separator is light', false, $matrix->isDark(7, 0));

echo "\nSVG\n";
$plain = new QrRenderer($matrix, 30, 4, '#1a5d3a');
$svg = simplexml_load_string($plain->svg());
check('svg width is in mm', '30mm', (string)$svg['width']);
check('svg viewBox covers code and quiet zone', '0 0 33 33', (string)$svg['viewBox']);
check('svg uses the colour', '#1a5d3a', (string)$svg->path['fill']);
check('svg has a white background', '#ffffff', (string)$svg->rect['fill']);

echo "\nEPS\n";
$eps = $plain->eps();
check('eps header', true, str_starts_with($eps, "%!PS-Adobe-3.0 EPSF-3.0\n"));
check('eps bounding box in points', true, str_contains($eps, "%%BoundingBox: 0 0 86 86\n"));
check('eps hi-res bounding box', true, str_contains($eps, "%%HiResBoundingBox: 0 0 85.0394 85.0394\n"));
check('eps ends with EOF', true, str_ends_with($eps, "%%EOF\n"));

echo "\nPDF\n";
$pdf = $plain->pdf();
check('pdf header', true, str_starts_with($pdf, '%PDF-'));
check('pdf page is 30 mm', true, str_contains($pdf, '/MediaBox [0 0 85.04 85.04]'));

echo "\nPNG\n";
$png = $plain->png();
$info = getimagesizefromstring($png);
check('png is whole pixels per module at >= 300 dpi', [363, 363], [$info[0], $info[1]]);
check('png carries its dpi', 307, pngDpi($png));

echo "\nLogo\n";
$logo = QrLogo::fromFile(transparentLogo());
$withLogo = new QrRenderer(QrMatrix::encode(PAYLOAD, 'H'), 30, 4, '#000000', $logo);
[$kx, $ky, $kw, $kh] = $withLogo->knockout();
check('knockout width is about 22 % of the code', 7, $kw);
check('wide logo gets a flat knockout', true, $kh < $kw);
check('knockout is centred on module 16 of 33', [16, 16], [$kx + intdiv($kw, 2), $ky + intdiv($kh, 2)]);
$overlaps = array_filter($withLogo->darkRuns(), fn($run) => $run[1] >= $ky && $run[1] < $ky + $kh && $run[0] < $kx + $kw && $run[0] + $run[2] > $kx);
check('no dark module inside the knockout', [], array_values($overlaps));
check('svg embeds the logo', true, str_contains($withLogo->svg(), 'data:image/png;base64,'));
try {
    $logoPdf = $withLogo->pdf();
} catch (\Throwable $e) {
    $logoPdf = 'failed: ' . $e->getMessage();
}
check('transparent logo works in pdf', true, str_contains($logoPdf, '/Subtype /Image'));
check('logo png renders', true, getimagesizefromstring($withLogo->png()) !== false);
try {
    $withLogo->eps();
    $epsThrew = false;
} catch (LogicException) {
    $epsThrew = true;
}
check('eps refuses a logo', true, $epsThrew);

echo "\nLogo errors\n";
foreach (['missing file' => '/nonexistent/logo.png', 'text file' => __FILE__] as $label => $path) {
    try {
        QrLogo::fromFile($path);
        $threw = false;
    } catch (RuntimeException) {
        $threw = true;
    }
    check("$label is rejected", true, $threw);
}

echo "\nKnockout stays within the cap (every QR size)\n";
$capLogo = QrLogo::fromFile(transparentLogo());
$cursor = 0;
for ($size = 21; $size <= 177; $size += 4) {
    do {
        $cursor++;
        $probe = QrMatrix::encode(str_repeat('A', $cursor), 'H');
    } while ($probe->size < $size);
    check("size $size is reachable by encoding", $size, $probe->size);

    $renderer = new QrRenderer($probe, 30, 4, '#000000', $capLogo);
    [$kx, $ky, $kw, $kh] = $renderer->knockout();
    $violations = array_keys(array_filter([
        'width exceeds 22% of the code' => $kw > $size * QrRenderer::LOGO_SHARE + 1e-9,
        'width parity differs from the code size' => ($size - $kw) % 2 !== 0,
        'height exceeds the width' => $kh > $kw,
        'height parity differs from the code size' => ($size - $kh) % 2 !== 0,
        'knockout is not centred' => 2 * $kx + $kw !== $size || 2 * $ky + $kh !== $size,
    ]));
    check("size $size: knockout respects the cap, parity and centring", [], $violations);
}

shortLinkDone();
