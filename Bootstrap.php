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
      // Add components.
      \Yii::$app->setComponents([
        'user' => [
          'class' => 'yii\web\User',
          'identityClass' => 'giantbits\crelish\components\CrelishUser',
          'enableAutoLogin' => TRUE,
          'loginUrl' => ['crelish/user/login']
        ],
        'defaultRoute' => 'frontend/index',
        'view' => [
          'class' => 'yii\web\View',
          'renderers' => [
            'twig' => [
              'class' => 'yii\twig\ViewRenderer',
              'cachePath' => '@runtime/Twig/cache',
              //'extensions' => ['\Twig_Extension_Debug'],
              'extensions' => [
                new \Cocur\Slugify\Bridge\Twig\SlugifyExtension(\Cocur\Slugify\Slugify::create()),
                '\Twig_Extension_Debug'
              ],
              'options' => YII_ENV_DEV ? [
                'debug' => true,
                'auto_reload' => true,
              ] : [],
              'globals' => [
                'url' => ['class' => '\yii\helper\Url'],
                'html' => ['class' => '\yii\helpers\Html'],
                'chelper'=>['class' => '\giantbits\crelish\components\CrelishBaseHelper'],
                'globals'=>['class' => '\giantbits\crelish\components\CrelishGlobals'],
              ],
              'functions' => [
                't' => 'Yii::t'
              ]
            ]
          ]
        ],
        'urlManager' => [
          'class' => 'yii\web\UrlManager',
          'enablePrettyUrl' => TRUE,
          'enableStrictParsing' => TRUE,
          'showScriptName' => FALSE,
          'rules' => [
            ['class' => 'yii\rest\UrlRule', 'controller' => 'user', 'tokens' => ['{uuid}' => '<uuid:\\d[\\d,]*>']],
            ['class' => 'yii\rest\UrlRule', 'controller' => 'company', 'tokens' => ['{uuid}' => '<uuid:\\d[\\d,]*>']],
            ['class' => 'yii\rest\UrlRule', 'controller' => 'product', 'tokens' => ['{uuid}' => '<uuid:\\d[\\d,]*>']],
          ],
        ],
        'i18n' => [
          'class' => 'yii\i18n\I18N',
          'translations' => [
            'crelish*' => [
              'class' => 'yii\i18n\PhpMessageSource',
              'basePath' => '@app/messages',
              'sourceLanguage' => 'en-US',
              'fileMap' => [
                'app' => 'app.php',
                'app.error' => 'error.php',
                'crelish' => 'crelish.php'
              ],
              'on missingTranslation' => [
                CrelishI18nEventHandler::class,
                'handleMissingTranslation'
              ]
            ],
          ],
        ],
        'glide' => [
          'class' => 'trntv\glide\components\Glide',
          'sourcePath' => '@app/web/uploads',
          'cachePath' => '@runtime/glide',
          'signKey' => false, //'kluhjli7klhhk',
          'presets' => [
            'tiny' => [
              'w' => 90,
              'h' => 90,
              'fit' => 'crop-center',
            ],
            'small' => [
              'w' => 270,
              'fit' => 'crop',
            ],
            'medium' => [
              'w' => 640,
              'h' => 480,
              'fit' => 'crop',
            ],
            'large' => [
              'w' => 720,
              'fit' => 'crop',
            ]
          ]
        ]
      ]);
      // Add url rules.
      $app->getUrlManager()->addRules([
        [
          'class' => 'yii\web\UrlRule',
          'pattern' => '<pathRequested:[\w\-]+>',
          'route' => 'crelish/frontend/run'
        ],
        [
          'class' => 'yii\web\UrlRule',
          'pattern' => 'crelish',
          'route' => 'crelish/dashboard/index'
        ],
        [
          'class' => 'yii\web\UrlRule',
          'pattern' => 'crelish/<controller:[\w\-]+>/<action:[\w\-]+>',
          'route' => 'crelish/<controller>/<action>'
        ],
        [
          'class' => 'yii\web\UrlRule',
          'pattern' => 'site/<action:[\w\-]+>',
          'route' => 'site/<action>'
        ],
        [
          'class' => 'yii\web\UrlRule',
          'pattern' => 'api/<action:[\w\-]+>',
          'route' => 'api/<action>'
        ],
        ['class' => 'giantbits\crelish\components\CrelishBaseUrlRule'],
        [
          'class' => 'yii\web\UrlRule',
          'pattern' => '<controller:[\w\-]+>/<action:[\w\-]+>',
          'route' => '/<controller>/<action>'
        ],
      ], TRUE);
    }

    // Register crelish.
    \Yii::$app->setModules([
      'crelish' => [
        'class' => 'giantbits\crelish\Module',
        'theme' => \Yii::$app->params['crelish']['theme']
      ]
    ]);
  }
}
