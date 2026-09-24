<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\shortlinks\ResolveResult;
use giantbits\crelish\components\shortlinks\ShortLinkCode;
use giantbits\crelish\components\shortlinks\ShortLinkConfig;
use giantbits\crelish\components\shortlinks\ShortLinkResolver;
use giantbits\crelish\models\ShortLink;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Public short link redirect.
 *
 * Deliberately a plain controller: CrelishBaseController redirects anyone who
 * is not an admin. Bots are redirected too; they are flagged through the
 * analytics session and filtered by the nightly aggregation.
 */
class ShortLinkRedirectController extends Controller
{
  public $enableCsrfValidation = false;

  public function beforeAction($action)
  {
    if (!ShortLinkConfig::isEnabled()) {
      throw new NotFoundHttpException();
    }

    return parent::beforeAction($action);
  }

  public function actionIndex(string $code, int $scan = 0): Response
  {
    $code = ShortLinkCode::normalize($code);

    // The URL rule already constrains the code, but this action is reachable
    // directly too; never let an unvalidated code reach the database or the log.
    if (strlen($code) > 64 || !preg_match(ShortLinkCode::PATTERN, $code)) {
      Yii::info('Malformed short link code ' . $this->logCode($code), 'shortlink');
      return $this->send(ShortLinkConfig::siteFallbackUrl());
    }

    try {
      $link = ShortLink::findByCode($code);
    } catch (\Throwable $e) {
      Yii::error('Short link lookup failed for ' . $this->logCode($code) . ': ' . $e->getMessage(), 'shortlink');
      return $this->send(ShortLinkConfig::siteFallbackUrl());
    }

    if ($link === null) {
      Yii::info('Unknown short link code ' . $this->logCode($code), 'shortlink');
      return $this->send(ShortLinkConfig::siteFallbackUrl());
    }

    try {
      $result = (new ShortLinkResolver())->resolve($link, Yii::$app->request->headers->get('Accept-Language'));

      if ($result->reason === ResolveResult::REASON_BROKEN) {
        $this->warnBroken($link);
      }
    } catch (\Throwable $e) {
      Yii::error("Short link resolution failed for '{$link->code}': " . $e->getMessage(), 'shortlink');
      return $this->send($link->fallback_url ?: ShortLinkConfig::siteFallbackUrl());
    }

    $this->track($link, $result->isFallback() ? 'fallback' : ($scan ? 'scan' : 'click'));

    return $this->send($result->url);
  }

  /**
   * Any other path on a dedicated short host
   */
  public function actionHome(): Response
  {
    return $this->send(ShortLinkConfig::homeUrl());
  }

  private function track(ShortLink $link, string $type): void
  {
    try {
      if (Yii::$app->has('crelishAnalytics')) {
        Yii::$app->get('crelishAnalytics')->trackEvent($link->uuid, 'shortlink', $type);
      }
    } catch (\Throwable $e) {
      Yii::error("Short link tracking failed for '{$link->code}': " . $e->getMessage(), 'analytics');
    }
  }

  /**
   * Warn once per link and day; a broken link on a flyer would otherwise flood Sentry.
   *
   * Must never throw: it runs inside actionIndex's resolve() try/catch, and a
   * throw there would skip track() and drop the hit instead of just the dedup.
   * On a cache failure the warning is still emitted, just without dedup.
   */
  private function warnBroken(ShortLink $link): void
  {
    $key = ['shortlink-broken', $link->uuid, date('Y-m-d')];

    try {
      $cache = Yii::$app->getCache();

      if ($cache !== null) {
        if ($cache->get($key)) {
          return;
        }
        $cache->set($key, 1, 86400);
      }
    } catch (\Throwable $e) {
      Yii::error("Short link broken-warning cache failed for '{$link->code}': " . $e->getMessage(), 'shortlink');
    }

    Yii::warning("Short link '{$link->code}' ({$link->uuid}) points to {$link->target_ctype}/{$link->target_uuid}, which cannot be resolved; sent to the fallback", 'shortlink');
  }

  /**
   * A code as taken from the request may contain anything; never write it
   * into the log verbatim (log injection, control characters, length).
   */
  private function logCode(string $code): string
  {
    return json_encode(mb_substr($code, 0, 64));
  }

  private function send(string $url): Response
  {
    // Not $this->redirect(): that defaults checkAjax to true, which swaps the
    // Location header for X-Redirect on an XHR-flagged request and breaks the
    // redirect for clients (in-app browsers, QR readers) that set that header.
    $response = Yii::$app->getResponse()->redirect(ShortLinkConfig::absolute($url), 302, false);
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('X-Robots-Tag', 'noindex');

    return $response;
  }
}
