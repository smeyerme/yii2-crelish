<?php
namespace giantbits\crelish\helpers;

use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\CrelishBaseUrlRule;
use Yii;
use yii\base\Component;
use yii\helpers\Url;

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
    if (isset($this->controllerConfigs[$controller])) {
      $config = array_merge($config, $this->controllerConfigs[$controller]);
    }

    // Apply action-specific config if exists
    if (isset($this->actionConfigs[$controller][$action])) {
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

    return  CrelishBaseHelper::urlFromSlug($request->pathInfo, $canonicalParams, false, true);
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
    ]);
  }
}