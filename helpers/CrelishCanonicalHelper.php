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

    // Set available languages with domains from params.php or use defaults
    $this->availableLanguages = $paramsConfig['availableLanguages'] ?? $this->availableLanguages;
    if (empty($this->availableLanguages)) {
      // Default configuration with separate domains
      $this->availableLanguages = [
        'de' => [
          'name' => 'Deutsch',
          'hreflang' => 'de',
          'domain' => 'https://spielegesellschaft.ch'
        ],
        'fr' => [
          'name' => 'Français',
          'hreflang' => 'fr',
          'domain' => 'https://societe-des-jeux.ch'
        ],
        'it' => [
          'name' => 'Italiano',
          'hreflang' => 'it',
          'domain' => 'https://societa-dei-giochi.ch'
        ],
        'en' => [
          'name' => 'English',
          'hreflang' => 'en',
          'domain' => 'https://gaming-society.ch'
        ]
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

    // Get current language code
    $currentLang = Yii::$app->language;
    if (preg_match('/([a-z]{2})-[A-Z]{2}/', $currentLang, $sub)) {
      $currentLang = $sub[1];
    }

    // Get the base path for this language (without language prefix)
    $slug = $currentPath ?: (\Yii::$app->params['crelish']['entryPoint']['slug'] ?? '');

    // Determine if we need to include language in path (depends on domain structure)
    $includeLangInPath = \Yii::$app->params['crelish']['langprefix'] ?? false;

    // If we're using domain-per-language approach, path doesn't need language prefix
    if (isset($this->availableLanguages[$currentLang]['domain'])) {
      $domain = $this->availableLanguages[$currentLang]['domain'];
      $langCode = $includeLangInPath ? $currentLang : null;

      // Generate URL with domain-per-language approach
      $path = CrelishBaseHelper::urlFromSlug($slug, $canonicalParams, $langCode, false);
      return rtrim($domain, '/') . $path;
    }

    // Fallback to standard URL generation
    return CrelishBaseHelper::urlFromSlug($slug, $canonicalParams, $currentLang, true);
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

    $view->registerLinkTag([
      'rel' => 'canonical',
      'href' => $canonical
    ], 'canonical');
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
  public function registerAll(array $params = [], bool $includePagination = false, array $config = []): void
  {
    $this->register($params, $includePagination, $config);
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

    // Get the base path for the current page (without language prefix)
    $slug = $currentPath ?: (\Yii::$app->params['crelish']['entryPoint']['slug'] ?? '');

    // Determine if we need to include language in path (depends on domain structure)
    $includeLangInPath = \Yii::$app->params['crelish']['langprefix'] ?? false;

    foreach ($this->availableLanguages as $langCode => $language) {
      if (isset($language['domain'])) {
        // For domain per language approach - no language in path
        $path = CrelishBaseHelper::urlFromSlug($slug, $canonicalParams,
          $includeLangInPath ? $langCode : null, false);
        $langUrl = rtrim($language['domain'], '/') . $path;
      } else {
        // Fallback to standard URL with language in path
        $langUrl = CrelishBaseHelper::urlFromSlug($slug, $canonicalParams, $langCode, true);
      }

      $view->registerLinkTag([
        'rel' => 'alternate',
        'hreflang' => $language['hreflang'],
        'href' => $langUrl
      ], 'hreflang-' . $langCode);
    }

    // Register x-default hreflang (typically points to your primary/default language)
    $defaultLangCode = Yii::$app->params['defaultLanguage'] ?? 'en';
    if (isset($this->availableLanguages[$defaultLangCode])) {
      $defaultLanguage = $this->availableLanguages[$defaultLangCode];

      if (isset($defaultLanguage['domain'])) {
        $path = CrelishBaseHelper::urlFromSlug($slug, $canonicalParams,
          $includeLangInPath ? $defaultLangCode : null, false);
        $defaultUrl = rtrim($defaultLanguage['domain'], '/') . $path;
      } else {
        $defaultUrl = CrelishBaseHelper::urlFromSlug($slug, $canonicalParams, $defaultLangCode, true);
      }

      $view->registerLinkTag([
        'rel' => 'alternate',
        'hreflang' => 'x-default',
        'href' => $defaultUrl
      ], 'hreflang-x-default');
    }
  }
}