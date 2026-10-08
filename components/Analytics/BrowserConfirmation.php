<?php

namespace giantbits\crelish\components\Analytics;

use Yii;
use yii\db\Connection;

/**
 * Browser confirmation of server-side page views.
 *
 * A page view is recorded while the page is rendered, so it is counted whether
 * or not a browser ever shows the page. With the confirmation on, the page
 * carries a few lines of script that report the page view back to the site;
 * the page view and its session then get a confirmed_at time. A client that
 * never runs the script - most bots - leaves both empty.
 *
 * Nothing reads confirmed_at yet: statistics, aggregation and bot detection
 * are unchanged. It is recorded so a site can compare the two counts.
 *
 * Off by default. A site turns it on in its params:
 *
 *   'crelish' => ['analytics' => ['browserConfirmation' => ['enabled' => true]]]
 *
 * The endpoint is on the site itself. Its path (default page/state) can be set
 * with 'path'; keep it free of words tracking blockers look for.
 */
final class BrowserConfirmation
{
  public const DEFAULTS = [
    'enabled' => false,
    'path' => 'page/state',
  ];

  public static function all(): array
  {
    $config = Yii::$app->params['crelish']['analytics']['browserConfirmation'] ?? [];

    return array_merge(self::DEFAULTS, is_array($config) ? $config : []);
  }

  public static function isEnabled(): bool
  {
    return (bool)self::all()['enabled'];
  }

  public static function path(): string
  {
    $path = trim((string)self::all()['path'], '/');

    return $path !== '' ? $path : self::DEFAULTS['path'];
  }

  /**
   * The script a rendered page carries for its page view, null when there is
   * nothing to report.
   */
  public static function script(?int $pageViewId): ?string
  {
    if (!self::isEnabled() || $pageViewId === null || $pageViewId < 1) {
      return null;
    }

    $url = json_encode(Yii::$app->request->getBaseUrl() . '/' . self::path(), JSON_UNESCAPED_SLASHES);
    $body = json_encode('v=' . $pageViewId);

    return '(function(){try{fetch(' . $url . ',{method:"POST",keepalive:true,credentials:"same-origin",'
      . 'headers:{"Content-Type":"application/x-www-form-urlencoded"},body:' . $body . '}).catch(function(){})}catch(e){}})();';
  }

  /**
   * Stamp a page view of $sessionId, and the session with its first
   * confirmation. A page view of another session, an unknown one and one that
   * is already confirmed are left alone.
   *
   * @return bool Whether the page view was confirmed by this call
   */
  public static function confirm(Connection $db, int $pageViewId, string $sessionId): bool
  {
    if ($pageViewId < 1 || $sessionId === '') {
      return false;
    }

    try {
      $confirmed = $db->createCommand(
        'UPDATE {{%analytics_page_views}} SET confirmed_at = NOW()'
        . ' WHERE id = :id AND session_id = :session AND confirmed_at IS NULL',
        [':id' => $pageViewId, ':session' => $sessionId]
      )->execute();

      if ($confirmed < 1) {
        return false;
      }

      $db->createCommand(
        'UPDATE {{%analytics_sessions}} SET confirmed_at = NOW() WHERE session_id = :session AND confirmed_at IS NULL',
        [':session' => $sessionId]
      )->execute();

      return true;
    } catch (\Throwable $e) {
      // A site whose tables lack the column yet must not fail a visitor's request
      Yii::warning('Page view not confirmed: ' . $e->getMessage(), __METHOD__);

      return false;
    }
  }
}
