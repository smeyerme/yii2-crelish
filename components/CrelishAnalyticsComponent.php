<?php

namespace giantbits\crelish\components;

use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Yii;
use yii\base\Component;
use yii\db\Query;
use yii\db\Expression;
use yii\helpers\ArrayHelper;

/**
 * Class CrelishAnalyticsComponent
 * @package giantbits\crelish\components
 */
class CrelishAnalyticsComponent extends Component
{
  /**
   * @var bool Whether to track analytics
   */
  public $enabled = true;

  /**
   * @var array IPs to exclude from tracking
   */
  public $excludeIps = [];

  /**
   * @var string Session key for analytics
   */
  private $_sessionKey = 'analytics_session_id';

  /**
   * @var string Current session ID
   */
  private $_sessionId;

  /**
   * @var CrawlerDetect Bot detector instance
   */
  private $_botDetector;

  /**
   * Initialize the component
   */
  public function init()
  {
    parent::init();

    // Initialize bot detector
    $this->_botDetector = new CrawlerDetect();

    // Start a session if not already started
    if (Yii::$app->session->isActive === false) {
      Yii::$app->session->open();
    }

    // Generate or retrieve session ID
    $this->_sessionId = $this->getSessionId();
  }

  /**
   * Get or create a session ID
   * @return string
   */
  public function getSessionId()
  {
    if (!Yii::$app->session->has($this->_sessionKey)) {
      $sessionId = Yii::$app->security->generateRandomString(32);
      Yii::$app->session->set($this->_sessionKey, $sessionId);
      return $sessionId;
    }

    return Yii::$app->session->get($this->_sessionKey);
  }

  /**
   * Track a page view
   * @param array $pageData
   * @return bool
   */
  public function trackPageView($pageData)
  {

    // Always track, but mark bot status correctly
    $isBot = $this->isBot();

    if (!$this->enabled || $this->shouldExclude()) {
      return false;
    }

    // Get user details
    $userId = !Yii::$app->user->isGuest ? Yii::$app->user->id : null;
    $ip = Yii::$app->request->userIP;
    $userAgent = Yii::$app->request->userAgent;
    $referer = Yii::$app->request->referrer;
    $url = Yii::$app->request->absoluteUrl;

    // Update session data
    $this->updateSession([
      'session_id' => $this->_sessionId,
      'user_id' => $userId,
      'ip_address' => $ip,
      'user_agent' => $userAgent,
      'is_bot' => $isBot,
      'first_page_uuid' => $pageData['uuid'],
      'first_url' => $url
    ]);

    // Track the page view
    return Yii::$app->db->createCommand()->insert('analytics_page_views', [
      'page_uuid' => $pageData['uuid'],
      'page_type' => $pageData['ctype'],
      'url' => $url,
      'referer' => $referer,
      'session_id' => $this->_sessionId,
      'user_id' => $userId,
      'user_agent' => $userAgent,
      'ip_address' => $ip,
      'is_bot' => $isBot,
      'created_at' => new Expression('NOW()')
    ])->execute();
  }

  /**
   * Track a content element view
   * @param string $elementUuid
   * @param string $elementType
   * @param string $pageUuid
   * @return bool
   */
  public function trackElementView($elementUuid, $elementType, $pageUuid, $type = null)
  {
    if (!$this->enabled || $this->shouldExclude()) {
      return false;
    }

    $userId = !Yii::$app->user->isGuest ? Yii::$app->user->id : null;

    return Yii::$app->db->createCommand()->insert('analytics_element_views', [
      'element_uuid' => $elementUuid,
      'element_type' => $elementType,
      'page_uuid' => $pageUuid,
      'session_id' => $this->_sessionId,
      'user_id' => $userId,
      'created_at' => new Expression('NOW()'),
      'type' => $type
    ])->execute();
  }

  /**
   * Update session data
   * @param array $data
   * @return bool
   */
  private function updateSession($data)
  {
    // Check if session already exists
    $session = (new Query())
      ->from('analytics_sessions')
      ->where(['session_id' => $this->_sessionId])
      ->one();

    if ($session) {
      // Update existing session
      $updateData = ['total_pages' => new Expression('total_pages + 1')];

      // If existing session wasn't marked as bot but current request is bot, update it
      if (!$session['is_bot'] && $data['is_bot']) {
        $updateData['is_bot'] = 1;
      }

      return Yii::$app->db->createCommand()->update('analytics_sessions',
        $updateData,
        ['session_id' => $this->_sessionId]
      )->execute();
    } else {
      // Create new session
      return Yii::$app->db->createCommand()->insert('analytics_sessions', $data)->execute();
    }
  }

  /**
   * Check if the current request should be excluded
   * @return bool
   */
  private function shouldExclude()
  {
    // Exclude specific IPs
    if (in_array(Yii::$app->request->userIP, $this->excludeIps)) {
      return true;
    }

    // You can add more exclusion rules here

    return false;
  }

  /**
   * Check if the current request is from a bot
   * @param string|null $userAgent Optional user agent to check
   * @return bool
   */
  public function isBot($userAgent = null)
  {
    if ($userAgent === null) {
      $userAgent = Yii::$app->request->userAgent;
    }

    // Empty or very short user agents are suspicious
    if (empty($userAgent) || strlen($userAgent) < 30) {
      return true;
    }

    // First check with the crawler-detect library
    if ($this->_botDetector->isCrawler($userAgent)) {
      return true;
    }

    // Custom rules for edge cases not caught by crawler-detect

    // Email in user agent
    if (preg_match('/@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $userAgent)) {
      return true;
    }

    // Very old Chrome/Firefox versions (< 100)
    if (preg_match('/Chrome\/[0-9]{1,2}\./', $userAgent) ||
      preg_match('/Firefox\/[0-9]{1,2}\./', $userAgent)) {
      return true;
    }

    // Old Internet Explorer (6, 7, 8, 9)
    if (preg_match('/MSIE [6789]\.0/', $userAgent)) {
      return true;
    }

    // Incomplete Chrome user agents
    if (strpos($userAgent, 'Chrome/') !== false &&
      strpos($userAgent, 'Safari/537.36') === false) {
      return true;
    }

    // CCleaner
    if (strpos($userAgent, 'CCleaner') !== false) {
      return true;
    }

    // Check for Chrome 100.0.x.x pattern (likely spoofed)
    if (preg_match('/Chrome\/100\.0\.[0-9]+\.[0-9]+/', $userAgent)) {
      return true;
    }

    // Check for malformed Mozilla strings
    if (preg_match('/Mozilla\/5\.0(101|084|066)/', $userAgent)) {
      return true;
    }

    // Check for Mozilla/5.0 with nothing after it
    if ($userAgent === 'Mozilla/5.0' || preg_match('/^Mozilla\/[0-9]+\.[0-9]+$/', $userAgent)) {
      return true;
    }

    // Optional: Check against your custom bot database
    // (You might want to remove this if using crawler-detect)
    /*
    $botPattern = (new Query())
      ->select('user_agent_pattern')
      ->from('analytics_bots')
      ->all();

    foreach ($botPattern as $pattern) {
      if (stripos($userAgent, $pattern['user_agent_pattern']) !== false) {
        return true;
      }
    }
    */

    return false;
  }

  /**
   * Get page view statistics for a given period
   * @param string $period day|week|month|year
   * @param bool $excludeBots
   * @param bool $uniqueVisitors Only count unique visitors (by IP + session_id)
   * @return array
   */
  public function getPageViewStats($period = 'month', $excludeBots = true, $uniqueVisitors = false)
  {
    $dateExpression = $this->getDateExpressionForPeriod($period);
    $startDate = $this->getPeriodStartDate($period);

    $query = (new Query());

    if ($uniqueVisitors) {
      // For unique visitors, count distinct user_id or ip_address
      $query->select([
        'date' => $dateExpression,
        'views' => 'COUNT(DISTINCT COALESCE(user_id, ip_address))'
      ]);
    } else {
      // For total views, count all records
      $query->select([
        'date' => $dateExpression,
        'views' => 'COUNT(*)'
      ]);
    }

    $query->from('analytics_page_views');

    if ($excludeBots) {
      $query->where(['is_bot' => 0]);
    }

    // Add the date filter
    $query->andWhere(['>=', 'created_at', $startDate]);

    return $query->groupBy(['date'])
      ->orderBy(['date' => SORT_ASC])
      ->all();
  }

  /**
   * Get top viewed pages for a given period
   * @param string $period day|week|month|year
   * @param int $limit
   * @param bool $excludeBots
   * @param bool $uniqueVisitors Only count unique visitors (by IP + session_id)
   * @return array
   */
  public function getTopPages($period = 'month', $limit = 10, $excludeBots = true, $uniqueVisitors = false)
  {
    $query = (new Query());
    
    if ($uniqueVisitors) {
      // For unique visitors, count distinct combinations of IP and session_id per page
      $query->select([
        'page_uuid',
        'page_type',
        'url',
        'views' => 'COUNT(DISTINCT CONCAT(ip_address, "-", session_id))'
      ]);
    } else {
      // For total views, count all records
      $query->select([
        'page_uuid',
        'page_type',
        'url',
        'views' => 'COUNT(*)'
      ]);
    }
    
    $query->from('analytics_page_views');

    if ($excludeBots) {
      $query->where(['is_bot' => 0]);
    }

    if ($period !== 'all') {
      $query->andWhere(['>=', 'created_at', $this->getPeriodStartDate($period)]);
    }

    return $query->groupBy(['page_uuid'])
      ->orderBy(['views' => SORT_DESC])
      ->limit($limit)
      ->all();
  }

  /**
   * Get most viewed elements
   * @param string $period day|week|month|year
   * @param int $limit
   * @param string|null $type Optional filter for view type (e.g., 'download', 'list', 'detail')
   * @return array
   */
  public function getTopElements($period = 'month', $limit = 10, $type = null)
  {
    $query = (new Query())
      ->select([
        'element_uuid',
        'element_type',
        'type',
        'views' => 'COUNT(*)'
      ])
      ->from('analytics_element_views');

    // Base where conditions
    $conditions = [];
    
    if ($period !== 'all') {
      $conditions[] = ['>=', 'created_at', $this->getPeriodStartDate($period)];
    }
    
    // Add type filter if specified
    if ($type !== null) {
      $conditions[] = ['type' => $type];
    }
    
    // Apply all conditions
    if (!empty($conditions)) {
      // If we have only one condition, apply it directly
      if (count($conditions) === 1) {
        $query->where($conditions[0]);
      } else {
        // If we have multiple conditions, use 'and' operator
        $query->where(array_shift($conditions));
        foreach ($conditions as $condition) {
          $query->andWhere($condition);
        }
      }
    }

    return $query->groupBy(['element_uuid'])
      ->orderBy(['views' => SORT_DESC])
      ->limit($limit)
      ->all();
  }

  /**
   * Get the date expression for a given period for SQL grouping
   * @param string $period
   * @return Expression
   */
  private function getDateExpressionForPeriod($period)
  {
    switch ($period) {
      case 'day':
        return new Expression('DATE_FORMAT(created_at, "%Y-%m-%d %H:00")');
      case 'week':
        return new Expression('DATE_FORMAT(created_at, "%Y-%m-%d")');
      case 'month':
        return new Expression('DATE_FORMAT(created_at, "%Y-%m-%d")');
      case 'year':
        return new Expression('DATE_FORMAT(created_at, "%Y-%m")');
      default:
        return new Expression('DATE_FORMAT(created_at, "%Y-%m-%d")');
    }
  }

  /**
   * Get the start date for a given period
   * @param string $period
   * @return string
   */
  public function getPeriodStartDate($period)
  {
    switch ($period) {
      case 'day':
        return date('Y-m-d 00:00:00');
      case 'week':
        return date('Y-m-d 00:00:00', strtotime('-7 days'));
      case 'month':
        return date('Y-m-d 00:00:00', strtotime('-30 days'));
      case 'year':
        return date('Y-m-d 00:00:00', strtotime('-365 days'));
      default:
        return date('Y-m-d 00:00:00', strtotime('-30 days'));
    }
  }

  /**
   * Check if a user agent string is a bot (public method for external use)
   * @param string $userAgent
   * @return bool
   */
  public function checkIsBot($userAgent)
  {
    return $this->isBot($userAgent);
  }

  /**
   * Get the bot detector instance (for advanced usage)
   * @return CrawlerDetect
   */
  public function getBotDetector()
  {
    return $this->_botDetector;
  }
}