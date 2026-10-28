<?php

/**
 * The asset form shows the copyright field only when the site's asset element defines it.
 * Sites whose asset.json has no copyright key could not open any asset for editing:
 * "Getting unknown property: CrelishDynamicModel::copyright" (FORUM-HOLZBAU-4R).
 *
 * Run with:  php tests/AssetUpdateCopyrightTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\config\ComponentsConfig;
use yii\base\DynamicModel;

$assets = sys_get_temp_dir() . '/crelish-asset-form-test';
@mkdir($assets);

shortLinkApp([], [], [
    'components' => [
        'assetManager' => ['basePath' => $assets, 'baseUrl' => '/assets', 'bundles' => false],
        'view' => [
            'renderers' => [
                'twig' => array_merge(ComponentsConfig::getConfig()['view']['renderers']['twig'], ['cachePath' => false]),
            ],
        ],
    ],
]);

$fields = ['uuid', 'ctype', 'systitle', 'state', 'src', 'src_remote', 'description', 'mime', 'pathName', 'fileName', 'thumbnail'];

function renderAssetForm(DynamicModel $model): string
{
    return Yii::$app->view->renderFile(dirname(__DIR__) . '/views/asset/update.twig', [
        'model' => $model,
        'extractModel' => null,
        'colormain_hex' => null,
        'colorpalette' => null,
        'alerts' => '',
        'showImageEditor' => false,
    ]);
}

$without = new DynamicModel(array_fill_keys($fields, null));
$without->mime = 'text/plain';
$without->systitle = 'Ohne Copyright';
$error = null;
try {
    $html = renderAssetForm($without);
} catch (\Throwable $e) {
    $error = $e->getMessage();
    $html = '';
}
check('renders without a copyright attribute', null, $error);
check('the other fields are there', true, str_contains($html, 'name="DynamicModel[description]"'));
check('no copyright field', false, str_contains($html, '[copyright]'));

$with = new DynamicModel(array_fill_keys([...$fields, 'copyright'], null));
$with->mime = 'text/plain';
$with->copyright = '© Forum Holz';
$html = renderAssetForm($with);
check('copyright field shown when defined', true, str_contains($html, 'name="DynamicModel[copyright]"'));
check('with its value', true, str_contains($html, 'value="© Forum Holz"'));

shortLinkDone();
