<?php

/**
 * The admin form's main fields always hold the default content language
 * (first entry of params['crelish']['languages']), whatever the admin UI
 * language; the other languages are translation tabs.
 *
 * Run with:  php tests/AdminContentLanguageTest.php
 */

declare(strict_types=1);

require __DIR__ . '/menu/bootstrap.php';

use giantbits\crelish\components\CrelishBaseController;

$app = menuApp(['languages' => ['de', 'en']]);

// An admin controller without the cookie/session setup of CrelishBaseController::init()
$controller = new class('content', $app) extends CrelishBaseController {
    public function init(): void
    {
    }
};
$call = static function (string $method, ...$args) use ($controller) {
    $reflection = new ReflectionMethod(CrelishBaseController::class, $method);

    return $reflection->invoke($controller, ...$args);
};

echo "Admin UI in English, content languages [de, en]\n";
Yii::$app->language = 'en';
check('default content language is de', 'de', CrelishBaseController::defaultContentLanguage());
check('de is the main field, not a translation', false, $call('isTranslation', 'de'));
check('en is a translation tab', true, $call('isTranslation', 'en'));
check('no language is the main field', false, $call('isTranslation', null));
check('main field key for de', 'systitle', $call('getFieldKey', (object)['key' => 'systitle'], 'de'));
check('translation field key for en', 'i18n[en][systitle]', $call('getFieldKey', (object)['key' => 'systitle'], 'en'));
$selector = $call('renderLanguageSelector');
check('selector preselects DE', 1, preg_match('/<option value="de" selected>DE<\/option>/', $selector));
check('selector does not preselect EN', 0, preg_match('/<option value="en" selected>/', $selector));

echo "\nAdmin UI in German\n";
Yii::$app->language = 'de';
check('still de', 'de', CrelishBaseController::defaultContentLanguage());
check('en still a translation tab', true, $call('isTranslation', 'en'));

echo "\nNo language list configured\n";
Yii::$app->params['crelish']['languages'] = [];
Yii::$app->language = 'en-US';
check('falls back to the two-letter app language', 'en', CrelishBaseController::defaultContentLanguage());
Yii::$app->language = 'de';

shortLinkDone();
