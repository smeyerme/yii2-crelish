<?php

/**
 * raw() hands list widgets an ArrayDataProvider over all records again, as the old
 * CrelishDataProvider did; templates and the datalist plugin still call it.
 *
 * Run with:  php tests/DataProviderRawTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishDataManager;
use yii\data\ArrayDataProvider;

new \yii\web\Application([
    'id' => 'raw-test',
    'basePath' => __DIR__,
    'components' => ['request' => ['cookieValidationKey' => 'x', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php']],
]);

$manager = new class extends CrelishDataManager {
    public function __construct()
    {
    }

    public function rawAll(bool $withRelations = false): array
    {
        return [['uuid' => 'a', 'systitle' => 'Eins'], ['uuid' => 'b', 'systitle' => 'Zwei']];
    }
};

$provider = $manager->raw();
check('raw() returns an ArrayDataProvider', true, $provider instanceof ArrayDataProvider);
check('all records', 2, $provider->getTotalCount());
check('keyed by uuid', ['a', 'b'], $provider->getKeys());
check('default page size', 30, $provider->getPagination()->pageSize);

shortLinkDone();
