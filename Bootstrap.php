<?php
namespace giantbits\crelish;

use giantbits\crelish\components\CrelishI18nEventHandler;
use \yii\base\BootstrapInterface;

class Bootstrap implements BootstrapInterface
{
  /** @param \yii\web\Application $app */
  public function bootstrap($app)
  {

    if ($app instanceof \yii\web\Application) {
      /**
       * Adding i18n component here.
       */
      \Yii::$app->setComponents([
        'i18n' => [
          'class' => 'yii\i18n\I18N',
          'translations' => [
            'app*' => [
              'class' => 'yii\i18n\PhpMessageSource',
              'basePath' => '@app/messages',
              'sourceLanguage' => 'en-US',
              'fileMap' => [
                'app' => 'app.php',
                'app/error' => 'error.php',
              ],
              'on missingTranslation' => [CrelishI18nEventHandler::class, 'handleMissingTranslation']
            ],
          ],
        ]
      ]);

      $app->getUrlManager()->addRules([
        ['class' => 'yii\web\UrlRule', 'pattern' => 'crelish/<controller:[\w\-]+>/<action:[\w\-]+>', 'route' => 'crelish/<controller>/<action>'],
        ['class' => 'yii\web\UrlRule', 'pattern' => 'crelish/<id:\w+>', 'route' => 'crelish/default/view'],
        ['class' => 'yii\web\UrlRule', 'pattern' => 'crelish', 'route' => 'crelish/default/index'],
        ['class' => 'yii\web\UrlRule', 'pattern' => '<controller:[\w\-]+>/<action:[\w\-]+>', 'route' => '/<controller>/<action>'],
        ['class' => 'giantbits\crelish\components\CrelishBaseUrlRule']
        //['class' => 'yii\web\UrlRule', 'pattern' => '<lang:[\w\-]+]>/<controller:[\w\-]+>/<action:[\w\-]+>', 'route' => '/<controller>/<action>']
      ], TRUE);
    }

    \Yii::$app->setModules(['redactor' => 'yii\redactor\RedactorModule']);
  }
}