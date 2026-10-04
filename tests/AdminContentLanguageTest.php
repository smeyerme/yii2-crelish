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

echo "\nTranslation fields without a stored translation render empty\n";
class AdminLangTestModel extends \yii\base\Model
{
    public $i18n = [];
    public $allTranslations = null;
    public $systitle;
    public $body;
    public $other;

    public function rules()
    {
        return [[['systitle', 'body', 'other', 'i18n'], 'safe']];
    }
}
Yii::$app->params['crelish']['languages'] = ['de', 'en', 'fr'];
Yii::$app->language = 'de';
Yii::$app->assetManager->bundles = [\yii\web\JqueryAsset::class => false, \yii\widgets\ActiveFormAsset::class => false, \yii\validators\ValidationAsset::class => false];
$render = static function (AdminLangTestModel $model, object $field) use ($controller): string {
    $controller->model = $model;
    $form = new \yii\widgets\ActiveForm(['id' => 'test-form', 'enableClientScript' => false]);

    return (string)(new ReflectionMethod(CrelishBaseController::class, 'renderField'))->invoke($controller, $field, $form);
};
/** [value, placeholder] of the input/textarea named <Model>[i18n][<lang>][<key>] */
$translationInput = static function (string $html, string $lang, string $key): array {
    $name = preg_quote(htmlspecialchars("AdminLangTestModel[i18n][$lang][$key]"), '/');
    if (preg_match('/<textarea[^>]*name="' . $name . '"[^>]*>(.*?)<\/textarea>/s', $html, $m)) {
        preg_match('/placeholder="([^"]*)"/', $m[0], $p);
        return [html_entity_decode($m[1]), isset($p[1]) ? html_entity_decode($p[1]) : null];
    }
    if (!preg_match('/<input[^>]*name="' . $name . '"[^>]*>/', $html, $m)) {
        return ['missing', null];
    }
    preg_match('/ value="([^"]*)"/', $m[0], $v);
    preg_match('/placeholder="([^"]*)"/', $m[0], $p);
    return [isset($v[1]) ? html_entity_decode($v[1]) : '', isset($p[1]) ? html_entity_decode($p[1]) : null];
};
$model = new AdminLangTestModel();
$model->systitle = 'Willkommen';
$model->allTranslations = ['systitle' => ['en' => 'Welcome']];
$field = (object)['key' => 'systitle', 'label' => 'Title', 'type' => 'textInput', 'translatable' => true];
$html = $render($model, $field);
check('stored translation is the value', 'Welcome', $translationInput($html, 'en', 'systitle')[0]);
check('missing translation renders empty with the default as placeholder', ['', 'Willkommen'], $translationInput($html, 'fr', 'systitle'));
check('main field keeps the default value and no placeholder', 1, preg_match('/<input type="text"[^>]*name="AdminLangTestModel\[systitle\]" value="Willkommen"(?![^>]*placeholder)[^>]*>/', $html));
$model = new AdminLangTestModel();
$model->systitle = 'Willkommen';
$html = $render($model, $field);
check('no translation at all: empty with placeholder', [['', 'Willkommen'], ['', 'Willkommen']], [$translationInput($html, 'en', 'systitle'), $translationInput($html, 'fr', 'systitle')]);
$model->i18n = ['fr' => ['systitle' => 'Bienvenue']];
check('a re-rendered POST value is kept', ['Bienvenue', 'Willkommen'], $translationInput($render($model, $field), 'fr', 'systitle'));
$model = new AdminLangTestModel();
$model->body = str_repeat('Lang ', 40);
$model->allTranslations = ['body' => ['en' => 'Long']];
$html = $render($model, (object)['key' => 'body', 'label' => 'Body', 'type' => 'textarea', 'translatable' => true]);
$fr = $translationInput($html, 'fr', 'body');
check('textarea: empty value', '', $fr[0]);
check('textarea: long default truncated to 120 characters', [120, '…'], [mb_strlen((string)$fr[1]), mb_substr((string)$fr[1], -1)]);
check('textarea: stored translation is the value', 'Long', $translationInput($html, 'en', 'body')[0]);
$model = new AdminLangTestModel();
$model->other = 'b';
$model->allTranslations = ['other' => ['en' => 'a']];
$html = $render($model, (object)['key' => 'other', 'label' => 'Other', 'type' => 'dropDownList', 'items' => ['a' => 'A', 'b' => 'B'], 'translatable' => true]);
check('list field without a stored translation: the default stays preselected (identical values are not stored)', 1, preg_match('/name="AdminLangTestModel\[i18n\]\[fr\]\[other\]"[^>]*>\s*<option value="a">A<\/option>\s*<option value="b" selected>/s', $html));
check('list field: stored translation selected', 1, preg_match('/name="AdminLangTestModel\[i18n\]\[en\]\[other\]"[^>]*>\s*<option value="a" selected/s', $html));
$model = new AdminLangTestModel();
$model->body = 'Text';
$html = $render($model, (object)['key' => 'body', 'label' => 'Body', 'type' => 'textArea', 'translatable' => true]);
check('type textArea (workspace spelling): empty with placeholder', ['', 'Text'], $translationInput($html, 'fr', 'body'));
$html = $render($model, (object)['key' => 'body', 'label' => 'Body', 'type' => 'textInput', 'translatable' => true, 'options' => ['placeholder' => 'Eigener Hinweis']]);
check('a placeholder configured on the field is kept', ['', 'Eigener Hinweis'], $translationInput($html, 'fr', 'body'));
Yii::$app->params['crelish']['languages'] = ['de', 'en'];

echo "\nSingle-language install: plain field unchanged\n";
Yii::$app->params['crelish']['languages'] = ['de'];
$model = new AdminLangTestModel();
$model->systitle = 'Willkommen';
$html = $render($model, (object)['key' => 'systitle', 'label' => 'Title', 'type' => 'textInput']);
check('value, no placeholder', 1, preg_match('/name="AdminLangTestModel\[systitle\]" value="Willkommen"(?![^>]*placeholder)/', $html));
check('translatable field renders nothing (as before)', '', $render($model, (object)['key' => 'systitle', 'label' => 'Title', 'type' => 'textInput', 'translatable' => true]));
Yii::$app->params['crelish']['languages'] = ['de', 'en'];

echo "\nNo language list configured\n";
Yii::$app->params['crelish']['languages'] = [];
Yii::$app->language = 'en-US';
check('falls back to the two-letter app language', 'en', CrelishBaseController::defaultContentLanguage());
Yii::$app->language = 'de';

shortLinkDone();
