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
 * With the report the browser says what is odd about it (confirmed_flags, the
 * FLAG_* bits: a driven browser, no screen, ...), and the first real
 * interaction with the page is reported once more (engaged_at).
 *
 * Nothing reads these columns yet: statistics, aggregation and bot detection
 * are unchanged. They are recorded so a site can compare the counts and see
 * which signal separates people from bots on its own traffic.
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
  /** navigator.webdriver: Selenium, Puppeteer, Playwright unless they hide it */
  public const FLAG_WEBDRIVER = 1;
  /** Globals left by PhantomJS, Nightmare or Cypress */
  public const FLAG_AUTOMATION_GLOBALS = 2;
  /** A screen or viewport without a size */
  public const FLAG_NO_SCREEN = 4;
  /** No preferred languages */
  public const FLAG_NO_LANGUAGES = 8;
  /** A Chrome user agent without window.chrome */
  public const FLAG_NOT_CHROME = 16;
  /** The page was not visible when it reported (background tab, prerender) */
  public const FLAG_HIDDEN = 32;

  public const KNOWN_FLAGS = 63;

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
    $report = json_encode('v=' . $pageViewId . '&f=');
    $touched = json_encode('v=' . $pageViewId . '&e=1');

    // Bits as the FLAG_* constants. A scroll is not an interaction: the browser
    // scrolls by itself (anchors, restored positions). Nor is a pointer move
    // without movement, which Chrome sends after a layout change.
    return '(function(){try{var w=window,n=navigator,d=document,f=0;'
      . 'if(n.webdriver)f|=1;'
      . 'if(w._phantom||w.__nightmare||w.callPhantom||w.Cypress)f|=2;'
      . 'if(!screen.width||!screen.height||!w.innerWidth||!w.innerHeight)f|=4;'
      . 'if(!n.languages||!n.languages.length)f|=8;'
      . 'if(/Chrome\\//.test(n.userAgent)&&!w.chrome)f|=16;'
      . 'if(d.visibilityState&&d.visibilityState!=="visible")f|=32;'
      . 'var p=function(b){try{fetch(' . $url . ',{method:"POST",keepalive:true,credentials:"same-origin",'
      . 'headers:{"Content-Type":"application/x-www-form-urlencoded"},body:b}).catch(function(){})}catch(e){}};'
      . 'p(' . $report . '+f);'
      . 'var t=["pointerdown","pointermove","keydown","touchstart","wheel"],o={capture:true,passive:true},'
      . 'h=function(e){if(!e.isTrusted||(e.type==="pointermove"&&!e.movementX&&!e.movementY))return;'
      . 't.forEach(function(x){w.removeEventListener(x,h,o)});p(' . $touched . ')};'
      . 't.forEach(function(x){w.addEventListener(x,h,o)});'
      . '}catch(e){}})();';
  }

  /**
   * Stamp a page view of $sessionId, and the session with its first
   * confirmation. A page view of another session, an unknown one and one that
   * is already confirmed are left alone.
   *
   * @param int $flags What the browser reported about itself (FLAG_* bits)
   * @return bool Whether the page view was confirmed by this call
   */
  public static function confirm(Connection $db, int $pageViewId, string $sessionId, int $flags = 0): bool
  {
    if ($pageViewId < 1 || $sessionId === '') {
      return false;
    }

    $view = [':id' => $pageViewId, ':session' => $sessionId];

    try {
      $confirmed = $db->createCommand(
        'UPDATE {{%analytics_page_views}} SET confirmed_at = NOW()'
        . ' WHERE id = :id AND session_id = :session AND confirmed_at IS NULL',
        $view
      )->execute();

      if ($confirmed < 1) {
        return false;
      }

      $db->createCommand(
        'UPDATE {{%analytics_sessions}} SET confirmed_at = NOW() WHERE session_id = :session AND confirmed_at IS NULL',
        [':session' => $sessionId]
      )->execute();
    } catch (\Throwable $e) {
      // A site whose tables lack the column yet must not fail a visitor's request
      Yii::warning('Page view not confirmed: ' . $e->getMessage(), __METHOD__);

      return false;
    }

    try {
      $flags = max(0, $flags) & self::KNOWN_FLAGS;

      $db->createCommand(
        'UPDATE {{%analytics_page_views}} SET confirmed_flags = :flags WHERE id = :id AND session_id = :session',
        $view + [':flags' => $flags]
      )->execute();
      $db->createCommand(
        'UPDATE {{%analytics_sessions}} SET confirmed_flags = COALESCE(confirmed_flags, 0) | :flags WHERE session_id = :session',
        [':session' => $sessionId, ':flags' => $flags]
      )->execute();
    } catch (\Throwable $e) {
      // Tables from before the signals: the confirmation itself stands
      Yii::warning('Confirmation signals not kept: ' . $e->getMessage(), __METHOD__);
    }

    return true;
  }

  /**
   * Record the visitor's first interaction with a page view of $sessionId, and
   * the first one of the session. A page whose own report never arrived counts
   * as confirmed by its interaction.
   *
   * @return bool Whether the interaction was recorded by this call
   */
  public static function engage(Connection $db, int $pageViewId, string $sessionId): bool
  {
    if ($pageViewId < 1 || $sessionId === '') {
      return false;
    }

    try {
      $engaged = $db->createCommand(
        'UPDATE {{%analytics_page_views}} SET engaged_at = NOW(), confirmed_at = COALESCE(confirmed_at, NOW())'
        . ' WHERE id = :id AND session_id = :session AND engaged_at IS NULL',
        [':id' => $pageViewId, ':session' => $sessionId]
      )->execute();

      if ($engaged < 1) {
        return false;
      }

      $db->createCommand(
        'UPDATE {{%analytics_sessions}} SET engaged_at = COALESCE(engaged_at, NOW()), confirmed_at = COALESCE(confirmed_at, NOW())'
        . ' WHERE session_id = :session',
        [':session' => $sessionId]
      )->execute();

      return true;
    } catch (\Throwable $e) {
      Yii::warning('Interaction not recorded: ' . $e->getMessage(), __METHOD__);

      return false;
    }
  }
}
