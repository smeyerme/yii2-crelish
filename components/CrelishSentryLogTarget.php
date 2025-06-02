<?php

namespace giantbits\crelish\components;

use Yii;
use yii\log\LogRuntimeException;
use yii\log\Target;
use yii\helpers\VarDumper;

/**
 * Crelish Sentry Log Target
 *
 * This log target sends log messages to Sentry for centralized error tracking.
 * It integrates with Yii2's logging system and can be configured to capture
 * specific log levels and categories.
 */
class CrelishSentryLogTarget extends Target
{
  /**
   * @var string Name of the Sentry component in the application
   */
  public $sentryComponent = 'crelishSentry';

  /**
   * @var array Mapping of Yii log levels to Sentry levels
   */
  public $levelMap = [
    'error' => 'error',
    'warning' => 'warning',
    'info' => 'info',
    'trace' => 'debug',
    'profile' => 'debug',
  ];

  /**
   * @var bool Whether to include context information
   */
  public $includeContext = true;

  /**
   * @var bool Whether to capture exceptions separately
   */
  public $captureExceptions = true;

  /**
   * @var int Maximum length of log message
   */
  public $maxMessageLength = 2048;

  /**
   * @var array Categories to exclude from Sentry logging
   */
  public $excludeCategories = [
    'yii\db\*', // Exclude database queries by default
    'yii\web\HttpException:404', // Exclude 404s
  ];

  /**
   * @inheritdoc
   */
  public function init()
  {
    parent::init();

    // Set default levels if not configured
    if (empty($this->levels)) {
      $this->levels = ['error', 'warning'];
    }

    // Add exclude categories to the existing ones
    if (!empty($this->excludeCategories)) {
      $existingExcept = $this->except ?? [];
      $this->except = array_merge($existingExcept, $this->excludeCategories);
    }
  }

  /**
   * @inheritdoc
   */
  public function export()
  {
    $sentry = $this->getSentryComponent();
    if ($sentry === null) {
      return;
    }

    foreach ($this->messages as $message) {
      $this->processMessage($sentry, $message);
    }
  }

  /**
   * Process a single log message
   *
   * @param CrelishSentryComponent $sentry
   * @param array $message Log message array
   */
  protected function processMessage(CrelishSentryComponent $sentry, array $message): void
  {
    [$text, $level, $category, $timestamp, $traces] = $message;

    try {
      // Check if we should skip this message
      if ($this->shouldSkipMessage($message)) {
        return;
      }

      // Format the message
      $formattedMessage = $this->formatMessage($message);


      // Add context information
      if ($this->includeContext) {
        $this->addMessageContext($sentry, $message);
      }

      // Handle exceptions specially
      if ($this->captureExceptions && isset($message[4]['exception'])) {
        $exception = $message[4]['exception'];
        if ($exception instanceof \Throwable) {
          $sentry->captureException($exception);
          return;
        }
      }

      // Capture as message
      $sentryLevel = $this->mapLogLevel($level);
      $sentry->captureMessage($formattedMessage, $sentryLevel);
    } catch (\Exception $e) {
      // Don't let Sentry errors break logging
      throw new LogRuntimeException('Unable to send log to Sentry: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Check if a message should be skipped
   *
   * @param array $message
   * @return bool
   */
  protected function shouldSkipMessage(array $message): bool
  {
    [$text, $level, $category, $timestamp] = $message;

    // Skip Sentry-related log messages to avoid loops
    if (strpos($category, 'crelish.sentry') !== false) {
      return true;
    }

    // Skip if it's a trace or profile message with low importance
    if (in_array($level, ['trace', 'profile']) && !$this->isImportantMessage($text)) {
      return true;
    }

    return false;
  }

  /**
   * Check if a trace/profile message is important enough to log
   *
   * @param mixed $text
   * @return bool
   */
  protected function isImportantMessage($text): bool
  {
    if (!is_string($text)) {
      return false;
    }

    $importantKeywords = [
      'exception',
      'error',
      'failed',
      'timeout',
      'memory',
      'slow',
    ];

    $lowerText = strtolower($text);
    foreach ($importantKeywords as $keyword) {
      if (strpos($lowerText, $keyword) !== false) {
        return true;
      }
    }

    return false;
  }

  /**
   * Add context information to Sentry
   *
   * @param CrelishSentryComponent $sentry
   * @param array $message
   */
  protected function addMessageContext(CrelishSentryComponent $sentry, array $message): void
  {
    [$text, $level, $category, $timestamp, $traces] = $message;

    // Add log-specific tags
    $sentry->setTag('log_level', $level);
    $sentry->setTag('log_category', $category);

    // Add timestamp with microseconds preserved
    $microtime = sprintf('%.6f', $timestamp);
    $sentry->setExtra('log_timestamp', date('Y-m-d H:i:s', (int) $timestamp));
    $sentry->setExtra('log_microtime', $microtime);

    // Add traces if available and not too many
    if (!empty($traces) && count($traces) <= 10) {
      $formattedTraces = [];
      foreach ($traces as $trace) {
        $formattedTraces[] = $this->formatTrace($trace);
      }
      $sentry->setExtra('log_traces', $formattedTraces);
    }

    // Add memory usage
    $sentry->setExtra('memory_usage', memory_get_usage(true));
    $sentry->setExtra('memory_peak', memory_get_peak_usage(true));

    // Add request info for web applications
    if (Yii::$app instanceof \yii\web\Application && isset(Yii::$app->request)) {
      $this->addWebRequestContext($sentry);
    }
  }

  /**
   * Add web request context
   *
   * @param CrelishSentryComponent $sentry
   */
  protected function addWebRequestContext(CrelishSentryComponent $sentry): void
  {
    try {
      $request = Yii::$app->request;

      // Add current route if available
      if (isset(Yii::$app->controller)) {
        $sentry->setExtra('current_route', Yii::$app->controller->getRoute());
      }

      // Add request ID if available
      if (method_exists($request, 'getId')) {
        $sentry->setExtra('request_id', $request->getId());
      }
    } catch (\Exception $e) {
      // Ignore context errors
    }
  }

  /**
   * Format a single trace entry
   *
   * @param array $trace
   * @return string
   */
  protected function formatTrace(array $trace): string
  {
    $file = $trace['file'] ?? 'unknown';
    $line = $trace['line'] ?? 0;
    $function = '';

    if (isset($trace['class'])) {
      $function .= $trace['class'];
    }
    if (isset($trace['type'])) {
      $function .= $trace['type'];
    }
    if (isset($trace['function'])) {
      $function .= $trace['function'] . '()';
    }

    return $function ? "{$function} in {$file}:{$line}" : "{$file}:{$line}";
  }

  /**
   * @inheritdoc
   */
  public function formatMessage($message)
  {
    [$text, $level, $category, $timestamp] = $message;

    // Handle different message types
    if (is_string($text)) {
      $formattedText = $text;
    } elseif ($text instanceof \Throwable) {
      $formattedText = (string)$text;
    } else {
      $formattedText = VarDumper::export($text);
    }

    // Truncate if too long
    if (strlen($formattedText) > $this->maxMessageLength) {
      $formattedText = substr($formattedText, 0, $this->maxMessageLength - 3) . '...';
    }

    // Add category prefix for context
    if (!empty($category) && $category !== 'application') {
      $formattedText = "[{$category}] {$formattedText}";
    }

    return $formattedText;
  }

  /**
   * Map Yii log level to Sentry level
   *
   * @param string $level
   * @return string
   */
  protected function mapLogLevel(string $level): string
  {
    return $this->levelMap[$level] ?? 'info';
  }

  /**
   * Get the Sentry component instance
   *
   * @return CrelishSentryComponent|null
   */
  protected function getSentryComponent(): ?CrelishSentryComponent
  {
    if (!isset(Yii::$app) || !Yii::$app->has($this->sentryComponent)) {
      return null;
    }

    try {
      $component = Yii::$app->get($this->sentryComponent);

      if (!$component instanceof CrelishSentryComponent) {
        return null;
      }

      if (!$component->isInitialized()) {
        return null;
      }

      return $component;
    } catch (\Exception $e) {
      return null;
    }
  }
}