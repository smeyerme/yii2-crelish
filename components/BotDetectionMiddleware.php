<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\Behavior;
use yii\web\Controller;

/**
 * BotDetectionMiddleware - Enhanced bot detection for analytics
 */
class BotDetectionMiddleware extends Behavior
{
  /**
   * @inheritdoc
   */
  public function events()
  {
    return [
      Controller::EVENT_BEFORE_ACTION => 'detectBot',
    ];
  }

  /**
   * Detect if the current request is from a bot
   * @param \yii\base\ActionEvent $event
   */
  public function detectBot($event)
  {
    // Don't run in console applications
    if (Yii::$app instanceof \yii\console\Application) {
      return;
    }

    // Check if analytics is enabled
    if (!isset(Yii::$app->crelishAnalytics) || !Yii::$app->crelishAnalytics->enabled) {
      return;
    }

    // Skip for AJAX requests
    if (Yii::$app->request->isAjax) {
      return;
    }

    // Advanced bot detection based on multiple factors
    $isBot = $this->advancedBotDetection();

    // Store the bot status in the session for future reference
    if ($isBot) {
      $sessionId = Yii::$app->crelishAnalytics->getSessionId();
      Yii::$app->db->createCommand()->update('analytics_sessions', [
        'is_bot' => 1
      ], [
        'session_id' => $sessionId
      ])->execute();
    }
  }

  /**
   * Advanced bot detection based on multiple factors
   * @return bool
   */
  protected function advancedBotDetection()
  {
    $request = Yii::$app->request;
    $userAgent = $request->userAgent;

    // 1. Check for empty or suspicious user agents
    if (empty($userAgent) || strlen($userAgent) < 5) {
      return true;
    }

    // 2. Check common bot user agent patterns
    $botPatterns = [
      'bot', 'spider', 'crawl', 'slurp', 'scrape', 'fetch',
      'apache-http', 'monitoring', 'scan', 'check',
      'curl', 'wget', 'python-requests', 'library', 'tool',
      'headless', 'phantomjs', 'selenium', 'chrome-lighthouse'
    ];

    foreach ($botPatterns as $pattern) {
      if (stripos($userAgent, $pattern) !== false) {
        return true;
      }
    }

    // 3. Check for headless browsers and automation tools
    if (
      stripos($userAgent, 'headless') !== false ||
      stripos($userAgent, 'phantomjs') !== false ||
      stripos($userAgent, 'selenium') !== false ||
      stripos($userAgent, 'puppeteer') !== false
    ) {
      return true;
    }

    // 4. Check for missing common headers usually sent by real browsers
    $acceptLanguage = $request->headers->get('Accept-Language');
    if (empty($acceptLanguage)) {
      // Most legitimate browsers send Accept-Language
      return true;
    }

    // 5. Check for suspicious IP addresses
    // (You could extend this with a list of known bot IPs)

    // 6. Behavioral checks - high request frequency
    // This requires tracking request counts per IP over time, which is beyond
    // the scope of this middleware but could be implemented as an extension.

    return false;
  }
}