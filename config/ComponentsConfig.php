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
    $components = [
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

    // Add Sentry components if enabled
    $sentryConfig = self::getSentryConfig();
    if ($sentryConfig !== null) {
      $components['crelishSentry'] = $sentryConfig;
      
      // Debug: Log that Sentry is being configured
      if (defined('YII_DEBUG') && YII_DEBUG && class_exists('Yii') && isset(Yii::$app)) {
        error_log('Crelish: Sentry component configured');
      }

      // Add Sentry error handler if in web application context
      $errorHandlerConfig = self::getSentryErrorHandlerConfig();
      if ($errorHandlerConfig !== null) {
        $components['errorHandler'] = $errorHandlerConfig;
        if (defined('YII_DEBUG') && YII_DEBUG && class_exists('Yii') && isset(Yii::$app)) {
          error_log('Crelish: Sentry error handler configured');
        }
      }

      // Add Sentry log target
      $logConfig = self::getSentryLogConfig();
      if ($logConfig !== null) {
        $components['log'] = $logConfig;
        if (defined('YII_DEBUG') && YII_DEBUG && class_exists('Yii') && isset(Yii::$app)) {
          error_log('Crelish: Sentry log target configured');
        }
      }
    } else {
      // Debug: Log why Sentry is not being configured
      if (defined('YII_DEBUG') && YII_DEBUG && class_exists('Yii') && isset(Yii::$app)) {
        error_log('Crelish: Sentry not configured - getSentryConfig returned null');
      }
    }

    return $components;
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

  /**
   * Get Sentry component configuration if enabled
   *
   * @return array|null Sentry configuration or null if not enabled
   */
  public static function getSentryConfig(): ?array
  {
    // Check environment variables first
    $envEnabled = $_ENV['CRELISH_USE_SENTRY'] ?? null;
    $envDsn = $_ENV['SENTRY_DSN'] ?? getenv('SENTRY_DSN');

    // Debug logging
    if (defined('YII_DEBUG') && YII_DEBUG) {
      error_log("Crelish Sentry Debug: envEnabled = " . var_export($envEnabled, true));
      error_log("Crelish Sentry Debug: envDsn = " . (!empty($envDsn) ? '***SET***' : 'not set'));
    }

    // Check if explicitly disabled
    if ($envEnabled !== null && !filter_var($envEnabled, FILTER_VALIDATE_BOOLEAN)) {
      if (defined('YII_DEBUG') && YII_DEBUG) {
        error_log("Crelish Sentry Debug: Disabled via CRELISH_USE_SENTRY");
      }
      return null;
    }

    // Check params configuration
    $paramsEnabled = Yii::$app->params['crelish']['sentry_enabled'] ?? null;
    if ($paramsEnabled !== null && !$paramsEnabled) {
      if (defined('YII_DEBUG') && YII_DEBUG) {
        error_log("Crelish Sentry Debug: Disabled via params sentry_enabled");
      }
      return null;
    }

    // Must have DSN to enable
    $paramsDsn = Yii::$app->params['crelish']['sentry_dsn'] ?? null;
    if (empty($envDsn) && empty($paramsDsn)) {
      if (defined('YII_DEBUG') && YII_DEBUG) {
        error_log("Crelish Sentry Debug: No DSN found in env or params");
        error_log("Crelish Sentry Debug: paramsDsn = " . var_export($paramsDsn, true));
      }
      return null;
    }

    // Build configuration
    $config = [
      'class' => 'giantbits\crelish\components\CrelishSentryComponent',
      'enabled' => true,
    ];

    // Set DSN if available
    if (!empty($envDsn)) {
      $config['dsn'] = $envDsn;
    } elseif (!empty($paramsDsn)) {
      $config['dsn'] = $paramsDsn;
    }

    // Set environment
    $environment = Yii::$app->params['crelish']['sentry_environment'] ?? (defined('YII_ENV') ? YII_ENV : 'production');
    $config['environment'] = $environment;

    // Set sample rates
    $tracesSampleRate = Yii::$app->params['crelish']['sentry_traces_sample_rate'] ?? 0.1;
    $profilesSampleRate = Yii::$app->params['crelish']['sentry_profiles_sample_rate'] ?? 0.1;
    $config['tracesSampleRate'] = (float)$tracesSampleRate;
    $config['profilesSampleRate'] = (float)$profilesSampleRate;

    // Additional options
    $additionalOptions = Yii::$app->params['crelish']['sentry_options'] ?? [];
    if (!empty($additionalOptions) && is_array($additionalOptions)) {
      $config['options'] = $additionalOptions;
    }

    return $config;
  }

  /**
   * Get Sentry error handler configuration if enabled
   *
   * @return array|null Error handler configuration or null if not applicable
   */
  public static function getSentryErrorHandlerConfig(): ?array
  {
    // Only configure for web applications
    if (!isset(Yii::$app) || !(Yii::$app instanceof \yii\web\Application)) {
      return null;
    }

    $config = [
      'class' => 'giantbits\crelish\components\CrelishSentryErrorHandler',
      'sentryComponent' => 'crelishSentry',
      'captureErrors' => true,
      'captureFatalErrors' => true,
      'showUserFriendlyErrors' => true,
    ];

    // Allow customization of error handler behavior
    $showFriendlyErrors = Yii::$app->params['crelish']['sentry_show_friendly_errors'] ?? true;
    $config['showUserFriendlyErrors'] = $showFriendlyErrors;

    $errorView = Yii::$app->params['crelish']['sentry_error_view'] ?? null;
    if ($errorView !== null) {
      $config['errorView'] = $errorView;
    }

    $defaultErrorMessage = Yii::$app->params['crelish']['sentry_default_error_message'] ?? null;
    if ($defaultErrorMessage !== null) {
      $config['defaultErrorMessage'] = $defaultErrorMessage;
    }

    return $config;
  }

  /**
   * Get Sentry log configuration if enabled
   *
   * @return array|null Log configuration with Sentry target or null if not applicable
   */
  public static function getSentryLogConfig(): ?array
  {
    // Get existing log configuration or create default
    $existingLog = Yii::$app->components['log'] ?? [];

    // Determine log levels to capture
    $logLevels = Yii::$app->params['crelish']['sentry_log_levels'] ?? ['error', 'warning'];

    // Determine categories to capture (empty means all)
    $logCategories = Yii::$app->params['crelish']['sentry_log_categories'] ?? [];

    // Check if log target should be enabled
    $logTargetEnabled = Yii::$app->params['crelish']['sentry_log_target_enabled'] ?? true;

    if (!$logTargetEnabled) {
      return null;
    }

    // Build Sentry log target configuration
    $sentryTarget = [
      'class' => 'giantbits\crelish\components\CrelishSentryLogTarget',
      'sentryComponent' => 'crelishSentry',
      'levels' => $logLevels,
      'includeContext' => true,
      'captureExceptions' => true,
      'exportInterval' => 1, // Export immediately
      'logVars' => [], // Don't include global vars to avoid sensitive data
    ];

    // Add categories if specified
    if (!empty($logCategories)) {
      $sentryTarget['categories'] = $logCategories;
    }

    // Create base log configuration if it doesn't exist
    $logConfig = array_merge([
      'class' => 'yii\log\Dispatcher',
      'flushInterval' => 1,
      'targets' => [],
    ], $existingLog);

    // Add Sentry target to existing targets
    $targets = $logConfig['targets'] ?? [];
    $targets['sentry'] = $sentryTarget;
    $logConfig['targets'] = $targets;

    return $logConfig;
  }
}