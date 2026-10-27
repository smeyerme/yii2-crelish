<?php

/**
 * kartik input widgets register the 'kvbase' category with the widget's own folder
 * (e.g. yii2-widget-colorinput/src/messages), but the kvbase files live in
 * yii2-krajee-base. Every form with such a widget logged "The message file for category
 * 'kvbase' does not exist" (FORUM-HOLZKARRIERE-47). Crelish sets the right folder;
 * kartik merges the app config over its own, so that folder wins.
 *
 * Run with:  php tests/KartikBaseTranslationsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\config\ComponentsConfig;

$app = new \yii\web\Application([
    'id' => 'kvbase-test',
    'basePath' => dirname(__DIR__),
    'vendorPath' => dirname(__DIR__) . '/vendor',
    'language' => 'de',
    'components' => ['request' => ['cookieValidationKey' => 'x', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php']],
]);
$app->setComponents(['i18n' => ComponentsConfig::getConfig()['i18n']]);

// What InputWidget::init() does for a ColorInput: point kvbase at the widget's own folder
$widget = new class {
    use \kartik\base\TranslationTrait;
    public $i18n = [];
};
$widget->initI18N(sys_get_temp_dir() . '/yii2-widget-colorinput/src', 'kvbase');

$basePath = Yii::getAlias(Yii::$app->i18n->translations['kvbase*']['basePath']);
check('kvbase points at krajee-base', true, str_ends_with($basePath, 'kartik-v/yii2-krajee-base/src/messages'));
check('the German kvbase file is there', true, is_file($basePath . '/de/kvbase.php'));

$warnings = 0;
Yii::getLogger()->flushInterval = 1;
Yii::$app->getLog()->targets['count'] = new class(['levels' => ['warning'], 'exportInterval' => 1]) extends \yii\log\Target {
    public static int $count = 0;
    public function export(): void
    {
        self::$count += count($this->messages);
    }
};
Yii::t('kvbase', 'It is recommended you use an upgraded browser to display the {type} control properly.', ['type' => 'color']);
Yii::getLogger()->flush(true);
check('no missing-file warning', 0, Yii::$app->getLog()->targets['count']::$count);

shortLinkDone();
