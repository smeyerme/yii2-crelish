<?php

/**
 * QR bundle: file set with and without logo, README, ZIP, logo lookup.
 *
 * Run with:  php tests/QrBundleTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\shortlinks\QrBundleService;
use giantbits\crelish\components\shortlinks\qr\QrLogo;
use giantbits\crelish\components\shortlinks\qr\QrMatrix;
use giantbits\crelish\components\shortlinks\qr\QrRenderer;
use giantbits\crelish\models\ShortLink;

function bundleLink(array $attributes = []): ShortLink
{
    $link = new ShortLink();
    $link->setAttributes(array_merge([
        'systitle' => 'IHF 2026 Flyer', 'code' => 'ihf26', 'state' => 2,
        'target_type' => ShortLink::TARGET_URL, 'target_url' => 'https://www.example.com/ihf',
        'qr_size_mm' => 30, 'qr_color' => '#000000', 'qr_quiet_zone' => 4,
    ], $attributes), false);

    return $link;
}

function squareLogo(): string
{
    $image = imagecreatetruecolor(300, 300);
    imagefill($image, 0, 0, imagecolorallocate($image, 47, 111, 79));
    $path = tempnam(sys_get_temp_dir(), 'qr-bundle-logo-') . '.png';
    imagepng($image, $path);

    return $path;
}

function svgLogo(): string
{
    $path = tempnam(sys_get_temp_dir(), 'qr-bundle-logo-') . '.svg';
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100"><rect x="10" y="10" width="180" height="80" fill="#2f6f4f"/></svg>');

    return $path;
}

shortLinkApp();
$plainNames = ['plain/ihf26.svg', 'plain/ihf26.eps', 'plain/ihf26.pdf', 'plain/ihf26.png', 'README.txt'];
$logoNames = ['logo/ihf26.svg', 'logo/ihf26.pdf', 'logo/ihf26.png'];

echo "Without logo\n";
$service = new QrBundleService(null);
$files = $service->files(bundleLink());
check('plain files and README only', $plainNames, array_keys($files));
check('README names the short URL', true, str_contains($files['README.txt'], 'https://forum-holzbau.test/go/ihf26'));
check('README names the QR content', true, str_contains($files['README.txt'], 'HTTPS://FORUM-HOLZBAU.TEST/GO/IHF26/Q'));
check('README states the size', true, str_contains($files['README.txt'], '30 mm'));
check('fileNames matches the plain bundle', array_slice($plainNames, 0, 4), QrBundleService::fileNames(bundleLink(), false));

echo "\nWith logo\n";
$service = new QrBundleService(squareLogo());
$files = $service->files(bundleLink());
check('logo variants are added', array_merge(array_slice($plainNames, 0, 4), $logoNames, ['README.txt']), array_keys($files));
check('no EPS with logo', false, isset($files['logo/ihf26.eps']));
check('no logo error', null, $service->logoError);
check('README explains the logo minimum size', true, str_contains($files['README.txt'], '25 mm'));
check('fileNames matches the logo bundle', array_merge(array_slice($plainNames, 0, 4), $logoNames), QrBundleService::fileNames(bundleLink(), true));

$zipPath = $service->zip(bundleLink());
$zip = new ZipArchive();
$zip->open($zipPath);
check('zip contains all eight files', 8, $zip->numFiles);
$zip->close();
unlink($zipPath);

echo "\nBroken logo\n";
$service = new QrBundleService('/nonexistent/logo.png');
$files = $service->files(bundleLink());
check('broken logo falls back to plain only', $plainNames, array_keys($files));
check('logo error is reported', true, $service->logoError !== null);
check('README says why the logo is missing', true, str_contains($files['README.txt'], 'logo/ was not created'));
try {
    $service->renderer(bundleLink(), 'logo');
    $threw = false;
} catch (RuntimeException) {
    $threw = true;
}
check('logo preview without a usable logo throws', true, $threw);
check('logoProblem reports the same message', $service->logoError, $service->logoProblem());

echo "\nSVG logo\n";
$service = new QrBundleService(svgLogo());
$files = $service->files(bundleLink());
check('svg logo variants are added', array_merge(array_slice($plainNames, 0, 4), $logoNames, ['README.txt']), array_keys($files));
check('no logo error', null, $service->logoError);
check('logoProblem is null for a usable svg logo', null, $service->logoProblem());

echo "\nlogoProblem\n";
check('no logo path means no problem', null, (new QrBundleService(null))->logoProblem());
check('a usable logo means no problem', null, (new QrBundleService(squareLogo()))->logoProblem());
$brokenService = new QrBundleService('/nonexistent/logo.png');
check('an unusable logo returns the error message', true, is_string($brokenService->logoProblem()));
check('logoProblem is cached, not recomputed', $brokenService->logoProblem(), $brokenService->logoProblem());

echo "\nLogo renderer throwing a non-RuntimeException is still handled\n";
// FPDF (used by pdf()) raises a plain \Exception, not a RuntimeException, when it
// can't read the image file it's given (e.g. the temp file disappeared under it).
// This reproduces the class of bug behind the live 500: QrBundleService/logoProblem()
// used to only catch RuntimeException, so this crashed the download instead of
// falling back to the plain bundle.
$vanishingLogo = QrLogo::fromFile(squareLogo());
unlink($vanishingLogo->file);
$vanishingRenderer = new QrRenderer(QrMatrix::encode('TEST', 'H'), 30, 4, '#000000', $vanishingLogo);
try {
    $vanishingRenderer->pdf();
    $thrown = null;
} catch (\Throwable $e) {
    $thrown = $e;
}
check('fpdf throws when the logo file is missing', true, $thrown !== null);
check('...and it is not a RuntimeException', false, $thrown instanceof RuntimeException);
check('QrBundleService::files() catches \Throwable, not just RuntimeException', true,
    str_contains(file_get_contents(__DIR__ . '/../components/shortlinks/QrBundleService.php'), 'catch (\Throwable $e) {'));

echo "\nLogo lookup\n";
$webroot = sys_get_temp_dir() . '/qr-webroot-' . uniqid();
mkdir($webroot . '/uploads', 0777, true);
Yii::setAlias('@webroot', $webroot);
check('asset override is a file under webroot', $webroot . '/uploads/logo.png',
    QrBundleService::logoPathFor(bundleLink(['logo_asset_uuid' => 'asset-1']), fn(string $uuid) => $uuid === 'asset-1' ? '/uploads/logo.png' : null));
shortLinkApp(['shortLinks' => ['qrLogo' => '/srv/logo.png']]);
check('without override the site logo is used', '/srv/logo.png', QrBundleService::logoPathFor(bundleLink(), fn() => null));
check('unknown asset falls back to the site logo', '/srv/logo.png', QrBundleService::logoPathFor(bundleLink(['logo_asset_uuid' => 'gone']), fn() => null));
shortLinkApp();
check('no logo anywhere', null, QrBundleService::logoPathFor(bundleLink(), fn() => null));

shortLinkDone();
