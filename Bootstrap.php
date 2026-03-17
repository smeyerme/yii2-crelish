<?php

namespace giantbits\crelish;

use giantbits\crelish\config\ComponentsConfig;
use giantbits\crelish\config\UrlRulesConfig;
use Yii;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApplication;
use yii\web\Application as WebApplication;

/**
 * Bootstrap class for Crelish CMS
 *
 * Handles the initial configuration and setup of the Crelish module
 * during the application bootstrap phase.
 */
class Bootstrap implements BootstrapInterface
{
  /**
   * @var string Default theme name
   */
  private const DEFAULT_THEME = 'basic';

  /**
   * @var string Default package version
   */
  private const DEFAULT_VERSION = 'V0.9.0';

  /**
   * Bootstrap method to be called during application bootstrap stage
   *
   * @param Application $app The application instance
   * @throws InvalidConfigException If configuration is invalid
   */
  public function bootstrap($app): void
  {
    if ($app instanceof WebApplication) {
      $this->bootstrapWebApplication($app);
    } elseif ($app instanceof ConsoleApplication) {
      $this->bootstrapConsoleApplication($app);
    }

    $this->detectPackageVersion();
    $this->registerTwigGlobals();
  }

  /**
   * Bootstrap web application
   *
   * @param WebApplication $app
   * @throws InvalidConfigException
   */
  private function bootstrapWebApplication(WebApplication $app): void
  {
    // Configure asset manager with PjaxAsset override
    $this->configureAssetManager($app);

    // Configure web application components
    $this->configureWebApplication($app);

    // Register modules
    $this->registerModules($app);
  }

  /**
   * Bootstrap console application
   *
   * @param ConsoleApplication $app
   */
  private function bootstrapConsoleApplication(ConsoleApplication $app): void
  {
    $app->setComponents([
      'response' => [
        'class' => 'yii\console\Response',
      ]
    ]);

    // Register only the main crelish module for console
    $app->setModules([
      'crelish' => [
        'class' => Module::class,
        'theme' => $this->getTheme(),
      ]
    ]);

    $this->registerConsoleCommands($app);
  }

  /**
   * Register console commands
   *
   * @param ConsoleApplication $app
   */
  private function registerConsoleCommands(ConsoleApplication $app): void
  {
    // Register bot detection command
    $app->controllerMap['crelish-bot-detection'] = [
      'class' => 'giantbits\crelish\commands\BotDetectionController',
    ];

    // Register migration command for Crelish tables
    $app->controllerMap['crelish-migrate'] = [
      'class' => 'yii\console\controllers\MigrateController',
      'migrationPath' => [
        '@giantbits/crelish/migrations',
      ],
      'migrationNamespaces' => [
        'giantbits\crelish\migrations',
      ],
    ];

    // Register any other console commands here
    // $app->controllerMap['crelish-another-command'] = [
    //     'class' => 'giantbits\crelish\commands\AnotherController',
    // ];
  }

  /**
   * Configure asset manager with custom bundles
   *
   * @param WebApplication $app
   */
  private function configureAssetManager(WebApplication $app): void
  {
    $app->set('assetManager', [
      'class' => 'yii\web\AssetManager',
      'bundles' => [
        'yii\widgets\PjaxAsset' => [
          'class' => 'yii\web\AssetBundle',
          'sourcePath' => '@giantbits/crelish/assets/js',
          'js' => ['jquery.pjax.fixed.js'],
          'depends' => ['yii\web\YiiAsset'],
        ],
      ],
    ]);
  }

  /**
   * Configure web application components and URL rules
   *
   * @param WebApplication $app
   * @throws InvalidConfigException
   */
  private function configureWebApplication(WebApplication $app): void
  {
    // Configure components
    $this->configureComponents($app);

    // Configure URL rules
    $this->configureUrlRules($app);

    // Configure view paths for workspace
    $this->configureViewPaths($app);

    // Initialize sidebar manager
    $app->get('sideBarManager')->init();
  }

  /**
   * Configure application components
   *
   * @param WebApplication $app
   */
  private function configureComponents(WebApplication $app): void
  {
    $components = ComponentsConfig::getConfig();

    // Add analytics component if enabled
    $analyticsConfig = ComponentsConfig::getAnalyticsConfig();
    if ($analyticsConfig !== null) {
      $components['analytics'] = $analyticsConfig;
    }

    // Smart merge of urlManager configuration
    // If application has urlManager config, merge it with Crelish defaults (app config takes precedence)
    // If no app config exists, use Crelish defaults as fallback
    if (isset($components['urlManager'])) {
      $crelishUrlManager = $components['urlManager'];
      $existingUrlManager = $app->components['urlManager'] ?? [];

      // Merge configurations: existing app config overrides Crelish defaults
      if (!empty($existingUrlManager)) {
        $components['urlManager'] = \yii\helpers\ArrayHelper::merge($crelishUrlManager, $existingUrlManager);
      }
      // If no existing config, use Crelish defaults (already set in $components)
    }

    $app->setComponents($components);
  }

  /**
   * Configure URL rules
   *
   * @param WebApplication $app
   */
  private function configureUrlRules(WebApplication $app): void
  {
    $app->getUrlManager()->addRules(UrlRulesConfig::getRules(), true);
  }

  /**
   * Configure view paths for workspace customization
   *
   * @param WebApplication $app
   */
  private function configureViewPaths(WebApplication $app): void
  {
    $workspaceViewPath = Yii::getAlias('@app/workspace/crelish/views');

    if (!file_exists($workspaceViewPath) || !is_dir($workspaceViewPath)) {
      return;
    }

    $theme = $app->getView()->theme;
    if ($theme === null) {
      return;
    }

    // Add workspace paths with higher priority
    $pathMap = $theme->pathMap;
    $pathMap['@giantbits/crelish/views'] = [
      $workspaceViewPath,
      '@app/themes/' . $this->getTheme() . '/crelish/views',
    ];

    $theme->pathMap = $pathMap;

    Yii::info("Added workspace view path: {$workspaceViewPath}", 'crelish');
  }

  /**
   * Register Crelish modules
   *
   * @param WebApplication $app
   */
  private function registerModules(WebApplication $app): void
  {
    $modules = [
      'crelish' => [
        'class' => Module::class,
        'theme' => $this->getTheme(),
        'controllerMap' => $this->scanWorkspaceControllers($app),
      ],
      'crelish-api' => [
        'class' => 'giantbits\crelish\modules\api\Module',
      ],
    ];

    $app->setModules($modules);
  }

  /**
   * Scan workspace for custom controllers
   *
   * @param WebApplication $app
   * @return array Controller map
   */
  private function scanWorkspaceControllers(WebApplication $app): array
  {
    $controllerMap = [];
    $workspacePath = Yii::getAlias('@app/workspace/crelish/controllers');

    if (!file_exists($workspacePath) || !is_dir($workspacePath)) {
      return $controllerMap;
    }

    $files = glob($workspacePath . '/*.php');
    if ($files === false) {
      return $controllerMap;
    }

    foreach ($files as $file) {
      $controllerInfo = $this->parseControllerFile($file);
      if ($controllerInfo === null) {
        continue;
      }

      [$camelName, $kebabName, $fullClassName] = $controllerInfo;
      // Register kebab-case as primary (Yii2 convention)
      $controllerMap[$kebabName] = $fullClassName;
      $this->addControllerUrlRule($app, $kebabName);

      // Also register legacy camelCase alias for backward compatibility
      if ($camelName !== $kebabName) {
        $controllerMap[$camelName] = $fullClassName;
        $this->addControllerUrlRule($app, $camelName);
      }

      Yii::info("Discovered workspace controller: {$kebabName} => {$fullClassName}", 'crelish');
    }

    return $controllerMap;
  }

  /**
   * Parse controller file and extract controller information
   *
   * @param string $file Controller file path
   * @return array|null [camelCaseName, kebab-case-name, className] or null if invalid
   */
  private function parseControllerFile(string $file): ?array
  {
    $className = basename($file, '.php');
    if (!str_ends_with($className, 'Controller')) {
      return null;
    }

    $baseName = str_replace('Controller', '', $className);
    // Legacy camelCase format (e.g. eventAnalytics)
    $camelName = lcfirst($baseName);
    // Yii2-standard kebab-case format (e.g. event-analytics)
    $kebabName = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $baseName));
    $fullClassName = 'app\\workspace\\crelish\\controllers\\' . $className;

    return [$camelName, $kebabName, $fullClassName];
  }

  /**
   * Add URL rule for a workspace controller
   *
   * @param WebApplication $app
   * @param string $controllerName
   */
  private function addControllerUrlRule(WebApplication $app, string $controllerName): void
  {
    $app->getUrlManager()->addRules([
      [
        'class' => 'yii\web\UrlRule',
        'pattern' => 'crelish/' . $controllerName . '/<action:[\w\-]+>',
        'route' => 'crelish/' . $controllerName . '/<action>',
      ]
    ], false);
  }

  /**
   * Detect and set package version
   */
  private function detectPackageVersion(): void
  {
    $version = $this->getVersionFromComposer();
    Yii::$app->params['crelish']['version'] = $version;
  }

  /**
   * Get version from Composer files
   *
   * @return string Version string
   */
  private function getVersionFromComposer(): string
  {
    // Try to get version from installed.json
    $version = $this->getVersionFromInstalledJson();
    if ($version !== null) {
      return 'V' . $version;
    }

    // Try to get version from composer.json
    $version = $this->getVersionFromComposerJson();
    if ($version !== null) {
      return 'V' . $version;
    }

    return self::DEFAULT_VERSION;
  }

  /**
   * Get version from Composer's installed.json
   *
   * @return string|null Version or null if not found
   */
  private function getVersionFromInstalledJson(): ?string
  {
    try {
      $installedJsonPath = Yii::getAlias('@vendor/composer/installed.json');
      if (!file_exists($installedJsonPath)) {
        return null;
      }

      $content = file_get_contents($installedJsonPath);
      if ($content === false) {
        return null;
      }

      $installedData = json_decode($content, true);
      if (!is_array($installedData)) {
        return null;
      }

      $packages = $installedData['packages'] ?? $installedData;

      foreach ($packages as $package) {
        if (isset($package['name']) && $package['name'] === 'giantbits/yii2-crelish') {
          return $package['version'] ?? null;
        }
      }
    } catch (\Exception $e) {
      Yii::warning('Failed to read installed.json: ' . $e->getMessage(), 'crelish');
    }

    return null;
  }

  /**
   * Get version from composer.json
   *
   * @return string|null Version or null if not found
   */
  private function getVersionFromComposerJson(): ?string
  {
    try {
      $composerFile = dirname(__FILE__) . '/composer.json';
      if (!file_exists($composerFile)) {
        return null;
      }

      $content = file_get_contents($composerFile);
      if ($content === false) {
        return null;
      }

      $composerData = json_decode($content, true);
      if (!is_array($composerData)) {
        return null;
      }

      return $composerData['version'] ?? null;
    } catch (\Exception $e) {
      Yii::warning('Failed to read composer.json: ' . $e->getMessage(), 'crelish');
    }

    return null;
  }

  /**
   * Register Twig global functions
   */
  private function registerTwigGlobals(): void
  {
    if (!isset(Yii::$app->view->renderers['twig'])) {
      return;
    }

    Yii::$app->view->renderers['twig']['globals']['header_bar_widget'] = function ($config = []) {
      return \giantbits\crelish\components\widgets\HeaderBar::widget($config);
    };
  }

  /**
   * Get configured theme name
   *
   * @return string Theme name
   */
  private function getTheme(): string
  {
    return Yii::$app->params['crelish']['theme'] ?? self::DEFAULT_THEME;
  }
}