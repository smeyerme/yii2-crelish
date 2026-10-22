<?php

/**
 * PDF thumbnails are rendered from the file where the asset says it lives
 * (pathName + fileName). Older assets sit in month folders such as
 * /uploads/2022-10/, so looking only in /uploads/ never found them (FORUM-HOLZKARRIERE-4B).
 *
 * Run with:  php tests/PdfThumbnailSourceTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\controllers\AssetController;

$web = sys_get_temp_dir() . '/pdf-source-test-' . bin2hex(random_bytes(4));
mkdir($web . '/uploads/2022-10', 0755, true);
touch($web . '/uploads/2022-10/old file.pdf');
touch($web . '/uploads/new.pdf');

$failures = 0;
$check = function (string $label, $expected, $actual) use (&$failures) {
    if ($expected === $actual) {
        echo "ok   $label\n";
    } else {
        $failures++;
        echo "FAIL $label\n     expected: " . var_export($expected, true) . "\n     actual:   " . var_export($actual, true) . "\n";
    }
};

$asset = fn(array $a) => (object)array_merge(['src' => null, 'fileName' => null, 'pathName' => null], $a);

$check('asset in a month folder',
    $web . '/uploads/2022-10/old file.pdf',
    AssetController::pdfSourcePath($asset(['src' => 'old file.pdf', 'fileName' => 'old file.pdf', 'pathName' => '/uploads/2022-10/']), $web));

$check('asset directly in uploads',
    $web . '/uploads/new.pdf',
    AssetController::pdfSourcePath($asset(['src' => 'new.pdf', 'fileName' => 'new.pdf', 'pathName' => '/uploads/']), $web));

$check('pathName without slashes',
    $web . '/uploads/2022-10/old file.pdf',
    AssetController::pdfSourcePath($asset(['src' => 'old file.pdf', 'fileName' => 'old file.pdf', 'pathName' => 'uploads/2022-10']), $web));

$check('no pathName falls back to uploads + src',
    $web . '/uploads/new.pdf',
    AssetController::pdfSourcePath($asset(['src' => 'new.pdf']), $web));

$check('pathName pointing elsewhere falls back to uploads + src',
    $web . '/uploads/new.pdf',
    AssetController::pdfSourcePath($asset(['src' => 'new.pdf', 'fileName' => 'new.pdf', 'pathName' => '/uploads/2019-01/']), $web));

$check('missing everywhere names the recorded location',
    $web . '/uploads/2023-05/gone.pdf',
    AssetController::pdfSourcePath($asset(['src' => 'gone.pdf', 'fileName' => 'gone.pdf', 'pathName' => '/uploads/2023-05/']), $web));

array_map('unlink', [$web . '/uploads/2022-10/old file.pdf', $web . '/uploads/new.pdf']);
rmdir($web . '/uploads/2022-10');
rmdir($web . '/uploads');
rmdir($web);

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit($failures ? 1 : 0);
