<?php

/**
 * Glide answers 404 when the path is not an image file. A folder path such as
 * /uploads/thumbs/ (PDF asset without a thumbnail) used to reach Glide and end
 * in a NotSupportedException (FHB-WISSEN-1K).
 *
 * Run with:  php tests/GlideMissingImageTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\actions\GlideAction;

$base = sys_get_temp_dir() . '/glide-test-' . bin2hex(random_bytes(4));
mkdir($base . '/web/uploads/thumbs', 0755, true);
mkdir($base . '/runtime', 0755, true);

$app = new \yii\web\Application([
    'id' => 'glide-test',
    'basePath' => $base,
    'runtimePath' => $base . '/runtime',
    'params' => ['crelish' => ['glide_presets' => []]],
    'components' => ['request' => ['cookieValidationKey' => 'x', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php']],
]);

$run = function (string $path) use ($app) {
    $app->set('response', new \yii\web\Response());
    $app->request->setQueryParams(['path' => $path]);
    $action = new GlideAction('glide', new \yii\web\Controller('asset', $app));
    try {
        return $action->run()->statusCode;
    } catch (\Throwable $e) {
        return get_class($e) . ": " . $e->getMessage();
    }
};

check('folder path answers 404', 404, $run('/uploads/thumbs/'));
check('missing file answers 404', 404, $run('/uploads/thumbs/gone.jpg'));
check('empty path answers 404', 404, $run(''));

rmdir($base . '/web/uploads/thumbs');
rmdir($base . '/web/uploads');
rmdir($base . '/web');
exec('rm -rf ' . escapeshellarg($base));

shortLinkDone();
