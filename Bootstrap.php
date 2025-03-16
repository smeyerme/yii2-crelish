<?php

namespace giantbits\crelish;

use giantbits\crelish\components\CrelishI18nEventHandler;
use \yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;
use yii\web\Application;
use Yii;

/**
 * Bootstrap class for Crelish CMS
 */
class Bootstrap implements BootstrapInterface
{
  /**
   * Bootstrap method to be called during application bootstrap stage
   * 
   * @param \yii\web\Application $app
   * @throws InvalidConfigException
   */
  public function bootstrap($app): void
  {
    if ($app instanceof Application) {
      $this->configureWebApplication($app);
    }

    // Register crelish module
    Yii::$app->setModules([
      'crelish' => [
        'class' => 'giantbits\crelish\Module',
        'theme' => Yii::$app->params['crelish']['theme']
      ],
      'api' => [
        'class' => 'giantbits\crelish\modules\api\Module',
      ]
    ]);

    Yii::$app->params['crelish']['version'] = 'V0.8.0';
  }

  /**
   * Configure web application components and URL rules
   * 
   * @param Application $app
   */
  private function configureWebApplication(Application $app): void
  {
    // Add components
    $this->configureComponents($app);

    // Add URL rules
    $this->configureUrlRules($app);
    
    $app->get('sideBarManager')->init();
  }

  /**
   * Configure application components
   * 
   * @param Application $app
   */
  private function configureComponents(Application $app): void
  {
    $components = [
      'user' => [
        'class' => 'yii\web\User',
        'identityClass' => 'giantbits\crelish\components\CrelishUser',
        'enableAutoLogin' => true,
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
        'enablePrettyUrl' => true,
        'enableStrictParsing' => true,
        'showScriptName' => false,
        'rules' => [
          // Sitemap rules
          [
            'class' => 'yii\web\UrlRule',
            'pattern' => 'sitemap-<lang:\w+>',
            'route' => 'sitemap/language',
            'suffix' => '.xml',
          ],
          [
            'class' => 'yii\web\UrlRule',
            'pattern' => 'sitemap',
            'route' => 'sitemap/index',
            'suffix' => '.xml',
          ],
          [
            'class' => 'yii\web\UrlRule',
            'pattern' => 'sitemap-style',
            'route' => 'sitemap/style',
            'suffix' => '.xsl',
          ],
          [
            'class' => 'yii\web\UrlRule',
            'pattern' => 'sitemap-ping',
            'route' => 'sitemap/ping',
          ],
          // Other rules
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
            'sourceLanguage' => 'en',
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
        'signKey' => false,
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
      ],
      'sideBarManager' => [
        'class' => 'giantbits\crelish\components\CrelishSidebarManager'
      ],
      'canonicalHelper' => [
        'class' => 'giantbits\crelish\helpers\CrelishCanonicalHelper',
        'globalSignificantParams' => ['filter', 'search', 'uuid', 'ctype', 'id', 'action', 'pathRequested'],
        'globalExcludedParams' => ['_pjax', 'page', 'sort', 'order', 'filter', 'search', 'uuid', 'ctype', 'id', 'action', 'language'],
      ],
      'contentService' => [
        'class' => 'giantbits\crelish\components\ContentService',
        'contentTypesPath' => '@app/config/content-types',
      ],
    ];

    // Add analytics component if enabled
    if (Yii::$app->params['crelish']['ga_sst_enabled'] ?? false) {
      $components['analytics'] = [
        'class' => 'giantbits\crelish\components\Analytics\AnalyticsService',
        'debug' => YII_DEBUG,
      ];
    }

    Yii::$app->setComponents($components);
  }

  /**
   * Configure URL rules for the application
   * 
   * @param Application $app
   */
  private function configureUrlRules(Application $app): void
  {
    $app->getUrlManager()->addRules([
      // API routes
      [
        'class' => 'yii\web\UrlRule',
        'pattern' => 'api/content/<type:[\w\-]+>',
        'route' => 'api/content/index',
      ],
      [
        'class' => 'yii\web\UrlRule',
        'pattern' => 'api/content/<type:[\w\-]+>/<id:[\w\-]+>',
        'route' => 'api/content/view',
      ],
      [
        'class' => 'yii\web\UrlRule',
        'pattern' => 'api/content/<type:[\w\-]+>',
        'route' => 'api/content/create',
        'verb' => 'POST',
      ],
      [
        'class' => 'yii\web\UrlRule',
        'pattern' => 'api/content/<type:[\w\-]+>/<id:[\w\-]+>',
        'route' => 'api/content/update',
        'verb' => 'PUT',
      ],
      [
        'class' => 'yii\web\UrlRule',
        'pattern' => 'api/content/<type:[\w\-]+>/<id:[\w\-]+>',
        'route' => 'api/content/delete',
        'verb' => 'DELETE',
      ],
      // Existing routes
      [
        'class' => 'yii\web\UrlRule',
        'pattern' => 'api/<action:[\w\-]+>/<id:[\w\-]+>',
        'route' => 'api/<action>'
      ],
      [
        'class' => 'yii\web\UrlRule',
        'pattern' => 'site/<action:[\w\-]+>',
        'route' => 'site/<action>'
      ],
      ['class' => 'giantbits\crelish\components\CrelishBaseUrlRule'],
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
        'pattern' => 'user/<action:[\w\-]+>',
        'route' => 'user/<action>'
      ],
      [
        'class' => 'yii\web\UrlRule',
        'pattern' => '<controller:[\w\-]+>/<action:[\w\-]+>/<id:[\w\-]+>',
        'route' => '<controller>/<action>'
      ],
      [
        'class' => 'yii\web\UrlRule',
        'pattern' => '<lang:[\w\-]+>/<controller:[\w\-]+>/<action:[\w\-]+>/<id:[\w\-]+>',
        'route' => '<controller>/<action>'
      ],
    ], true);
  }
}
