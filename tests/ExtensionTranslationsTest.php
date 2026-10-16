<?php

/**
 * Crelish redefines the i18n component after the application booted. Extensions register
 * their message categories during that boot (yii2-bootstrap5 adds yii/bootstrap5), so the
 * redefinition dropped them: Yii::t('yii/bootstrap5', ...) went to Crelish's '*' source and
 * logged "message file ... messages/de/yii/bootstrap5.php does not exist" on admin pages.
 *
 * Run with:  php tests/ExtensionTranslationsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\config\ComponentsConfig;

function extensionTranslationsApp(): \yii\web\Application
{
    // vendor/yiisoft/extensions.php runs yii2-bootstrap5's TranslationBootstrap here
    return new \yii\web\Application([
        'id' => 'extension-translations-test',
        'basePath' => dirname(__DIR__),
        'language' => 'de',
        'components' => ['request' => ['cookieValidationKey' => 'test', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php']],
    ]);
}

function crelishI18n(): array
{
    return ComponentsConfig::getConfig()['i18n'];
}

echo "\nyii/bootstrap5 after Crelish redefined i18n\n";
$app = extensionTranslationsApp();
check('the extension registered its category', true, isset($app->i18n->translations['yii/bootstrap5']));
$app->setComponents(['i18n' => ComponentsConfig::keepTranslations(crelishI18n(), $app->getI18n()->translations)]);
check('the category is still there', true, isset($app->i18n->translations['yii/bootstrap5']));
check('its German text is used', 'Schließen', Yii::t('yii/bootstrap5', 'Close'));

echo "\norder and Crelish's own sources\n";
$merged = ComponentsConfig::keepTranslations(crelishI18n(), [
    'yii' => ['class' => 'yii\i18n\PhpMessageSource'],
    'app' => ['class' => 'yii\i18n\PhpMessageSource'],
    'crelish*' => ['class' => 'yii\i18n\PhpMessageSource', 'basePath' => '/elsewhere'],
    'yii/bootstrap5' => ['class' => 'yii\i18n\GettextMessageSource'],
]);
$keys = array_keys($merged['translations']);
check("'*' stays last", '*', end($keys));
check('carried categories come first', 'yii/bootstrap5', $keys[0]);
check("Crelish's crelish* definition wins", 'giantbits\crelish\components\CrelishMessageSource', $merged['translations']['crelish*']['class']);
check("I18N's own yii and app are not carried", [false, false], [isset($merged['translations']['yii']), isset($merged['translations']['app'])]);

shortLinkDone();
