<?php
namespace giantbits\crelish\helpers;

use giantbits\crelish\components\CrelishBaseHelper;
use Yii;
use yii\base\Component;

/**
 * CanonicalHelper handles the generation of canonical URLs with flexible configuration
 * for different controllers and views.
 */
class CrelishCanonicalHelper extends Component
{
  /**
   * Global configuration for significant parameters
   * @var array
   */
  public array $globalSignificantParams = [];

  /**
   * Global configuration for excluded parameters
   * @var array
   */
  public array $globalExcludedParams = [];

  /**
   * Controller-specific configurations
   * @var array
   */
  public array $controllerConfigs = [];

  /**
   * Action-specific configurations within controllers
   * @var array
   */
  public array $actionConfigs = [];

  /**
   * Available languages for the site
   * @var array
   */
  public array $availableLanguages = [];

  /**
   * Initialize the component with default global configurations
   */
  public function init(): void
  {
    parent::init();

    // First try to get config from params.php
    $paramsConfig = Yii::$app->params['canonical'] ?? [];

    // Set significant params from params.php or use defaults
    $this->globalSignificantParams = $paramsConfig['significantParams'] ?? $this->globalSignificantParams;
    if (empty($this->globalSignificantParams)) {
      $this->globalSignificantParams = [
        'category',
        'industry',
        'location'
      ];
    }

    // Set excluded params from params.php or use defaults
    $this->globalExcludedParams = $paramsConfig['excludedParams'] ?? $this->globalExcludedParams;
    if (empty($this->globalExcludedParams)) {
      $this->globalExcludedParams = [
        'sort',
        'order',
        'view',
        'ref',
        'utm_source',
        'utm_medium',
        'utm_campaign'
      ];
    }

    // Set available languages from params.php or use defaults
    $this->availableLanguages = $paramsConfig['availableLanguages'] ?? $this->availableLanguages;
    if (empty($this->availableLanguages)) {
      $this->availableLanguages = [
        'de' => ['name' => 'Deutsch', 'hreflang' => 'de'],
        'fr' => ['name' => 'Français', 'hreflang' => 'fr'],
        'it' => ['name' => 'Italiano', 'hreflang' => 'it'],
        'en' => ['name' => 'English', 'hreflang' => 'en']
      ];
    }
  }

  /**
   * Sets configuration for a specific controller
   *
   * @param string $controllerId
   * @param array $config Configuration array with significantParams and excludedParams
   * @return self
   */
  public function setControllerConfig(string $controllerId, array $config): static
  {
    $this->controllerConfigs[$controllerId] = $config;
    return $this;
  }

  /**
   * Sets configuration for a specific action within a controller
   *
   * @param string $controllerId
   * @param string $actionId
   * @param array $config Configuration array with significantParams and excludedParams
   * @return self
   */
  public function setActionConfig(string $controllerId, string $actionId, array $config): static
  {
    if (!isset($this->actionConfigs[$controllerId])) {
      $this->actionConfigs[$controllerId] = [];
    }
    $this->actionConfigs[$controllerId][$actionId] = $config;
    return $this;
  }

  /**
   * Gets the effective configuration for the current request
   *
   * @param array $overrideConfig Optional override configuration for this specific call
   * @return array
   */
  protected function getEffectiveConfig(array $overrideConfig = []): array
  {
    $controller = Yii::$app->controller ? Yii::$app->controller->id : null;
    $action = Yii::$app->controller ? Yii::$app->controller->action->id : null;

    // Start with global config
    $config = [
      'significantParams' => $this->globalSignificantParams,
      'excludedParams' => $this->globalExcludedParams
    ];

    // Apply controller-specific config if exists
    if ($controller !== null && isset($this->controllerConfigs[$controller])) {
      $config = array_merge($config, $this->controllerConfigs[$controller]);
    }

    // Apply action-specific config if exists
    if ($controller !== null && $action !== null && isset($this->actionConfigs[$controller][$action])) {
      $config = array_merge($config, $this->actionConfigs[$controller][$action]);
    }

    // Apply override config if provided
    if (!empty($overrideConfig)) {
      $config = array_merge($config, $overrideConfig);
    }

    return $config;
  }

  /**
   * Generates canonical URL for the current page
   *
   * @param array $params Additional parameters to consider
   * @param bool $includePagination Whether to include pagination in canonical URL
   * @param array $config Override configuration for this specific call
   * @return string
   */
  public function getCanonicalUrl(array $params = [], bool $includePagination = false, array $config = []): string
  {
    $effectiveConfig = $this->getEffectiveConfig($config);
    $request = Yii::$app->request;
    $currentParams = $request->getQueryParams();

    // Get the path without language prefix
    $pathInfo = $request->getPathInfo();
    $currentPath = $pathInfo;

    // If language prefixes are enabled, strip the current language prefix
    if (isset(\Yii::$app->params['crelish']['langprefix']) && \Yii::$app->params['crelish']['langprefix']) {
      $pathParts = explode('/', $pathInfo, 2);

      // Check if the first part is a language code (exactly 2 characters)
      if (isset($pathParts[0]) && strlen($pathParts[0]) === 2) {
        // Remove the language part to get the slug without language prefix
        $currentPath = isset($pathParts[1]) ? $pathParts[1] : '';
      }
    }

    // Filter out excluded parameters
    $filteredParams = array_diff_key(
      $currentParams,
      array_flip($effectiveConfig['excludedParams'])
    );

    // Keep only significant parameters
    $canonicalParams = array_intersect_key(
      $filteredParams,
      array_flip($effectiveConfig['significantParams'])
    );

    // Handle pagination
    if ($includePagination && isset($currentParams['page']) && $currentParams['page'] > 1) {
      $canonicalParams['page'] = $currentParams['page'];
    }

    // Merge with additional parameters
    $canonicalParams = array_merge($canonicalParams, $params);

    // Use current language for canonical URL
    $currentLang = Yii::$app->language;
    if (preg_match('/([a-z]{2})-[A-Z]{2}/', $currentLang, $sub)) {
      $currentLang = $sub[1];
    }

    return CrelishBaseHelper::urlFromSlug($currentPath, $canonicalParams, $currentLang, true);
  }

  /**
   * Registers canonical meta tag in the page
   *
   * @param array $params Additional parameters
   * @param bool $includePagination Whether to include pagination
   * @param array $config Override configuration for this specific call
   */
  public function register(array $params = [], bool $includePagination = false, array $config = []): void
  {
    $view = Yii::$app->view;
    $canonical = $this->getCanonicalUrl($params, $includePagination, $config);

    // Use a unique key for canonical link to prevent duplication
    $view->registerLinkTag([
      'rel' => 'canonical',
      'href' => $canonical
    ], 'canonical'); // Added key 'canonical'
  }

  /**
   * Registers hreflang tags (kept for backward compatibility)
   *
   * @param array $params Additional parameters
   * @param bool $includePagination Whether to include pagination
   * @param array $config Override configuration for this specific call
   */
  public function registerHreflang(array $params = [], bool $includePagination = false, array $config = []): void
  {
    $this->registerHreflangOnly($params, $includePagination, $config);
  }

  /**
   * Registers both canonical and hreflang tags
   *
   * @param array $params Additional parameters
   * @param bool $includePagination Whether to include pagination
   * @param array $config Override configuration for this specific call
   */
  /**
   * Registers both canonical and hreflang tags
   *
   * @param array $params Additional parameters
   * @param bool $includePagination Whether to include pagination
   * @param array $config Override configuration for this specific call
   */
  public function registerAll(array $params = [], bool $includePagination = false, array $config = []): void
  {
    // Generate the canonical URL once
    $view = Yii::$app->view;
    $canonical = $this->getCanonicalUrl($params, $includePagination, $config);

    // Register canonical tag with key to prevent duplication
    $view->registerLinkTag([
      'rel' => 'canonical',
      'href' => $canonical
    ], 'canonical'); // Added key 'canonical'

    // Register hreflang tags
    $this->registerHreflangOnly($params, $includePagination, $config);
  }

  /**
   * Registers only hreflang tags without canonical tag
   *
   * @param array $params Additional parameters to consider
   * @param bool $includePagination Whether to include pagination
   * @param array $config Override configuration for this specific call
   */
  public function registerHreflangOnly(array $params = [], bool $includePagination = false, array $config = []): void
  {
    $view = Yii::$app->view;
    $request = Yii::$app->request;

    // Get the path without language prefix
    $pathInfo = $request->getPathInfo();
    $currentPath = $pathInfo;

    // If language prefixes are enabled, strip the current language prefix
    if (isset(\Yii::$app->params['crelish']['langprefix']) && \Yii::$app->params['crelish']['langprefix']) {
      $pathParts = explode('/', $pathInfo, 2);

      // Check if the first part is a language code (exactly 2 characters)
      if (isset($pathParts[0]) && strlen($pathParts[0]) === 2) {
        // Remove the language part to get the slug without language prefix
        $currentPath = isset($pathParts[1]) ? $pathParts[1] : '';
      }
    }

    // Get the effective parameters for the URL
    $effectiveConfig = $this->getEffectiveConfig($config);
    $currentParams = $request->getQueryParams();

    // Filter out excluded parameters
    $filteredParams = array_diff_key(
      $currentParams,
      array_flip($effectiveConfig['excludedParams'])
    );

    // Keep only significant parameters
    $canonicalParams = array_intersect_key(
      $filteredParams,
      array_flip($effectiveConfig['significantParams'])
    );

    // Handle pagination
    if ($includePagination && isset($currentParams['page']) && $currentParams['page'] > 1) {
      $canonicalParams['page'] = $currentParams['page'];
    }

    // Merge with additional parameters
    $canonicalParams = array_merge($canonicalParams, $params);

    foreach ($this->availableLanguages as $langCode => $language) {
      $langUrl = CrelishBaseHelper::urlFromSlug($currentPath, $canonicalParams, $langCode, true);

      // Use unique key for each hreflang tag based on language
      $view->registerLinkTag([
        'rel' => 'alternate',
        'hreflang' => $language['hreflang'],
        'href' => $langUrl
      ], 'hreflang-' . $langCode); // Added unique key
    }
  }
}