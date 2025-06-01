<?php

namespace giantbits\crelish\config;

use giantbits\crelish\components\CrelishI18nEventHandler;
use Yii;

/**
 * Components configuration for Crelish CMS
 */
class ComponentsConfig
{
    /**
     * Get the default components configuration
     * 
     * @return array Component configurations
     */
    public static function getConfig(): array
    {
        return [
            'user' => self::getUserConfig(),
            'defaultRoute' => 'frontend/index',
            'view' => self::getViewConfig(),
            'urlManager' => self::getUrlManagerConfig(),
            'i18n' => self::getI18nConfig(),
            'glide' => self::getGlideConfig(),
            'sideBarManager' => [
                'class' => 'giantbits\crelish\components\CrelishSidebarManager'
            ],
            'canonicalHelper' => self::getCanonicalHelperConfig(),
            'contentService' => [
                'class' => 'giantbits\crelish\components\ContentService',
                'contentTypesPath' => '@app/config/content-types',
            ],
            'crelishAnalytics' => [
                'class' => 'giantbits\crelish\components\CrelishAnalyticsComponent',
                'enabled' => true,
                'excludeIps' => [],
            ],
            'dashboardManager' => [
                'class' => 'giantbits\crelish\components\CrelishDashboardManager',
            ],
        ];
    }
    
    /**
     * Get analytics component configuration if enabled
     * 
     * @return array|null Analytics configuration or null if not enabled
     */
    public static function getAnalyticsConfig(): ?array
    {
        if (Yii::$app->params['crelish']['ga_sst_enabled'] ?? false) {
            return [
                'class' => 'giantbits\crelish\components\Analytics\AnalyticsService',
                'debug' => YII_DEBUG,
            ];
        }
        return null;
    }
    
    /**
     * Get user component configuration
     */
    private static function getUserConfig(): array
    {
        return [
            'class' => 'yii\web\User',
            'identityClass' => 'giantbits\crelish\components\CrelishUser',
            'enableAutoLogin' => true,
            'loginUrl' => ['crelish/user/login']
        ];
    }
    
    /**
     * Get view component configuration
     */
    private static function getViewConfig(): array
    {
        return [
            'class' => 'yii\web\View',
            'renderers' => [
                'twig' => self::getTwigConfig()
            ],
            'theme' => [
                'basePath' => '@app/themes/basic',
                'baseUrl' => '@web/themes/basic',
                'pathMap' => [
                    '@app/views' => '@app/themes/basic',
                ],
            ],
        ];
    }
    
    /**
     * Get Twig renderer configuration
     */
    private static function getTwigConfig(): array
    {
        return [
            'class' => 'yii\twig\ViewRenderer',
            'cachePath' => '@runtime/Twig/cache',
            'extensions' => [
                new \Cocur\Slugify\Bridge\Twig\SlugifyExtension(\Cocur\Slugify\Slugify::create()),
                \Twig\Extension\DebugExtension::class,
                \giantbits\crelish\extensions\RegisterCssExtension::class,
                \giantbits\crelish\extensions\RegisterJsExtension::class,
                \giantbits\crelish\extensions\TruncateWords::class,
                \giantbits\crelish\extensions\ExtractFirstTagExtension::class,
                \giantbits\crelish\extensions\HtmlAttributesExtension::class,
                \giantbits\crelish\extensions\CrelishGlobalsExtension::class,
                \giantbits\crelish\extensions\JsExpressionExtension::class,
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
        ];
    }
    
    /**
     * Get URL manager configuration
     */
    private static function getUrlManagerConfig(): array
    {
        return [
            'class' => 'yii\web\UrlManager',
            'enablePrettyUrl' => true,
            'enableStrictParsing' => true,
            'showScriptName' => false,
            'rules' => [],
        ];
    }
    
    /**
     * Get i18n configuration
     */
    private static function getI18nConfig(): array
    {
        $handler = [
            CrelishI18nEventHandler::class,
            'handleMissingTranslation'
        ];
        
        return [
            'class' => 'yii\i18n\I18N',
            'translations' => [
                'crelish*' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    'basePath' => '@app/messages',
                    'sourceLanguage' => 'en',
                    'fileMap' => ['crelish' => 'crelish.php'],
                    'on missingTranslation' => $handler
                ],
                'i18n*' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    'basePath' => '@app/messages',
                    'fileMap' => ['i18n' => 'i18n.php'],
                    'on missingTranslation' => $handler
                ],
                'app*' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    'basePath' => '@app/messages',
                    'fileMap' => ['app' => 'app.php'],
                    'on missingTranslation' => $handler
                ],
                'content*' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    'basePath' => '@app/messages',
                    'fileMap' => ['content' => 'content.php'],
                    'on missingTranslation' => $handler
                ],
                '*' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                ],
            ],
        ];
    }
    
    /**
     * Get Glide configuration
     */
    private static function getGlideConfig(): array
    {
        return [
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
        ];
    }
    
    /**
     * Get canonical helper configuration
     */
    private static function getCanonicalHelperConfig(): array
    {
        return [
            'class' => 'giantbits\crelish\helpers\CrelishCanonicalHelper',
            'globalSignificantParams' => ['filter', 'search', 'uuid', 'ctype', 'id', 'action', 'pathRequested'],
            'globalExcludedParams' => ['_pjax', 'page', 'sort', 'order', 'filter', 'search', 'uuid', 'ctype', 'id', 'action', 'language'],
        ];
    }
}