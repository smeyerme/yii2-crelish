<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\Application;

/**
 * Switches a running application over to the Sentry error handler and log target.
 *
 * Yii registers the configured error handler in the application constructor, before
 * any bootstrap runs. Replacing the 'errorHandler' or 'log' component definitions from
 * a bootstrap therefore changes nothing: PHP keeps calling the handler registered
 * first, and the logger keeps dispatching to the targets it already has. This class
 * swaps the registered handler and adds the target to the live dispatcher instead.
 */
final class SentryActivation
{
  /**
   * @param array|null $errorHandlerConfig component config for the Sentry error handler, or null to keep the current one
   * @param array|null $logTargetConfig config of the Sentry log target, or null to add none
   */
  public static function apply(Application $app, ?array $errorHandlerConfig, ?array $logTargetConfig): void
  {
    if ($errorHandlerConfig !== null) {
      $app->getErrorHandler()->unregister();
      $app->set('errorHandler', $errorHandlerConfig);
      $app->getErrorHandler()->register();
    }

    if ($logTargetConfig !== null) {
      $dispatcher = $app->getLog();
      $targets = $dispatcher->targets;
      $targets['sentry'] = Yii::createObject($logTargetConfig);
      $dispatcher->targets = $targets;
    }
  }
}
