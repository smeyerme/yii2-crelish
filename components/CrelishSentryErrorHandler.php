<?php

namespace giantbits\crelish\components;

use Yii;
use yii\web\ErrorHandler;
use yii\console\ErrorHandler as ConsoleErrorHandler;
use yii\base\Exception;
use yii\base\ErrorException;
use yii\web\HttpException;

/**
 * Crelish Sentry Error Handler
 *
 * This error handler integrates with Sentry for error tracking while maintaining
 * all standard Yii2 error handling functionality. It automatically captures
 * exceptions and errors to Sentry when the Sentry component is available and enabled.
 */
class CrelishSentryErrorHandler extends ErrorHandler
{
  /**
   * @var string Name of the Sentry component in the application
   */
  public $sentryComponent = 'crelishSentry';

  /**
   * @var bool Whether to capture errors in addition to exceptions
   */
  public $captureErrors = true;

  /**
   * @var bool Whether to show user-friendly error pages in production
   */
  public $showUserFriendlyErrors = true;

  /**
   * @var string Path to custom error view file
   */
  public $errorView = '@giantbits/crelish/views/error/error';

  /**
   * @var string Default error message for production
   */
  public $defaultErrorMessage = 'An error occurred while processing your request.';

  /**
   * @var array HTTP status code to user-friendly message mapping
   */
  public $statusMessages = [
    400 => 'Bad Request - The request could not be understood.',
    401 => 'Unauthorized - Authentication is required.',
    403 => 'Forbidden - You do not have permission to access this resource.',
    404 => 'Page Not Found - The requested page could not be found.',
    405 => 'Method Not Allowed - The request method is not supported.',
    500 => 'Internal Server Error - Something went wrong on our end.',
    502 => 'Bad Gateway - The server received an invalid response.',
    503 => 'Service Unavailable - The service is temporarily unavailable.',
  ];

  /**
   * @var array Error levels to capture (when captureErrors is true)
   */
  public $captureErrorLevels = [
    E_ERROR,
    E_WARNING,
    E_PARSE,
    E_NOTICE,
    E_CORE_ERROR,
    E_CORE_WARNING,
    E_COMPILE_ERROR,
    E_COMPILE_WARNING,
    E_USER_ERROR,
    E_USER_WARNING,
    E_USER_NOTICE,
    E_RECOVERABLE_ERROR,
    E_DEPRECATED,
    E_USER_DEPRECATED,
  ];

  /**
   * @var bool Whether to capture fatal errors
   */
  public $captureFatalErrors = true;

  /**
   * @inheritdoc
   */
  public function handleException($exception)
  {
    // Capture to Sentry before standard handling
    $this->captureException($exception);

    // Continue with standard Yii error handling
    parent::handleException($exception);
  }

  /**
   * @inheritdoc
   */
  protected function renderException($exception)
  {
    // Debug: Log exception details to help troubleshoot
    $logFile = Yii::getAlias('@runtime/sentry_error_debug.log');
    $logMessage = "[" . date('Y-m-d H:i:s') . "] CrelishSentryErrorHandler - renderException\n";
    $logMessage .= "YII_DEBUG: " . (YII_DEBUG ? 'true' : 'false') . "\n";
    $logMessage .= "showUserFriendlyErrors: " . ($this->showUserFriendlyErrors ? 'true' : 'false') . "\n";
    $logMessage .= "Exception Type: " . get_class($exception) . "\n";
    $logMessage .= "Exception Message: " . $exception->getMessage() . "\n";
    $logMessage .= "Exception File: " . $exception->getFile() . ":" . $exception->getLine() . "\n";
    $logMessage .= "Stack Trace:\n" . $exception->getTraceAsString() . "\n";
    $logMessage .= str_repeat('=', 80) . "\n\n";
    @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

    // In production mode with user-friendly errors enabled
    if (!YII_DEBUG && $this->showUserFriendlyErrors && Yii::$app instanceof \yii\web\Application) {
      $this->renderUserFriendlyException($exception);
    } else {
      // Use default Yii error rendering (debug mode or disabled user-friendly errors)
      parent::renderException($exception);
    }
  }

  /**
   * @inheritdoc
   */
  public function handleError($code, $message, $file, $line)
  {
    // Capture to Sentry if enabled and level matches
    if ($this->captureErrors && in_array($code, $this->captureErrorLevels)) {
      $this->captureError($code, $message, $file, $line);
    }

    // Continue with standard Yii error handling
    return parent::handleError($code, $message, $file, $line);
  }

  /**
   * @inheritdoc
   */
  public function handleFatalError()
  {
    // Capture fatal error to Sentry if enabled
    if ($this->captureFatalErrors) {
      $error = error_get_last();
      if ($error !== null && $this->isFatalError($error)) {
        $this->captureError(
          $error['type'],
          $error['message'],
          $error['file'],
          $error['line']
        );
      }
    }

    // Continue with standard Yii fatal error handling
    parent::handleFatalError();
  }

  /**
   * Capture an exception to Sentry
   *
   * @param \Throwable $exception The exception to capture
   */
  protected function captureException(\Throwable $exception): void
  {
    $sentry = $this->getSentryComponent();
    if ($sentry === null) {
      return;
    }

    try {
      // Add error handler context
      $sentry->setTag('error_handler', 'crelish_sentry');
      $sentry->setExtra('exception_class', get_class($exception));

      // Add HTTP context for web applications
      if (Yii::$app instanceof \yii\web\Application) {
        $this->addWebContext($sentry, $exception);
      }

      // Capture the exception
      $eventId = $sentry->captureException($exception);

      if ($eventId !== null) {
        Yii::debug("Exception captured to Sentry with ID: {$eventId}", 'crelish.sentry');
      }
    } catch (\Exception $e) {
      // Don't let Sentry errors break the error handling
      Yii::error("Failed to capture exception to Sentry: " . $e->getMessage(), 'crelish.sentry');
    }
  }

  /**
   * Capture an error to Sentry
   *
   * @param int $code Error code
   * @param string $message Error message
   * @param string $file Error file
   * @param int $line Error line
   */
  protected function captureError(int $code, string $message, string $file, int $line): void
  {
    $sentry = $this->getSentryComponent();
    if ($sentry === null) {
      return;
    }

    try {
      // Create an ErrorException for better context
      $exception = new ErrorException($message, 0, $code, $file, $line);

      // Add error-specific context
      $sentry->setTag('error_handler', 'crelish_sentry');
      $sentry->setTag('error_type', $this->getErrorTypeName($code));
      $sentry->setExtra('error_code', $code);
      $sentry->setExtra('error_file', $file);
      $sentry->setExtra('error_line', $line);

      // Capture as exception
      $eventId = $sentry->captureException($exception);

      if ($eventId !== null) {
        Yii::debug("Error captured to Sentry with ID: {$eventId}", 'crelish.sentry');
      }
    } catch (\Exception $e) {
      // Don't let Sentry errors break the error handling
      Yii::error("Failed to capture error to Sentry: " . $e->getMessage(), 'crelish.sentry');
    }
  }

  /**
   * Add web-specific context to Sentry
   *
   * @param CrelishSentryComponent $sentry
   * @param \Throwable $exception
   */
  protected function addWebContext(CrelishSentryComponent $sentry, \Throwable $exception): void
  {
    if (!isset(Yii::$app->request)) {
      return;
    }

    try {
      $request = Yii::$app->request;

      // Add route information
      if (isset(Yii::$app->controller)) {
        $sentry->setTag('controller', get_class(Yii::$app->controller));
        $sentry->setTag('action', Yii::$app->controller->action->id ?? 'unknown');
        $sentry->setExtra('route', Yii::$app->controller->getRoute());
      }

      // Add HTTP status code for HTTP exceptions
      if ($exception instanceof HttpException) {
        $sentry->setTag('http_status', (string)$exception->statusCode);
      }

      // Add session information if available
      if (isset(Yii::$app->session) && Yii::$app->session->getIsActive()) {
        $sentry->setExtra('session_id', Yii::$app->session->getId());
      }

      // Add referrer if available
      $referrer = $request->getReferrer();
      if ($referrer !== null) {
        $sentry->setExtra('referrer', $referrer);
      }
    } catch (\Exception $e) {
      // Ignore context errors
    }
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
        Yii::warning("Component '{$this->sentryComponent}' is not a CrelishSentryComponent instance", 'crelish.sentry');
        return null;
      }

      if (!$component->isInitialized()) {
        return null;
      }

      return $component;
    } catch (\Exception $e) {
      Yii::error("Failed to get Sentry component: " . $e->getMessage(), 'crelish.sentry');
      return null;
    }
  }

  /**
   * Check if an error is fatal
   *
   * @param array $error Error information from error_get_last()
   * @return bool
   */
  protected function isFatalError(array $error): bool
  {
    return in_array($error['type'], [
      E_ERROR,
      E_PARSE,
      E_CORE_ERROR,
      E_CORE_WARNING,
      E_COMPILE_ERROR,
      E_COMPILE_WARNING,
    ]);
  }

  /**
   * Get human-readable error type name
   *
   * @param int $code Error code
   * @return string Error type name
   */
  protected function getErrorTypeName(int $code): string
  {
    $errorTypes = [
      E_ERROR => 'E_ERROR',
      E_WARNING => 'E_WARNING',
      E_PARSE => 'E_PARSE',
      E_NOTICE => 'E_NOTICE',
      E_CORE_ERROR => 'E_CORE_ERROR',
      E_CORE_WARNING => 'E_CORE_WARNING',
      E_COMPILE_ERROR => 'E_COMPILE_ERROR',
      E_COMPILE_WARNING => 'E_COMPILE_WARNING',
      E_USER_ERROR => 'E_USER_ERROR',
      E_USER_WARNING => 'E_USER_WARNING',
      E_USER_NOTICE => 'E_USER_NOTICE',
      E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
      E_DEPRECATED => 'E_DEPRECATED',
      E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ];

    return $errorTypes[$code] ?? "UNKNOWN_ERROR({$code})";
  }

  /**
   * Render user-friendly exception page for production
   *
   * @param \Throwable $exception
   */
  protected function renderUserFriendlyException(\Throwable $exception): void
  {
    try {
      // Determine HTTP status code
      $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;

      // Set response status
      if (isset(Yii::$app->response)) {
        Yii::$app->response->setStatusCode($statusCode);
      }

      // Generate error ID for user reference
      $errorId = $this->generateErrorId();

      // Get user-friendly message
      $userMessage = $this->getUserFriendlyMessage($exception, $statusCode);

      // Try to render with Crelish layout
      $content = $this->renderErrorView([
        'exception' => $exception,
        'statusCode' => $statusCode,
        'userMessage' => $userMessage,
        'errorId' => $errorId,
        'showDetails' => false, // Never show details in production
      ]);

      echo $content;
    } catch (\Exception $e) {
      // Fallback to basic HTML if view rendering fails
      $this->renderBasicErrorPage($exception);
    }
  }

  /**
   * Generate a unique error ID for user reference
   *
   * @return string
   */
  protected function generateErrorId(): string
  {
    return 'ERR-' . strtoupper(substr(md5(microtime(true) . mt_rand()), 0, 8));
  }

  /**
   * Get user-friendly error message
   *
   * @param \Throwable $exception
   * @param int $statusCode
   * @return string
   */
  protected function getUserFriendlyMessage(\Throwable $exception, int $statusCode): string
  {
    // For HTTP exceptions, use the status-specific message if available
    if ($exception instanceof HttpException && isset($this->statusMessages[$statusCode])) {
      return $this->statusMessages[$statusCode];
    }

    // For specific exception types, provide friendly messages
    if ($exception instanceof \yii\db\Exception) {
      return 'A database error occurred. Please try again later.';
    }

    if ($exception instanceof \yii\web\NotFoundHttpException) {
      return $this->statusMessages[404];
    }

    if ($exception instanceof \yii\web\ForbiddenHttpException) {
      return $this->statusMessages[403];
    }

    // Default message
    return $this->defaultErrorMessage;
  }

  /**
   * Render error view with Crelish layout
   *
   * @param array $params View parameters
   * @return string
   */
  protected function renderErrorView(array $params): string
  {
    // Try to use the application view component
    if (isset(Yii::$app->view)) {
      $view = Yii::$app->view;

      // Check if custom error view exists
      if ($this->viewExists($this->errorView)) {
        return $view->renderFile($this->errorView, $params);
      }

      // Try default error views
      $defaultViews = [
        '@app/views/site/error.php',
        '@app/views/site/error.twig',
        '@giantbits/crelish/views/default/error.twig',
      ];

      foreach ($defaultViews as $viewFile) {
        if ($this->viewExists($viewFile)) {
          return $view->renderFile($viewFile, $params);
        }
      }
    }

    // Fallback to basic error page
    return $this->renderBasicErrorHtml($params);
  }

  /**
   * Check if view file exists
   *
   * @param string $viewFile
   * @return bool
   */
  protected function viewExists(string $viewFile): bool
  {
    try {
      $resolvedFile = Yii::getAlias($viewFile);
      return file_exists($resolvedFile);
    } catch (\Exception $e) {
      return false;
    }
  }

  /**
   * Render basic error page when view rendering fails
   *
   * @param \Throwable $exception
   */
  protected function renderBasicErrorPage(\Throwable $exception): void
  {
    $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;
    $errorId = $this->generateErrorId();
    $userMessage = $this->getUserFriendlyMessage($exception, $statusCode);

    echo $this->renderBasicErrorHtml([
      'statusCode' => $statusCode,
      'userMessage' => $userMessage,
      'errorId' => $errorId,
    ]);
  }

  /**
   * Render basic HTML error page
   *
   * @param array $params
   * @return string
   */
  protected function renderBasicErrorHtml(array $params): string
  {
    $statusCode = $params['statusCode'] ?? 500;
    $userMessage = $params['userMessage'] ?? $this->defaultErrorMessage;
    $errorId = $params['errorId'] ?? $this->generateErrorId();

    $title = "Error {$statusCode}";

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .error-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 3rem;
            text-align: center;
            max-width: 500px;
            margin: 2rem;
        }
        .error-code {
            font-size: 4rem;
            font-weight: 700;
            color: #e74c3c;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }
        .error-message {
            font-size: 1.2rem;
            color: #555;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .error-id {
            font-size: 0.9rem;
            color: #888;
            margin-bottom: 2rem;
            padding: 0.5rem 1rem;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 4px solid #007bff;
        }
        .back-button {
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 0.75rem 2rem;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.3s ease;
            font-weight: 500;
        }
        .back-button:hover {
            background: #0056b3;
            color: white;
            text-decoration: none;
        }
        .support-info {
            margin-top: 2rem;
            font-size: 0.9rem;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">{$statusCode}</div>
        <div class="error-message">{$userMessage}</div>
        <div class="error-id">
            <strong>Error ID:</strong> {$errorId}<br>
            <small>Please include this ID when contacting support</small>
        </div>
        <a href="javascript:history.back()" class="back-button">Go Back</a>
        <div class="support-info">
            If the problem persists, please contact our support team.
        </div>
    </div>
</body>
</html>
HTML;
  }
}