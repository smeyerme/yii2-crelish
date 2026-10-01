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
use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\CrelishTranslationService;

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

echo "\nAuto-translate source is the default content language\n";
Yii::$app->language = 'en';
Yii::$app->sourceLanguage = 'en-US';
Yii::$app->params['crelish']['enable_autotranslation'] = true;
$_ENV['DEEPL_API_KEY'] = 'test-key';
Yii::$app->view->js = [];
$selector = $call('renderLanguageSelector');
check('button source is de', 1, preg_match('/data-source-language="de"/', $selector));
check('button label names DE', 1, preg_match('/from DE to selected language/', $selector));
$js = implode("\n", array_merge(...array_values(array_map('array_values', Yii::$app->view->js))));
check('script source is de', 1, preg_match("/var sourceLanguage = 'de';/", $js));
check('service offers en', true, CrelishTranslationService::shouldOfferTranslation('en'));
check('service does not offer de', false, CrelishTranslationService::shouldOfferTranslation('de'));
check('service does not offer de-CH', false, CrelishTranslationService::shouldOfferTranslation('de-CH'));
$sourceProp = new ReflectionProperty(CrelishTranslationService::class, 'sourceLanguage');
check('service translates from de by default', 'de', $sourceProp->getValue(new CrelishTranslationService()));
check('explicit source still wins', 'en', $sourceProp->getValue(new CrelishTranslationService('en')));
unset(Yii::$app->params['crelish']['enable_autotranslation'], $_ENV['DEEPL_API_KEY']);
Yii::$app->sourceLanguage = 'en-US';
Yii::$app->language = 'de';

echo "\nTwo-letter comparison with the default content language\n";
Yii::$app->params['crelish']['languages'] = ['de-CH', 'en'];
check('de is the default content language of [de-CH, en]', true, CrelishBaseHelper::isDefaultContentLanguage('de'));
check('de-CH is too', true, CrelishBaseHelper::isDefaultContentLanguage('de-CH'));
check('de_AT is too', true, CrelishBaseHelper::isDefaultContentLanguage('de_AT'));
check('en is not', false, CrelishBaseHelper::isDefaultContentLanguage('en'));
check('empty is not', false, CrelishBaseHelper::isDefaultContentLanguage(''));
check('admin: de is not a translation with [de-CH, en]', false, $call('isTranslation', 'de'));
check('admin: de-CH is not a translation', false, $call('isTranslation', 'de-CH'));
check('admin: en still is', true, $call('isTranslation', 'en'));
Yii::$app->params['crelish']['languages'] = ['de', 'en'];
check('de-CH is the default content language of [de, en]', true, CrelishBaseHelper::isDefaultContentLanguage('de-CH'));
Yii::$app->params['crelish']['languages'] = [];
check('no language list: nothing is the default', false, CrelishBaseHelper::isDefaultContentLanguage('de'));
Yii::$app->params['crelish']['languages'] = ['de', 'en'];

echo "\nNo language list configured\n";
Yii::$app->params['crelish']['languages'] = [];
Yii::$app->language = 'en-US';
check('falls back to the two-letter app language', 'en', CrelishBaseController::defaultContentLanguage());
Yii::$app->language = 'de';

shortLinkDone();
