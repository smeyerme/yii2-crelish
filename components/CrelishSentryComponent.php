<?php

namespace giantbits\crelish\components;

use Sentry;
use Yii;
use yii\base\Component;
use yii\web\HttpException;

/**
 * Crelish Sentry Component for error tracking and monitoring
 *
 * This component integrates Sentry.io error tracking into Crelish CMS.
 * It can be activated via environment variables:
 * - SENTRY_DSN: The Sentry project DSN
 * - CRELISH_USE_SENTRY: Optional boolean to enable/disable (defaults to checking for DSN)
 *
 * Configuration in params.php:
 * - crelish.sentry_enabled: Boolean to enable/disable Sentry
 * - crelish.sentry_dsn: The Sentry DSN (can also come from env)
 * - crelish.sentry_environment: Environment name (defaults to YII_ENV)
 * - crelish.sentry_sample_rate: Sample rate for performance monitoring (0.0-1.0)
 */
class CrelishSentryComponent extends Component
{
  /**
   * @var string|null Sentry DSN
   */
  public $dsn;

  /**
   * @var string Environment name for Sentry
   */
  public $environment;

  /**
   * @var bool Whether Sentry is enabled
   */
  public $enabled = true;

  /**
   * @var float Sample rate for performance monitoring (0.0 to 1.0)
   */
  public $tracesSampleRate = 0.1;

  /**
   * @var float Sample rate for profiling (0.0 to 1.0)
   */
  public $profilesSampleRate = 0.1;

  /**
   * @var array Additional Sentry configuration options
   */
  public $options = [];

  /**
   * @var array HTTP status codes to ignore (won't be sent to Sentry)
   */
  public $ignoreHttpCodes = [404];

  /**
   * @var bool Whether Sentry has been initialized
   */
  private $initialized = false;

  /**
   * @inheritdoc
   */
  public function init(): void
  {
    parent::init();

    // Check if Sentry should be enabled
    if (!$this->shouldEnable()) {
      return;
    }

    // Get DSN from various sources
    $this->dsn = $this->getDsn();

    if (empty($this->dsn)) {
      Yii::warning('Sentry DSN not provided, disabling Sentry', 'crelish.sentry');
      return;
    }

    // Set default environment
    if (empty($this->environment)) {
      $this->environment = YII_ENV;
    }

    $this->initializeSentry();
  }

  /**
   * Initialize Sentry with configuration
   */
  private function initializeSentry(): void
  {
    try {
      $config = array_merge([
        'dsn' => $this->dsn,
        'environment' => $this->environment,
        'traces_sample_rate' => $this->tracesSampleRate,
        'profiles_sample_rate' => $this->profilesSampleRate,
        'send_default_pii' => false, // Don't send personally identifiable information
        'attach_stacktrace' => true,
        'context_lines' => 5,
        'before_send' => [$this, 'beforeSend'],
      ], $this->options);

      Sentry\init($config);
      $this->initialized = true;

      // Set user context if available
      $this->setUserContext();

      Yii::info('Sentry initialized successfully', 'crelish.sentry');
    } catch (\Exception $e) {
      Yii::error('Failed to initialize Sentry: ' . $e->getMessage(), 'crelish.sentry');
    }
  }

  /**
   * Check if Sentry should be enabled
   *
   * @return bool
   */
  private function shouldEnable(): bool
  {
    // Check environment variable
    $envEnabled = $_ENV['CRELISH_USE_SENTRY'] ?? null;
    if ($envEnabled !== null) {
      return filter_var($envEnabled, FILTER_VALIDATE_BOOLEAN);
    }

    // Check params configuration
    $paramsEnabled = Yii::$app->params['crelish']['sentry_enabled'] ?? null;
    if ($paramsEnabled !== null) {
      return (bool)$paramsEnabled;
    }

    // Check if enabled property is set
    if (!$this->enabled) {
      return false;
    }

    // Default: enable if DSN is available
    return !empty($this->getDsn());
  }

  /**
   * Get DSN from various sources
   *
   * @return string|null
   */
  private function getDsn(): ?string
  {
    // Check if already set
    if (!empty($this->dsn)) {
      return $this->dsn;
    }

    // Check environment variable
    $envDsn = $_ENV['SENTRY_DSN'] ?? getenv('SENTRY_DSN');
    if (!empty($envDsn)) {
      return $envDsn;
    }

    // Check params configuration
    $paramsDsn = Yii::$app->params['crelish']['sentry_dsn'] ?? null;
    if (!empty($paramsDsn)) {
      return $paramsDsn;
    }

    return null;
  }

  /**
   * Set user context for Sentry
   */
  private function setUserContext(): void
  {
    if (!$this->initialized || !Yii::$app->has('user', true)) {
      return;
    }

    try {
      $user = Yii::$app->user;

      if (!$user->getIsGuest() && $user->identity) {
        Sentry\configureScope(function (Sentry\State\Scope $scope) use ($user) {
          $scope->setUser([
            'id' => $user->identity->uuid ?? $user->id,
            'username' => $user->identity->username ?? 'unknown',
            'email' => $user->identity->email ?? null,
          ]);
        });
      }
    } catch (\Throwable $e) {
      // Ignore errors in user context setting
    }
  }

  /**
   * Capture an exception
   *
   * @param \Throwable $exception
   * @return string|null Event ID
   */
  public function captureException(\Throwable $exception): ?string
  {

    if (!$this->initialized) {
      return null;
    }

    // Check if we should ignore this exception
    if ($this->shouldIgnoreException($exception)) {
      return null;
    }

    try {
      return Sentry\captureException($exception);
    } catch (\Exception $e) {
      Yii::error('Failed to capture exception in Sentry: ' . $e->getMessage(), 'crelish.sentry');
      return null;
    }
  }

  /**
   * Capture a message
   *
   * @param string $message
   * @param string $level
   * @return string|null Event ID
   */
  public function captureMessage(string $message, string $level = 'info'): ?string
  {

    if (!$this->initialized) {
      return null;
    }

    try {
      return Sentry\captureMessage($message, $this->mapLogLevel($level));
    } catch (\Exception $e) {
      Yii::error('Failed to capture message in Sentry: ' . $e->getMessage(), 'crelish.sentry');
      return null;
    }
  }

  /**
   * Add breadcrumb
   *
   * @param string $message
   * @param string $category
   * @param string $level
   * @param array $data
   */
  public function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
  {
    if (!$this->initialized) {
      return;
    }

    try {
      Sentry\addBreadcrumb([
        'message' => $message,
        'category' => $category,
        'level' => $this->mapLogLevel($level),
        'data' => $data,
        'timestamp' => time(),
      ]);
    } catch (\Exception $e) {
      // Ignore breadcrumb errors
    }
  }

  /**
   * Set tag
   *
   * @param string $key
   * @param string $value
   */
  public function setTag(string $key, string $value): void
  {
    if (!$this->initialized) {
      return;
    }

    try {
      Sentry\configureScope(function (Sentry\State\Scope $scope) use ($key, $value) {
        $scope->setTag($key, $value);
      });
    } catch (\Exception $e) {
      // Ignore tag errors
    }
  }

  /**
   * Set extra context
   *
   * @param string $key
   * @param mixed $value
   */
  public function setExtra(string $key, $value): void
  {
    if (!$this->initialized) {
      return;
    }

    try {
      Sentry\configureScope(function (Sentry\State\Scope $scope) use ($key, $value) {
        $scope->setExtra($key, $value);
      });
    } catch (\Exception $e) {
      // Ignore extra context errors
    }
  }

  /**
   * Check if we should ignore an exception
   *
   * @param \Throwable $exception
   * @return bool
   */
  private function shouldIgnoreException(\Throwable $exception): bool
  {
    // Ignore HTTP exceptions with certain status codes
    if ($exception instanceof HttpException) {
      return in_array($exception->statusCode, $this->ignoreHttpCodes);
    }

    return false;
  }

  /**
   * Before send callback to filter events
   *
   * @param Sentry\Event $event
   * @param Sentry\EventHint|null $hint
   * @return Sentry\Event|null
   */
  public function beforeSend(Sentry\Event $event, ?Sentry\EventHint $hint = null): ?Sentry\Event
  {
    // Add Crelish-specific context
    $event->setTag('cms', 'crelish');
    $event->setTag('yii_version', Yii::getVersion());

    if (isset(Yii::$app->params['crelish']['version'])) {
      $event->setTag('crelish_version', Yii::$app->params['crelish']['version']);
    }

    // Add request information if available
    if (isset(Yii::$app->request) && !Yii::$app->request instanceof \yii\console\Request) {
      try {
        $request = Yii::$app->request;
        $extras = $event->getExtra();
        $extras['request_method'] = $request->method;
        $extras['request_url'] = $request->absoluteUrl;
        $extras['user_agent'] = $request->userAgent;
        $extras['user_ip'] = $request->userIP;
        $event->setExtra($extras);
      } catch (\Exception $e) {
        // Ignore request context errors
      }
    }

    return $event;
  }

  /**
   * Map Yii log level to Sentry level
   *
   * @param string $level
   * @return string
   */
  private function mapLogLevel(string $level): string
  {
    $mapping = [
      'error' => 'error',
      'warning' => 'warning',
      'info' => 'info',
      'trace' => 'debug',
      'profile' => 'debug',
    ];

    return $mapping[$level] ?? 'info';
  }

  /**
   * Check if Sentry is initialized and ready
   *
   * @return bool
   */
  public function isInitialized(): bool
  {
    return $this->initialized;
  }
}