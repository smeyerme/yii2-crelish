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
                'extensions' => [
                  new \Cocur\Slugify\Bridge\Twig\SlugifyExtension(\Cocur\Slugify\Slugify::create()),
                  '\Twig_Extension_Debug',
	                \giantbits\crelish\extensions\RegisterCssExtension::class,
	                \giantbits\crelish\extensions\RegisterJsExtension::class,
	                \giantbits\crelish\extensions\TruncateWords::class,
	                \giantbits\crelish\extensions\ExtractFirstTagExtension::class,
                ],
                'options' => YII_ENV_DEV ? [
                  'debug' => true,
                  'auto_reload' => true,
                ] : [],
                'globals' => [
                  'url' => ['class' => '\yii\helpers\Url'],
                  'html' => ['class' => '\yii\helpers\Html'],
                  'chelper' => ['class' => '\giantbits\crelish\components\CrelishBaseHelper'],
                  'globals' => ['class' => '\giantbits\crelish\components\CrelishGlobals'],
                ],
                'functions' => [
                  't' => 'Yii::t'
                ],
	              'filters' => [
		              'clean_html' => '\giantbits\crelish\extensions\HtmlCleaner::cleanHtml',
	              ],
              ]
            ],
            'theme' => [
              'basePath' => '@app/themes/basic',
              'baseUrl' => '@web/themes/basic',
              'pathMap' => [
                '@app/views' => '@app/themes/basic',
              ],
            ],
          ],
          'urlManager' => [
            'class' => 'yii\web\UrlManager',
            'enablePrettyUrl' => TRUE,
            'enableStrictParsing' => TRUE,
            'showScriptName' => FALSE,
            'rules' => [
              [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'sitemap',
                'route' => 'sitemap/index',
                'suffix' => '.xml',
              ],
              [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'document/secure/<id:[\w\-]+>',
                'route' => 'document/secure'
              ],
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
                'sourceLanguage' => 'de',
                'fileMap' => [
                  'crelish' => 'crelish.php'
                ],
                'on missingTranslation' => [
                  CrelishI18nEventHandler::class,
                  'handleMissingTranslation'
                ]
              ],
              'i18n*' => [
                'class' => 'yii\i18n\PhpMessageSource',
                'basePath' => '@app/messages',
                'sourceLanguage' => 'de',
                'fileMap' => [
                  'i18n' => 'i18n.php'
                ],
                'on missingTranslation' => [
                  CrelishI18nEventHandler::class,
                  'handleMissingTranslation'
                ]
              ],
              'app*' => [
                'class' => 'yii\i18n\PhpMessageSource',
                'basePath' => '@app/messages',
                'sourceLanguage' => 'de',
                'fileMap' => [
                  'app' => 'app.php'
                ],
                'on missingTranslation' => [
                  CrelishI18nEventHandler::class,
                  'handleMissingTranslation'
                ]
              ],
              'content*' => [
                'class' => 'yii\i18n\PhpMessageSource',
                'basePath' => '@app/messages',
                'sourceLanguage' => 'de',
                'fileMap' => [
                  'content' => 'content.php'
                ],
                'on missingTranslation' => [
                  CrelishI18nEventHandler::class,
                  'handleMissingTranslation'
                ]
              ],
	            '*' => [
		            'class' => 'yii\i18n\PhpMessageSource',
	            ],
            ],
          ],
          'glide' => [
            'class' => 'giantbits\crelish\components\CrelishGlide',
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
          [
            'class' => 'yii\web\UrlRule',
            'pattern' => 'user/<action:[\w\-]+>',
            'route' => 'user/<action>'
          ],
          ['class' => 'giantbits\crelish\components\CrelishBaseUrlRule'],
          [
            'class' => 'yii\web\UrlRule',
            'pattern' => '<lang:[\w\-]+>/<controller:[\w\-]+>/<action:[\w\-]+>',
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
			
			\Yii::$app->params['crelish']['version'] = 'V0.4.34';
    }
  }
