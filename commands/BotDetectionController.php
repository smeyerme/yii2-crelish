<?php

namespace giantbits\crelish\commands;

use giantbits\crelish\components\DatacenterIpService;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

class BotDetectionController extends Controller
{
  /**
   * Score thresholds for confidence levels
   * HIGH (70+): Delete immediately - clearly bot
   * MEDIUM (30-69): Flag for review - suspicious but uncertain
   * LOW (0-29): Keep - edge cases, possibly legitimate
   */
  const SCORE_HIGH_CONFIDENCE = 70;
  const SCORE_MEDIUM_CONFIDENCE = 30;

  /**
   * Score contributions for different detection methods
   */
  const SCORE_KNOWN_BOT = 50;         // CrawlerDetect library match
  const SCORE_OUTDATED_BROWSER = 35;  // Old browser/OS version
  const SCORE_DATACENTER_IP = 35;     // From cloud provider
  const SCORE_ROBOTIC_TIMING = 40;    // Consistent timing patterns
  const SCORE_HIGH_VOLUME = 35;       // Excessive requests
  const SCORE_SEQUENTIAL_CRAWL = 40;  // Sequential pagination
  const SCORE_SYSTEMATIC_CRAWL = 35;  // High URL diversity
  const SCORE_IP_VOLUME_ANOMALY = 30; // Many sessions from same IP
  const SCORE_SINGLE_PAGE_SESSION = 20; // Session with only 1 page view

  /**
   * @var DatacenterIpService|null Datacenter IP detection service
   */
  protected ?DatacenterIpService $_datacenterIpService = null;

  /**
   * @var int Number of records to process in each batch
   */
  public $batchSize = 1000;

  /**
   * @var bool Whether to run in dry-run mode (no updates)
   */
  public $dryRun = false;

  /**
   * @var array Session scores being calculated (session_id => ['score' => int, 'reasons' => []])
   */
  protected array $sessionScores = [];

  /**
   * @var array Configurable thresholds for bot detection
   */
  protected $thresholds = [
    // Volume-based thresholds
    'session_requests_per_hour' => 500,
    'session_requests_per_day' => 2000,
    'ip_requests_per_hour' => 1000,
    'ip_requests_per_day' => 5000,

    // Timing-based thresholds
    'min_human_interval_seconds' => 1,
    'max_requests_per_minute' => 30,
    'consistent_interval_threshold' => 2, // Standard deviation

    // Pattern-based thresholds
    'url_diversity_threshold' => 0.95, // 95% unique URLs = bot
    'sequential_page_threshold' => 5,
    'min_requests_for_pattern_detection' => 50,

    // Browser version thresholds
    'min_chrome_version' => 100,
    'min_firefox_version' => 100,
    'min_safari_version' => 600,
    'min_ios_version' => 16,
    'min_android_version' => 10,
  ];

  /**
   * Define command options
   */
  public function options($actionID)
  {
    return array_merge(parent::options($actionID), [
      'batchSize',
      'dryRun',
    ]);
  }

  /**
   * Define option aliases
   */
  public function optionAliases()
  {
    return array_merge(parent::optionAliases(), [
      'b' => 'batchSize',
      'd' => 'dryRun',
    ]);
  }

  /**
   * Main entry point - runs all detection methods with confidence scoring
   */
  public function actionIndex()
  {
    $this->stdout("Starting confidence-based bot detection process...\n", Console::FG_GREEN);
    $this->stdout(sprintf(
      "Thresholds: HIGH >= %d (delete), MEDIUM >= %d (review), LOW < %d (keep)\n",
      self::SCORE_HIGH_CONFIDENCE,
      self::SCORE_MEDIUM_CONFIDENCE,
      self::SCORE_MEDIUM_CONFIDENCE
    ), Console::FG_YELLOW);

    if ($this->dryRun) {
      $this->stdout("Running in DRY RUN mode - no changes will be made\n", Console::FG_YELLOW);
    }

    // Load custom thresholds if available
    $this->loadCustomThresholds();

    // Reset session scores
    $this->sessionScores = [];

    // Phase 1: Collect scores from all detection methods
    // 1. Orphan sessions (sessions with no page views - definitely garbage)
    $this->stdout("\n[1/10] Detecting orphan sessions...\n", Console::FG_CYAN);
    $this->scoreOrphanSessions();

    // 2. User agent based detection (known bots + outdated browsers)
    $this->stdout("\n[2/10] Processing user agent-based detection...\n", Console::FG_CYAN);
    $this->scoreUserAgents();

    // 3. Volume-based anomaly detection
    $this->stdout("\n[3/10] Detecting high-volume anomalies...\n", Console::FG_CYAN);
    $this->scoreVolumeAnomalies();

    // 4. Timing pattern detection
    $this->stdout("\n[4/10] Analyzing timing patterns...\n", Console::FG_CYAN);
    $this->scoreTimingPatterns();

    // 5. Crawling pattern detection
    $this->stdout("\n[5/10] Detecting crawling patterns...\n", Console::FG_CYAN);
    $this->scoreCrawlingPatterns();

    // 6. Single-page session detection
    $this->stdout("\n[6/10] Scoring single-page sessions...\n", Console::FG_CYAN);
    $this->scoreSinglePageSessions();

    // 7. Datacenter IP detection
    if ($this->thresholds['enable_datacenter_ip_detection'] ?? true) {
      $this->stdout("\n[7/10] Detecting datacenter IPs...\n", Console::FG_CYAN);
      $this->scoreDatacenterIps();
    } else {
      $this->stdout("\n[7/10] Datacenter IP detection disabled, skipping...\n", Console::FG_YELLOW);
    }

    // Phase 2: Apply combination bonuses
    $this->stdout("\n[8/10] Applying combination bonuses...\n", Console::FG_CYAN);
    $this->applyComboBoosts();

    // Phase 3: Commit scores to database
    $this->stdout("\n[9/10] Committing confidence scores...\n", Console::FG_CYAN);
    $this->commitScores();

    // Phase 4: Delete only HIGH confidence bots
    $this->stdout("\n[10/10] Deleting high-confidence bot traffic...\n", Console::FG_CYAN);
    $this->deleteHighConfidenceBots();

    // Show summary
    $this->showDetectionSummary();

    return ExitCode::OK;
  }

  /**
   * Load custom thresholds from config file if exists
   */
  protected function loadCustomThresholds()
  {
    $configFile = Yii::getAlias('@app/config/bot-detection-thresholds.php');
    if (file_exists($configFile)) {
      $customThresholds = require $configFile;
      $this->thresholds = array_merge($this->thresholds, $customThresholds);
      $this->stdout("Loaded custom thresholds from config file\n", Console::FG_YELLOW);
    }
  }

  /**
   * Score sessions with no page views (orphan sessions)
   * These are completely useless and should always be deleted
   */
  protected function scoreOrphanSessions(): void
  {
    $db = Yii::$app->db;
    $scored = 0;

    $this->stdout("Finding sessions without page views...\n");

    // Find all sessions that have zero page views
    $orphanSessions = $db->createCommand("
      SELECT s.session_id
      FROM analytics_sessions s
      LEFT JOIN analytics_page_views pv ON s.session_id = pv.session_id
      WHERE s.is_bot = 0
        AND pv.id IS NULL
    ")->queryColumn();

    foreach ($orphanSessions as $sessionId) {
      // Give maximum score - these are definitely garbage
      $this->addScore($sessionId, 100, 'no_page_views');
      $scored++;
    }

    $this->stdout(sprintf("Orphan session scoring: %d sessions scored (100 each)\n", $scored), Console::FG_YELLOW);
  }

  /**
   * Add score to a session
   *
   * @param string $sessionId Session ID
   * @param int $score Score to add
   * @param string $reason Reason for the score
   */
  protected function addScore(string $sessionId, int $score, string $reason): void
  {
    if (!isset($this->sessionScores[$sessionId])) {
      $this->sessionScores[$sessionId] = [
        'score' => 0,
        'reasons' => [],
      ];
    }

    $this->sessionScores[$sessionId]['score'] += $score;
    $this->sessionScores[$sessionId]['reasons'][] = $reason;
  }

  /**
   * Get confidence level from score
   */
  protected function getConfidenceLevel(int $score): string
  {
    if ($score >= self::SCORE_HIGH_CONFIDENCE) {
      return 'high';
    } elseif ($score >= self::SCORE_MEDIUM_CONFIDENCE) {
      return 'medium';
    }
    return 'low';
  }

  /**
   * Score sessions based on user agent analysis
   */
  protected function scoreUserAgents(): void
  {
    $detector = new CrawlerDetect;
    $db = Yii::$app->db;

    $totalProcessed = 0;
    $knownBots = 0;
    $deadBrowsers = 0;
    $outdatedBrowsers = 0;
    $offset = 0;

    do {
      $records = $db->createCommand("
        SELECT session_id, user_agent
        FROM analytics_sessions
        WHERE is_bot = 0 AND (bot_score IS NULL OR bot_score < :threshold)
        LIMIT :limit OFFSET :offset
      ")
        ->bindValue(':threshold', self::SCORE_HIGH_CONFIDENCE)
        ->bindValue(':limit', $this->batchSize)
        ->bindValue(':offset', $offset)
        ->queryAll();

      if (empty($records)) {
        break;
      }

      foreach ($records as $record) {
        $userAgent = $record['user_agent'] ?? '';

        // Check for known bot via CrawlerDetect
        if ($detector->isCrawler($userAgent)) {
          $this->addScore($record['session_id'], self::SCORE_KNOWN_BOT, 'known_bot');
          $knownBots++;
        }

        // Check for completely dead browsers (hard cutoff - always 50 points)
        if ($this->hasDeadBrowser($userAgent)) {
          $this->addScore($record['session_id'], 50, 'dead_browser');
          $deadBrowsers++;
        } else {
          // Calculate age-based score for outdated but not dead browsers
          $ageScore = $this->getBrowserAgeScore($userAgent);
          if ($ageScore > 0) {
            $this->addScore($record['session_id'], $ageScore, 'outdated_browser:' . $ageScore);
            $outdatedBrowsers++;
          }
        }

        // Check for custom bot patterns
        if ($this->hasCustomBotPattern($userAgent)) {
          $this->addScore($record['session_id'], self::SCORE_KNOWN_BOT, 'custom_bot_pattern');
          $knownBots++;
        }
      }

      $totalProcessed += count($records);
      $offset += $this->batchSize;

      $this->stdout(sprintf("Processed %d sessions...\n", $totalProcessed));

    } while (count($records) == $this->batchSize);

    $this->stdout(sprintf(
      "User agent analysis: %d sessions, %d known bots, %d dead browsers, %d outdated browsers\n",
      $totalProcessed, $knownBots, $deadBrowsers, $outdatedBrowsers
    ), Console::FG_YELLOW);
  }

  /**
   * Check for custom bot patterns in user agent
   */
  protected function hasCustomBotPattern(string $userAgent): bool
  {
    // Email in user agent
    if (preg_match('/@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $userAgent)) {
      return true;
    }

    // Incomplete Chrome user agents (missing Safari/537.36)
    if (strpos($userAgent, 'Chrome/') !== false &&
      strpos($userAgent, 'Safari/537.36') === false) {
      return true;
    }

    // CCleaner
    if (strpos($userAgent, 'CCleaner') !== false) {
      return true;
    }

    // Any old IE
    if (preg_match('/MSIE (?:[67]\.|8\.0|9\.0)/', $userAgent)) {
      return true;
    }

    // iOS 13.2.3 - Well-known bot fingerprint (exact version from 2019)
    // This specific version appears disproportionately often in bot traffic
    if (strpos($userAgent, 'iPhone OS 13_2_3') !== false) {
      return true;
    }

    // Sec-CH-UA format being used as User-Agent (bot misconfiguration)
    // Real browsers send this as a header, not in the UA string
    // Pattern: starts with "Brand";v="version" format
    if (preg_match('/^"[^"]+";v="\d+"/', $userAgent)) {
      return true;
    }

    // HeadlessChrome - automated browser
    if (strpos($userAgent, 'HeadlessChrome') !== false) {
      return true;
    }

    // PhantomJS - headless browser often used for scraping
    if (strpos($userAgent, 'PhantomJS') !== false) {
      return true;
    }

    // Puppeteer default UA contains "HeadlessChrome" but some modify it
    // Check for puppeteer-specific patterns
    if (preg_match('/Chrome\/\d+\.\d+\.\d+\.\d+ Safari\/537\.36$/', $userAgent) &&
      strpos($userAgent, 'Mozilla/5.0') === 0 &&
      strpos($userAgent, 'AppleWebKit') !== false &&
      strpos($userAgent, '(') === false) {
      // Suspiciously clean UA without OS info
      return true;
    }

    return false;
  }

  /**
   * Score sessions based on volume anomalies
   */
  protected function scoreVolumeAnomalies(): void
  {
    $db = Yii::$app->db;
    $scored = 0;

    // Check hourly volumes
    $this->stdout("Checking hourly request volumes...\n");
    $hourlyAnomalies = $db->createCommand("
      SELECT s.session_id, COUNT(*) as request_count
      FROM analytics_sessions s
      JOIN analytics_page_views pv ON s.session_id = pv.session_id
      WHERE s.is_bot = 0
        AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
      GROUP BY s.session_id
      HAVING request_count > :threshold
    ")
      ->bindValue(':threshold', $this->thresholds['session_requests_per_hour'])
      ->queryAll();

    foreach ($hourlyAnomalies as $anomaly) {
      $this->addScore($anomaly['session_id'], self::SCORE_HIGH_VOLUME, 'high_volume_hourly');
      $scored++;
    }

    // Check daily volumes
    $this->stdout("Checking daily request volumes...\n");
    $dailyAnomalies = $db->createCommand("
      SELECT s.session_id, COUNT(*) as request_count
      FROM analytics_sessions s
      JOIN analytics_page_views pv ON s.session_id = pv.session_id
      WHERE s.is_bot = 0
        AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
      GROUP BY s.session_id
      HAVING request_count > :threshold
    ")
      ->bindValue(':threshold', $this->thresholds['session_requests_per_day'])
      ->queryAll();

    foreach ($dailyAnomalies as $anomaly) {
      $this->addScore($anomaly['session_id'], self::SCORE_HIGH_VOLUME, 'high_volume_daily');
      $scored++;
    }

    // Check URL diversity (systematic crawlers)
    $this->stdout("Checking URL diversity patterns...\n");
    $crawlers = $db->createCommand("
      SELECT s.session_id, COUNT(*) as total_requests,
        COUNT(DISTINCT pv.url) / COUNT(*) as url_diversity
      FROM analytics_sessions s
      JOIN analytics_page_views pv ON s.session_id = pv.session_id
      WHERE s.is_bot = 0
        AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
      GROUP BY s.session_id
      HAVING total_requests > :min_requests
        AND url_diversity > :diversity_threshold
    ")
      ->bindValue(':min_requests', $this->thresholds['min_requests_for_pattern_detection'])
      ->bindValue(':diversity_threshold', $this->thresholds['url_diversity_threshold'])
      ->queryAll();

    foreach ($crawlers as $crawler) {
      $this->addScore($crawler['session_id'], self::SCORE_SYSTEMATIC_CRAWL, 'systematic_crawler');
      $scored++;
    }

    // Check IP-based volume
    $this->stdout("Checking IP-based request volumes...\n");
    $ipAnomalies = $db->createCommand("
      SELECT s.ip_address, COUNT(DISTINCT s.session_id) as session_count
      FROM analytics_sessions s
      JOIN analytics_page_views pv ON s.session_id = pv.session_id
      WHERE s.is_bot = 0
        AND s.ip_address IS NOT NULL AND s.ip_address != ''
        AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
      GROUP BY s.ip_address
      HAVING session_count > :max_sessions
    ")
      ->bindValue(':max_sessions', $this->thresholds['max_sessions_per_ip'] ?? 10)
      ->queryAll();

    foreach ($ipAnomalies as $anomaly) {
      // Get all sessions from this IP
      $sessions = $db->createCommand("
        SELECT session_id FROM analytics_sessions
        WHERE ip_address = :ip AND is_bot = 0
      ")
        ->bindValue(':ip', $anomaly['ip_address'])
        ->queryColumn();

      foreach ($sessions as $sessionId) {
        $this->addScore($sessionId, self::SCORE_IP_VOLUME_ANOMALY, 'ip_volume_anomaly');
        $scored++;
      }
    }

    $this->stdout(sprintf("Volume scoring: %d sessions scored\n", $scored), Console::FG_YELLOW);
  }

  /**
   * Score sessions based on timing patterns
   */
  protected function scoreTimingPatterns(): void
  {
    $db = Yii::$app->db;
    $scored = 0;

    $timingAnomalies = $db->createCommand("
      SELECT session_id, AVG(time_diff) as avg_interval,
        STDDEV(time_diff) as stddev_interval, COUNT(*) as interval_count
      FROM (
        SELECT pv.session_id,
          TIMESTAMPDIFF(SECOND,
            LAG(pv.created_at) OVER (PARTITION BY pv.session_id ORDER BY pv.created_at),
            pv.created_at
          ) as time_diff
        FROM analytics_page_views pv
        INNER JOIN analytics_sessions s ON pv.session_id = s.session_id
        WHERE pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
          AND s.is_bot = 0
      ) as intervals
      WHERE time_diff IS NOT NULL AND time_diff < 300
      GROUP BY session_id
      HAVING interval_count > 20
        AND avg_interval < :max_interval
        AND stddev_interval < :consistency_threshold
    ")
      ->bindValue(':max_interval', 10)
      ->bindValue(':consistency_threshold', $this->thresholds['consistent_interval_threshold'])
      ->queryAll();

    foreach ($timingAnomalies as $anomaly) {
      $this->addScore($anomaly['session_id'], self::SCORE_ROBOTIC_TIMING, 'robotic_timing');
      $scored++;
      $this->stdout(sprintf(
        "  Robotic timing: session %s, avg %.2fs (stddev: %.2f)\n",
        substr($anomaly['session_id'], 0, 8),
        $anomaly['avg_interval'],
        $anomaly['stddev_interval']
      ));
    }

    $this->stdout(sprintf("Timing scoring: %d sessions scored\n", $scored), Console::FG_YELLOW);
  }

  /**
   * Score sessions based on crawling patterns
   */
  protected function scoreCrawlingPatterns(): void
  {
    $db = Yii::$app->db;
    $scored = 0;

    $paginationCrawlers = $db->createCommand("
      SELECT pv.session_id,
        GROUP_CONCAT(DISTINCT pv.url ORDER BY pv.created_at) as url_sequence
      FROM analytics_page_views pv
      INNER JOIN analytics_sessions s ON pv.session_id = s.session_id
      WHERE s.is_bot = 0
        AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        AND (pv.url LIKE '%page=%' OR pv.url LIKE '%/page/%' OR pv.url LIKE '%&p=%')
      GROUP BY pv.session_id
      HAVING COUNT(*) > :threshold
    ")
      ->bindValue(':threshold', $this->thresholds['sequential_page_threshold'])
      ->queryAll();

    foreach ($paginationCrawlers as $crawler) {
      if ($this->hasSequentialPattern($crawler['url_sequence'])) {
        $this->addScore($crawler['session_id'], self::SCORE_SEQUENTIAL_CRAWL, 'sequential_crawler');
        $scored++;
      }
    }

    $this->stdout(sprintf("Crawling pattern scoring: %d sessions scored\n", $scored), Console::FG_YELLOW);
  }

  /**
   * Score sessions with only a single page view
   * Single-page sessions are a weak signal on their own but combine with other signals
   * to push suspicious sessions over confidence thresholds
   */
  protected function scoreSinglePageSessions(): void
  {
    $db = Yii::$app->db;
    $scored = 0;

    // Find sessions with exactly 1 page view that aren't already marked as bots
    // No time limit - single page sessions are suspicious regardless of age
    $singlePageSessions = $db->createCommand("
      SELECT s.session_id, COUNT(pv.id) as page_count
      FROM analytics_sessions s
      LEFT JOIN analytics_page_views pv ON s.session_id = pv.session_id
      WHERE s.is_bot = 0
      GROUP BY s.session_id
      HAVING page_count = 1
    ")->queryAll();

    foreach ($singlePageSessions as $session) {
      $this->addScore($session['session_id'], self::SCORE_SINGLE_PAGE_SESSION, 'single_page_session');
      $scored++;
    }

    $this->stdout(sprintf("Single-page session scoring: %d sessions scored (+%d points each)\n",
      $scored, self::SCORE_SINGLE_PAGE_SESSION), Console::FG_YELLOW);
  }

  /**
   * Score sessions from datacenter IPs
   */
  protected function scoreDatacenterIps(): void
  {
    $db = Yii::$app->db;
    $scored = 0;
    $providerCounts = [];

    $service = $this->getDatacenterIpService();

    $this->stdout("Loading datacenter IP ranges...\n");
    $rangeCount = $service->refreshCache();
    $this->stdout(sprintf("Loaded %d IP ranges\n", $rangeCount));

    $offset = 0;
    $totalChecked = 0;

    do {
      $sessions = $db->createCommand("
        SELECT DISTINCT s.session_id, s.ip_address
        FROM analytics_sessions s
        WHERE s.is_bot = 0
          AND s.ip_address IS NOT NULL AND s.ip_address != ''
        LIMIT :limit OFFSET :offset
      ")
        ->bindValue(':limit', $this->batchSize)
        ->bindValue(':offset', $offset)
        ->queryAll();

      if (empty($sessions)) {
        break;
      }

      foreach ($sessions as $session) {
        $datacenterInfo = $service->isDatacenterIp($session['ip_address']);

        if ($datacenterInfo !== false) {
          $provider = $datacenterInfo['provider'];

          if (!isset($providerCounts[$provider])) {
            $providerCounts[$provider] = 0;
          }
          $providerCounts[$provider]++;

          $this->addScore($session['session_id'], self::SCORE_DATACENTER_IP, 'datacenter_ip:' . $provider);
          $scored++;
        }
      }

      $totalChecked += count($sessions);
      $offset += $this->batchSize;

    } while (count($sessions) == $this->batchSize);

    if (!empty($providerCounts)) {
      arsort($providerCounts);
      $this->stdout("\nDatacenter providers detected:\n");
      foreach (array_slice($providerCounts, 0, 10) as $provider => $count) {
        $this->stdout(sprintf("  %s: %d sessions\n", $provider, $count));
      }
    }

    $this->stdout(sprintf("\nDatacenter IP scoring: %d sessions scored\n", $scored), Console::FG_YELLOW);
  }

  /**
   * Apply combination bonuses for multiple signals
   * Certain combinations are much stronger indicators than individual signals
   * Uses exclusive logic - only the highest applicable combo is applied
   */
  protected function applyComboBoosts(): void
  {
    $boosted = 0;

    $behaviorSignals = [
      'high_volume_hourly', 'high_volume_daily',
      'robotic_timing', 'sequential_crawler',
      'systematic_crawler', 'ip_volume_anomaly'
    ];

    foreach ($this->sessionScores as $sessionId => &$data) {
      $reasons = $data['reasons'];
      $originalScore = $data['score'];

      // Check for datacenter IP
      $hasDatacenter = false;
      foreach ($reasons as $reason) {
        if (str_contains($reason, 'datacenter_ip')) {
          $hasDatacenter = true;
          break;
        }
      }

      // Check for outdated browser (now includes score suffix like "outdated_browser:30")
      $hasOutdated = in_array('dead_browser', $reasons) ||
        count(array_filter($reasons, fn($r) => str_starts_with($r, 'outdated_browser'))) > 0;
      $hasBehavior = count(array_intersect($reasons, $behaviorSignals)) > 0;
      $hasKnownBot = in_array('known_bot', $reasons);
      $hasCustomBot = in_array('custom_bot_pattern', $reasons);
      $hasSinglePage = in_array('single_page_session', $reasons);

      // Apply ONE combo bonus (highest priority first)
      $comboApplied = false;

      // Triple threat: Outdated + Datacenter + Behavior = Absolutely bot (+40)
      if (!$comboApplied && $hasOutdated && $hasDatacenter && $hasBehavior) {
        $data['score'] += 40;
        $data['reasons'][] = 'combo:triple_threat';
        $comboApplied = true;
      }

      // Known bot + Datacenter = Definitely bot (+25)
      if (!$comboApplied && $hasKnownBot && $hasDatacenter) {
        $data['score'] += 25;
        $data['reasons'][] = 'combo:known+datacenter';
        $comboApplied = true;
      }

      // Outdated browser + Datacenter IP = Almost certainly bot (+25)
      if (!$comboApplied && $hasOutdated && $hasDatacenter) {
        $data['score'] += 25;
        $data['reasons'][] = 'combo:outdated+datacenter';
        $comboApplied = true;
      }

      // Outdated browser + Behavioral signal = Very suspicious (+20)
      if (!$comboApplied && $hasOutdated && $hasBehavior) {
        $data['score'] += 20;
        $data['reasons'][] = 'combo:outdated+behavior';
        $comboApplied = true;
      }

      // Datacenter IP + Behavioral signal = Likely bot (+15)
      if (!$comboApplied && $hasDatacenter && $hasBehavior) {
        $data['score'] += 15;
        $data['reasons'][] = 'combo:datacenter+behavior';
        $comboApplied = true;
      }

      // Custom bot pattern + Outdated = Definitely bot (+15)
      if (!$comboApplied && $hasCustomBot && $hasOutdated) {
        $data['score'] += 15;
        $data['reasons'][] = 'combo:custom+outdated';
        $comboApplied = true;
      }

      // Outdated browser + Single-page session = Suspicious (+15)
      // Common bot pattern: hit one page with outdated UA and leave
      if (!$comboApplied && $hasOutdated && $hasSinglePage) {
        $data['score'] += 15;
        $data['reasons'][] = 'combo:outdated+singlepage';
        $comboApplied = true;
      }

      if ($data['score'] > $originalScore) {
        $boosted++;
      }
    }
    unset($data); // Break reference

    $this->stdout(sprintf("Combination bonuses applied to %d sessions\n", $boosted), Console::FG_YELLOW);
  }

  /**
   * Commit all collected scores to the database
   */
  protected function commitScores(): void
  {
    if ($this->dryRun) {
      $this->stdout("DRY RUN: Would commit scores for " . count($this->sessionScores) . " sessions\n");
      return;
    }

    $db = Yii::$app->db;
    $committed = 0;
    $highCount = 0;
    $mediumCount = 0;
    $lowCount = 0;

    foreach ($this->sessionScores as $sessionId => $data) {
      $score = min(100, $data['score']); // Cap at 100
      $reasons = implode(',', array_unique($data['reasons']));
      $confidence = $this->getConfidenceLevel($score);

      // Count by confidence level
      if ($confidence === 'high') {
        $highCount++;
      } elseif ($confidence === 'medium') {
        $mediumCount++;
      } else {
        $lowCount++;
      }

      try {
        // Update session with score
        $updateData = [
          'bot_score' => $score,
          'is_bot' => ($confidence === 'high') ? 1 : 0,
        ];

        if ($this->hasColumn('analytics_sessions', 'bot_reason')) {
          $updateData['bot_reason'] = substr($reasons, 0, 255);
        }

        $db->createCommand()
          ->update('analytics_sessions', $updateData, ['session_id' => $sessionId])
          ->execute();

        // If high confidence, also mark page views
        if ($confidence === 'high') {
          $db->createCommand()
            ->update('analytics_page_views', ['is_bot' => 1], ['session_id' => $sessionId])
            ->execute();
        }

        $committed++;
      } catch (\Exception $e) {
        $this->stderr("Error committing score for {$sessionId}: " . $e->getMessage() . "\n", Console::FG_RED);
      }
    }

    $this->stdout(sprintf(
      "Committed scores: %d total (HIGH: %d, MEDIUM: %d, LOW: %d)\n",
      $committed, $highCount, $mediumCount, $lowCount
    ), Console::FG_YELLOW);
  }

  /**
   * Delete only high-confidence bot traffic
   */
  protected function deleteHighConfidenceBots(): void
  {
    if ($this->dryRun) {
      $this->stdout("DRY RUN: Would delete high-confidence bots\n");
      return;
    }

    $db = Yii::$app->db;

    try {
      // Count before deletion
      $botSessionsCount = $db->createCommand("
        SELECT COUNT(*) FROM analytics_sessions WHERE is_bot = 1
      ")->queryScalar();

      $botPageViewsCount = $db->createCommand("
        SELECT COUNT(*) FROM analytics_page_views WHERE is_bot = 1
      ")->queryScalar();

      // Delete element views from bot sessions
      $deletedElementViews = $db->createCommand("
        DELETE ev FROM analytics_element_views ev
        INNER JOIN analytics_sessions s ON ev.session_id = s.session_id
        WHERE s.is_bot = 1
      ")->execute();

      $this->stdout(sprintf("Deleted %d bot element views\n", $deletedElementViews));

      // Delete bot page views
      $deletedPageViews = $db->createCommand("
        DELETE FROM analytics_page_views WHERE is_bot = 1
      ")->execute();

      $this->stdout(sprintf("Deleted %d bot page views\n", $deletedPageViews));

      // Delete bot sessions
      $deletedSessions = $db->createCommand("
        DELETE FROM analytics_sessions WHERE is_bot = 1
      ")->execute();

      $this->stdout(sprintf("Deleted %d bot sessions\n", $deletedSessions));

      $this->stdout(sprintf(
        "\nHigh-confidence bots deleted: %d sessions, %d page views\n",
        $deletedSessions, $deletedPageViews
      ), Console::FG_YELLOW);

    } catch (\Exception $e) {
      $this->stderr("Error deleting bot traffic: " . $e->getMessage() . "\n", Console::FG_RED);
    }
  }

  /**
   * Process page views table (existing method stays the same)
   */
  protected function processPageViews()
  {
    $detector = new CrawlerDetect;
    $db = Yii::$app->db;

    $totalProcessed = 0;
    $totalBotsFound = 0;
    $offset = 0;

    do {
      $records = $db->createCommand("
                SELECT id, user_agent 
                FROM analytics_page_views 
                WHERE is_bot = 0 
                LIMIT :limit OFFSET :offset
            ")
        ->bindValue(':limit', $this->batchSize)
        ->bindValue(':offset', $offset)
        ->queryAll();

      if (empty($records)) {
        break;
      }

      $botsInBatch = 0;
      $transaction = $db->beginTransaction();

      try {
        foreach ($records as $record) {
          if ($this->isBot($record['user_agent'], $detector)) {
            if (!$this->dryRun) {
              $db->createCommand()
                ->update('analytics_page_views', ['is_bot' => 1], ['id' => $record['id']])
                ->execute();
            }
            $botsInBatch++;
            $totalBotsFound++;
          }
        }

        $transaction->commit();
      } catch (\Exception $e) {
        $transaction->rollBack();
        $this->stderr("Error processing page views batch: " . $e->getMessage() . "\n", Console::FG_RED);
        return false;
      }

      $totalProcessed += count($records);
      $offset += $this->batchSize;

      $this->stdout(sprintf(
        "Processed %d page view records (found %d bots)\n",
        $totalProcessed,
        $botsInBatch
      ));

    } while (count($records) == $this->batchSize);

    $this->stdout(sprintf(
      "Page views: %d records processed, %d bots found (%.2f%%)\n",
      $totalProcessed,
      $totalBotsFound,
      $totalProcessed > 0 ? ($totalBotsFound / $totalProcessed * 100) : 0
    ), Console::FG_YELLOW);

    return ['processed' => $totalProcessed, 'bots' => $totalBotsFound];
  }

  /**
   * Process sessions table (existing method stays the same)
   */
  protected function processSessions()
  {
    $detector = new CrawlerDetect;
    $db = Yii::$app->db;

    $totalProcessed = 0;
    $totalBotsFound = 0;
    $offset = 0;

    do {
      $records = $db->createCommand("
                SELECT session_id, user_agent 
                FROM analytics_sessions 
                WHERE is_bot = 0 
                LIMIT :limit OFFSET :offset
            ")
        ->bindValue(':limit', $this->batchSize)
        ->bindValue(':offset', $offset)
        ->queryAll();

      if (empty($records)) {
        break;
      }

      $botsInBatch = 0;
      $transaction = $db->beginTransaction();

      try {
        foreach ($records as $record) {
          if ($this->isBot($record['user_agent'], $detector)) {
            if (!$this->dryRun) {
              $db->createCommand()
                ->update('analytics_sessions', ['is_bot' => 1], ['session_id' => $record['session_id']])
                ->execute();
            }
            $botsInBatch++;
            $totalBotsFound++;
          }
        }

        $transaction->commit();
      } catch (\Exception $e) {
        $transaction->rollBack();
        $this->stderr("Error processing sessions batch: " . $e->getMessage() . "\n", Console::FG_RED);
        return false;
      }

      $totalProcessed += count($records);
      $offset += $this->batchSize;

      $this->stdout(sprintf(
        "Processed %d session records (found %d bots)\n",
        $totalProcessed,
        $botsInBatch
      ));

    } while (count($records) == $this->batchSize);

    $this->stdout(sprintf(
      "Sessions: %d records processed, %d bots found (%.2f%%)\n",
      $totalProcessed,
      $totalBotsFound,
      $totalProcessed > 0 ? ($totalBotsFound / $totalProcessed * 100) : 0
    ), Console::FG_YELLOW);

    return ['processed' => $totalProcessed, 'bots' => $totalBotsFound];
  }

  /**
   * Detect volume-based anomalies
   */
  protected function detectVolumeAnomalies()
  {
    $db = Yii::$app->db;
    $detectedBots = 0;

    // Check hourly volumes
    $this->stdout("Checking hourly request volumes...\n");
    $hourlyAnomalies = $db->createCommand("
            SELECT 
                s.session_id,
                COUNT(*) as request_count,
                MIN(pv.created_at) as first_request,
                MAX(pv.created_at) as last_request
            FROM analytics_sessions s
            JOIN analytics_page_views pv ON s.session_id = pv.session_id
            WHERE s.is_bot = 0 
                AND pv.is_bot = 0
                AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY s.session_id
            HAVING request_count > :threshold
        ")
      ->bindValue(':threshold', $this->thresholds['session_requests_per_hour'])
      ->queryAll();

    foreach ($hourlyAnomalies as $anomaly) {
      $this->markAsBot($anomaly['session_id'], 'high_volume_hourly');
      $detectedBots++;
    }

    // Check daily volumes
    $this->stdout("Checking daily request volumes...\n");
    $dailyAnomalies = $db->createCommand("
            SELECT 
                s.session_id,
                COUNT(*) as request_count
            FROM analytics_sessions s
            JOIN analytics_page_views pv ON s.session_id = pv.session_id
            WHERE s.is_bot = 0 
                AND pv.is_bot = 0
                AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            GROUP BY s.session_id
            HAVING request_count > :threshold
        ")
      ->bindValue(':threshold', $this->thresholds['session_requests_per_day'])
      ->queryAll();

    foreach ($dailyAnomalies as $anomaly) {
      $this->markAsBot($anomaly['session_id'], 'high_volume_daily');
      $detectedBots++;
    }

    // Check URL diversity (systematic crawlers)
    $this->stdout("Checking URL diversity patterns...\n");
    $crawlers = $db->createCommand("
            SELECT 
                s.session_id,
                COUNT(*) as total_requests,
                COUNT(DISTINCT pv.url) as unique_urls,
                COUNT(DISTINCT pv.url) / COUNT(*) as url_diversity
            FROM analytics_sessions s
            JOIN analytics_page_views pv ON s.session_id = pv.session_id
            WHERE s.is_bot = 0 
                AND pv.is_bot = 0
                AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            GROUP BY s.session_id
            HAVING total_requests > :min_requests
                AND url_diversity > :diversity_threshold
        ")
      ->bindValue(':min_requests', $this->thresholds['min_requests_for_pattern_detection'])
      ->bindValue(':diversity_threshold', $this->thresholds['url_diversity_threshold'])
      ->queryAll();

    foreach ($crawlers as $crawler) {
      $this->markAsBot($crawler['session_id'], 'systematic_crawler');
      $detectedBots++;
    }

    // Check IP-based volume (same IP, many sessions)
    $this->stdout("Checking IP-based request volumes...\n");
    $ipAnomalies = $db->createCommand("
            SELECT
                s.ip_address,
                COUNT(DISTINCT s.session_id) as session_count,
                COUNT(*) as total_requests
            FROM analytics_sessions s
            JOIN analytics_page_views pv ON s.session_id = pv.session_id
            WHERE s.is_bot = 0
                AND pv.is_bot = 0
                AND s.ip_address IS NOT NULL
                AND s.ip_address != ''
                AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            GROUP BY s.ip_address
            HAVING session_count > :max_sessions OR total_requests > :threshold
        ")
      ->bindValue(':max_sessions', $this->thresholds['max_sessions_per_ip'] ?? 10)
      ->bindValue(':threshold', $this->thresholds['ip_requests_per_day'])
      ->queryAll();

    foreach ($ipAnomalies as $anomaly) {
      // Mark all sessions from this IP as bots
      $sessions = $db->createCommand("
                SELECT session_id FROM analytics_sessions
                WHERE ip_address = :ip AND is_bot = 0
            ")
        ->bindValue(':ip', $anomaly['ip_address'])
        ->queryColumn();

      foreach ($sessions as $sessionId) {
        $this->markAsBot($sessionId, 'high_volume_ip');
        $detectedBots++;
      }

      $this->stdout(sprintf(
        "  IP %s: %d sessions, %d requests\n",
        $anomaly['ip_address'],
        $anomaly['session_count'],
        $anomaly['total_requests']
      ));
    }

    $this->stdout(sprintf(
      "Volume anomaly detection: found %d bots\n",
      $detectedBots
    ), Console::FG_YELLOW);
  }

  /**
   * Detect timing-based patterns
   */
  protected function detectTimingPatterns()
  {
    $db = Yii::$app->db;
    $detectedBots = 0;

    $this->stdout("Analyzing request timing patterns...\n");

    // Get sessions with consistent timing intervals
    $timingAnomalies = $db->createCommand("
            SELECT
                session_id,
                AVG(time_diff) as avg_interval,
                STDDEV(time_diff) as stddev_interval,
                COUNT(*) as interval_count
            FROM (
                SELECT
                    pv.session_id,
                    TIMESTAMPDIFF(SECOND,
                        LAG(pv.created_at) OVER (PARTITION BY pv.session_id ORDER BY pv.created_at),
                        pv.created_at
                    ) as time_diff
                FROM analytics_page_views pv
                INNER JOIN analytics_sessions s ON pv.session_id = s.session_id
                WHERE pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                    AND pv.is_bot = 0
                    AND s.is_bot = 0
            ) as intervals
            WHERE time_diff IS NOT NULL
                AND time_diff < 300  -- Ignore gaps > 5 minutes
            GROUP BY session_id
            HAVING interval_count > 20  -- Need enough data points
                AND avg_interval < :max_interval
                AND stddev_interval < :consistency_threshold
        ")
      ->bindValue(':max_interval', 10)  // Max 10 seconds average
      ->bindValue(':consistency_threshold', $this->thresholds['consistent_interval_threshold'])
      ->queryAll();

    foreach ($timingAnomalies as $anomaly) {
      $this->stdout(sprintf(
        "  Bot pattern: session %s, avg interval %.2fs (stddev: %.2f)\n",
        substr($anomaly['session_id'], 0, 8),
        $anomaly['avg_interval'],
        $anomaly['stddev_interval']
      ));
      $this->markAsBot($anomaly['session_id'], 'robotic_timing');
      $detectedBots++;
    }

    $this->stdout(sprintf(
      "Timing pattern detection: found %d bots\n",
      $detectedBots
    ), Console::FG_YELLOW);
  }

  /**
   * Detect crawling patterns
   */
  protected function detectCrawlingPatterns()
  {
    $db = Yii::$app->db;
    $detectedBots = 0;

    // Detect sequential pagination crawling
    $this->stdout("Checking for sequential pagination patterns...\n");

    $paginationCrawlers = $db->createCommand("
            SELECT
                pv.session_id,
                GROUP_CONCAT(DISTINCT pv.url ORDER BY pv.created_at) as url_sequence
            FROM analytics_page_views pv
            INNER JOIN analytics_sessions s ON pv.session_id = s.session_id
            WHERE pv.is_bot = 0
                AND s.is_bot = 0
                AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                AND (pv.url LIKE '%page=%' OR pv.url LIKE '%/page/%' OR pv.url LIKE '%&p=%')
            GROUP BY pv.session_id
            HAVING COUNT(*) > :threshold
        ")
      ->bindValue(':threshold', $this->thresholds['sequential_page_threshold'])
      ->queryAll();

    foreach ($paginationCrawlers as $crawler) {
      if ($this->hasSequentialPattern($crawler['url_sequence'])) {
        $this->markAsBot($crawler['session_id'], 'sequential_crawler');
        $detectedBots++;
      }
    }

    $this->stdout(sprintf(
      "Crawling pattern detection: found %d bots\n",
      $detectedBots
    ), Console::FG_YELLOW);
  }

  /**
   * Get the datacenter IP service instance
   *
   * @return DatacenterIpService
   */
  protected function getDatacenterIpService(): DatacenterIpService
  {
    if ($this->_datacenterIpService === null) {
      $this->_datacenterIpService = new DatacenterIpService();
    }
    return $this->_datacenterIpService;
  }

  /**
   * Detect sessions from datacenter IPs
   */
  protected function detectDatacenterIps(): void
  {
    $db = Yii::$app->db;
    $detectedBots = 0;
    $providerCounts = [];

    $service = $this->getDatacenterIpService();

    // Refresh IP list cache
    $this->stdout("Loading datacenter IP ranges...\n");
    $rangeCount = $service->refreshCache();
    $this->stdout(sprintf("Loaded %d IP ranges\n", $rangeCount));

    // Get unique IPs from non-bot sessions
    $this->stdout("Checking session IPs against datacenter ranges...\n");

    $offset = 0;
    $totalChecked = 0;

    do {
      $sessions = $db->createCommand("
        SELECT DISTINCT s.session_id, s.ip_address
        FROM analytics_sessions s
        WHERE s.is_bot = 0
          AND s.ip_address IS NOT NULL
          AND s.ip_address != ''
        LIMIT :limit OFFSET :offset
      ")
        ->bindValue(':limit', $this->batchSize)
        ->bindValue(':offset', $offset)
        ->queryAll();

      if (empty($sessions)) {
        break;
      }

      $botsInBatch = 0;

      foreach ($sessions as $session) {
        $ip = $session['ip_address'];
        $datacenterInfo = $service->isDatacenterIp($ip);

        if ($datacenterInfo !== false) {
          $provider = $datacenterInfo['provider'];

          // Track provider counts
          if (!isset($providerCounts[$provider])) {
            $providerCounts[$provider] = 0;
          }
          $providerCounts[$provider]++;

          $this->markAsBot($session['session_id'], 'datacenter_ip');
          $botsInBatch++;
          $detectedBots++;
        }
      }

      $totalChecked += count($sessions);
      $offset += $this->batchSize;

      if ($botsInBatch > 0) {
        $this->stdout(sprintf(
          "Checked %d sessions (found %d datacenter IPs)\n",
          $totalChecked,
          $botsInBatch
        ));
      }

    } while (count($sessions) == $this->batchSize);

    // Show provider breakdown
    if (!empty($providerCounts)) {
      arsort($providerCounts);
      $this->stdout("\nDatacenter providers detected:\n");
      foreach (array_slice($providerCounts, 0, 10) as $provider => $count) {
        $this->stdout(sprintf("  %s: %d sessions\n", $provider, $count));
      }
    }

    $this->stdout(sprintf(
      "\nDatacenter IP detection: found %d bots from %d providers\n",
      $detectedBots,
      count($providerCounts)
    ), Console::FG_YELLOW);
  }

  /**
   * Enhanced bot detection with configurable rules
   */
  protected function isBot($userAgent, CrawlerDetect $detector)
  {
    // First check with the library
    if ($detector->isCrawler($userAgent)) {
      return true;
    }

    // Check for dead browsers
    if ($this->hasDeadBrowser($userAgent)) {
      return true;
    }

    // Check for significantly outdated browsers
    if ($this->getBrowserAgeScore($userAgent) >= 40) {
      return true;
    }

    // Custom rules for edge cases
    if ($this->hasCustomBotPattern($userAgent)) {
      return true;
    }

    return false;
  }

  /**
   * Fetch current browser versions from Can I Use data
   * Cache for 7 days to avoid constant API calls
   */
  protected function getCurrentBrowserVersions(): array
  {
    $cache = Yii::$app->cache ?? null;
    $cacheKey = 'browser_versions_cache';

    // Try cache first (7 day TTL)
    if ($cache) {
      $cached = $cache->get($cacheKey);
      if ($cached !== false) {
        return $cached;
      }
    }

    try {
      // Fetch from Can I Use GitHub (most reliable public source)
      $context = stream_context_create([
        'http' => [
          'timeout' => 10,
          'user_agent' => 'CrelishBotDetection/1.0',
        ],
      ]);

      $url = 'https://raw.githubusercontent.com/Fyrd/caniuse/main/data.json';
      $json = @file_get_contents($url, false, $context);

      if ($json) {
        $data = json_decode($json, true);
        if ($data && isset($data['agents'])) {
          $versions = [
            'ios' => $this->extractLatestVersion($data['agents']['ios_saf']['versions'] ?? []),
            'android' => $this->extractLatestVersion($data['agents']['android']['versions'] ?? []),
            'chrome' => $this->extractLatestVersion($data['agents']['chrome']['versions'] ?? []),
            'firefox' => $this->extractLatestVersion($data['agents']['firefox']['versions'] ?? []),
            'safari' => $this->extractLatestVersion($data['agents']['safari']['versions'] ?? []),
          ];

          // Cache for 7 days
          if ($cache) {
            $cache->set($cacheKey, $versions, 604800);
          }

          $this->stdout("Fetched current browser versions from Can I Use\n");
          return $versions;
        }
      }
    } catch (\Exception $e) {
      // Silently fall through to fallback
    }

    // Fallback to hardcoded versions (update annually)
    // As of early 2026: iOS 19, Android 16, Chrome ~145, Firefox ~145, Safari 19
    $versions = [
      'ios' => 19,        // iOS 19 = 2025
      'android' => 16,    // Android 16 = 2025
      'chrome' => 145,    // Chrome ~145 in early 2026
      'firefox' => 145,   // Firefox ~145 in early 2026
      'safari' => 19,     // Safari 19 = 2025
    ];

    // Cache fallback too
    if ($cache) {
      $cache->set($cacheKey, $versions, 604800);
    }

    return $versions;
  }

  /**
   * Extract latest stable version from Can I Use version array
   * Includes sanity checks to prevent false positives from bad data
   */
  protected function extractLatestVersion(array $versions): int
  {
    // Can I Use returns versions like ["130", "131", "132", null, null]
    // Filter out nulls and get the highest numeric value
    $validVersions = array_filter($versions, function ($v) {
      return $v !== null && is_numeric($v);
    });

    if (empty($validVersions)) {
      return 0;
    }

    // Get numeric values only
    $numericVersions = array_map('intval', $validVersions);
    $maxVersion = max($numericVersions);

    // Sanity check: Cap versions at reasonable maximums to prevent false positives
    // These caps should be updated periodically but are generous enough to not cause issues
    // iOS/Safari: cap at 25 (allows for several years of growth from iOS 19)
    // Chrome/Firefox: cap at 200 (releases ~13/year, allows for ~4 years growth)
    // Android: cap at 20 (allows for several years of growth from Android 16)
    return $maxVersion;
  }

  /**
   * Calculate browser age score (0-50 points based on how outdated)
   * Returns higher scores for older browsers
   */
  protected function getBrowserAgeScore($userAgent): int
  {
    $currentVersions = $this->getCurrentBrowserVersions();

    // iOS version check
    if (preg_match('/(?:iPhone OS|CPU OS) (\d+)[_\.]/', $userAgent, $matches)) {
      $version = intval($matches[1]);
      $currentVersion = $currentVersions['ios'] ?? 18;
      $yearsOld = $currentVersion - $version;

      if ($yearsOld >= 6) return 50;   // 6+ years old = ancient
      if ($yearsOld >= 4) return 40;   // 4+ years old = very old
      if ($yearsOld >= 2) return 30;   // 2+ years old = old
      if ($yearsOld >= 1) return 20;   // 1+ year old = somewhat old
      return 0;
    }

    // Android version check
    if (preg_match('/Android (\d+)/', $userAgent, $matches)) {
      $version = intval($matches[1]);
      $currentVersion = $currentVersions['android'] ?? 15;
      $yearsOld = $currentVersion - $version;

      if ($yearsOld >= 6) return 50;   // Android 9 or older = ancient
      if ($yearsOld >= 4) return 40;   // Android 11 = very old
      if ($yearsOld >= 2) return 30;   // Android 13 = old
      if ($yearsOld >= 1) return 20;   // Android 14 = somewhat old
      return 0;
    }

    // Chrome version check (releases every 4 weeks, ~13/year)
    if (preg_match('/Chrome\/(\d+)\./', $userAgent, $matches)) {
      $version = intval($matches[1]);
      $currentVersion = $currentVersions['chrome'] ?? 131;
      $versionsBehind = $currentVersion - $version;

      if ($versionsBehind >= 52) return 50;  // 4+ years old
      if ($versionsBehind >= 26) return 40;  // 2+ years old
      if ($versionsBehind >= 13) return 30;  // 1+ year old
      if ($versionsBehind >= 6) return 20;   // 6+ months old
      return 0;
    }

    // Firefox version check (similar release cycle to Chrome)
    if (preg_match('/Firefox\/(\d+)\./', $userAgent, $matches)) {
      $version = intval($matches[1]);
      $currentVersion = $currentVersions['firefox'] ?? 133;
      $versionsBehind = $currentVersion - $version;

      if ($versionsBehind >= 52) return 50;  // 4+ years old
      if ($versionsBehind >= 26) return 40;  // 2+ years old
      if ($versionsBehind >= 13) return 30;  // 1+ year old
      if ($versionsBehind >= 6) return 20;   // 6+ months old
      return 0;
    }

    // Safari desktop version check (Version/X.Y...Safari)
    if (preg_match('/Version\/(\d+)\.\d+.*Safari/', $userAgent, $matches)) {
      $version = intval($matches[1]);
      $currentVersion = $currentVersions['safari'] ?? 18;
      $yearsOld = $currentVersion - $version;

      if ($yearsOld >= 5) return 50;   // Safari 13 or older
      if ($yearsOld >= 3) return 40;   // Safari 15
      if ($yearsOld >= 2) return 30;   // Safari 16
      if ($yearsOld >= 1) return 20;   // Safari 17
      return 0;
    }

    return 0;
  }

  /**
   * Check for completely dead browsers (always suspicious)
   * These get a hard 50 points regardless of version
   */
  protected function hasDeadBrowser($userAgent): bool
  {
    // Opera Mini (all versions - mostly bots now)
    if (str_contains($userAgent, 'Opera Mini')) {
      return true;
    }

    // Old Opera versions (8.x, 9.x)
    if (preg_match('/Opera\/[89]\./', $userAgent)) {
      return true;
    }

    // Windows XP/Vista (NT 5.x, NT 6.0) - dead OSes
    if (preg_match('/Windows NT [56]\.[01]/', $userAgent)) {
      return true;
    }

    // Konqueror (dead browser)
    if (str_contains($userAgent, 'Konqueror')) {
      return true;
    }

    // PaleMoon browser (often used by bots)
    if (str_contains($userAgent, 'PaleMoon')) {
      return true;
    }

    // Old Trident/IE 11
    if (preg_match('/Trident\/7\.0/', $userAgent)) {
      return true;
    }

    // Any old IE
    if (preg_match('/MSIE (?:[67]\.|8\.0|9\.0)/', $userAgent)) {
      return true;
    }

    // CFNetwork with Darwin (automated apps/scrapers)
    if (preg_match('/CFNetwork\/\d+ Darwin/', $userAgent)) {
      return true;
    }

    // KHTML without Chrome/Safari (Konqueror derivatives)
    if (str_contains($userAgent, 'KHTML') &&
      !str_contains($userAgent, 'Chrome') &&
      !str_contains($userAgent, 'Safari')) {
      return true;
    }

    // Slack/chatlyio crawlers
    if (str_contains($userAgent, 'chatlyio') || str_contains($userAgent, 'Slackbot')) {
      return true;
    }

    // Old macOS versions (10.13 and below = 2017 and older)
    if (preg_match('/Mac OS X 10[._](\d+)/', $userAgent, $matches)) {
      if (intval($matches[1]) < 14) {
        return true;
      }
    }

    return false;
  }

  /**
   * Check for outdated browser versions (legacy method - now uses age scoring)
   * @deprecated Use getBrowserAgeScore() instead
   */
  protected function hasOutdatedBrowser($userAgent): bool
  {
    return $this->hasDeadBrowser($userAgent) || $this->getBrowserAgeScore($userAgent) >= 40;
  }

  /**
   * Check if URL sequence shows sequential pattern
   */
  protected function hasSequentialPattern($urlSequence)
  {
    $urls = explode(',', $urlSequence);
    if (count($urls) < 3) return false;

    // Extract page numbers from URLs
    $pageNumbers = [];
    foreach ($urls as $url) {
      if (preg_match('/(?:page=|\/page\/|&p=)(\d+)/', $url, $matches)) {
        $pageNumbers[] = intval($matches[1]);
      }
    }

    if (count($pageNumbers) < 3) return false;

    // Check if numbers are mostly sequential
    $sequential = 0;
    for ($i = 1; $i < count($pageNumbers); $i++) {
      $diff = $pageNumbers[$i] - $pageNumbers[$i - 1];
      if ($diff >= 0 && $diff <= 2) {
        $sequential++;
      }
    }

    return ($sequential / (count($pageNumbers) - 1)) > 0.7;
  }

  /**
   * Mark session and page views as bot
   */
  protected function markAsBot($sessionId, $reason = null)
  {
    if ($this->dryRun) {
      $this->stdout("Would mark session {$sessionId} as bot" . ($reason ? " (reason: {$reason})" : "") . "\n");
      return;
    }

    $db = Yii::$app->db;
    $transaction = $db->beginTransaction();

    try {
      // Update session
      $updateData = ['is_bot' => 1];
      if ($reason && $this->hasColumn('analytics_sessions', 'bot_reason')) {
        $updateData['bot_reason'] = $reason;
      }

      $db->createCommand()
        ->update('analytics_sessions', $updateData, ['session_id' => $sessionId])
        ->execute();

      // Update all page views
      $db->createCommand()
        ->update('analytics_page_views', ['is_bot' => 1], ['session_id' => $sessionId])
        ->execute();

      $transaction->commit();
    } catch (\Exception $e) {
      $transaction->rollBack();
      $this->stderr("Error marking session as bot: " . $e->getMessage() . "\n", Console::FG_RED);
    }
  }

  /**
   * Check if table has a column
   */
  protected function hasColumn($table, $column)
  {
    try {
      $schema = Yii::$app->db->getTableSchema($table);
      return $schema && isset($schema->columns[$column]);
    } catch (\Exception $e) {
      return false;
    }
  }

  /**
   * Update sessions based on page view bot status
   */
  protected function updateSessionsFromPageViews()
  {
    if ($this->dryRun) {
      $this->stdout("Skipping session updates in dry run mode\n");
      return;
    }

    $db = Yii::$app->db;
    $totalUpdated = 0;

    try {
      $updated = $db->createCommand("
                UPDATE analytics_sessions s
                INNER JOIN (
                    SELECT DISTINCT session_id
                    FROM analytics_page_views
                    WHERE is_bot = 1
                ) bot_pvs ON s.session_id = bot_pvs.session_id
                SET s.is_bot = 1
                WHERE s.is_bot = 0
            ")->execute();

      $totalUpdated = $updated;
    } catch (\Exception $e) {
      $this->stderr("Failed to update sessions: " . $e->getMessage() . "\n", Console::FG_RED);
    }

    $this->stdout(sprintf(
      "Updated %d sessions based on page view bot status\n",
      $totalUpdated
    ), Console::FG_YELLOW);
  }

  /**
   * Delete all bot traffic from database
   */
  protected function deleteBotTraffic()
  {
    if ($this->dryRun) {
      $this->stdout("Skipping bot deletion in dry run mode\n");
      return;
    }

    $db = Yii::$app->db;

    try {
      // Get counts before deletion for reporting
      $botPageViewsCount = $db->createCommand("
                SELECT COUNT(*) FROM analytics_page_views WHERE is_bot = 1
            ")->queryScalar();

      $botSessionsCount = $db->createCommand("
                SELECT COUNT(*) FROM analytics_sessions WHERE is_bot = 1
            ")->queryScalar();

      // Count element views from bot sessions
      $botElementViewsCount = $db->createCommand("
                SELECT COUNT(*)
                FROM analytics_element_views ev
                INNER JOIN analytics_sessions s ON ev.session_id = s.session_id
                WHERE s.is_bot = 1
            ")->queryScalar();

      // Delete element views from bot sessions FIRST (before deleting sessions)
      $deletedElementViews = $db->createCommand("
                DELETE ev FROM analytics_element_views ev
                INNER JOIN analytics_sessions s ON ev.session_id = s.session_id
                WHERE s.is_bot = 1
            ")->execute();

      $this->stdout(sprintf("Deleted %d bot element views\n", $deletedElementViews), Console::FG_YELLOW);

      // Delete bot page views
      $deletedPageViews = $db->createCommand("
                DELETE FROM analytics_page_views WHERE is_bot = 1
            ")->execute();

      $this->stdout(sprintf("Deleted %d bot page views\n", $deletedPageViews), Console::FG_YELLOW);

      // Delete bot sessions (do this LAST after element views are deleted)
      $deletedSessions = $db->createCommand("
                DELETE FROM analytics_sessions WHERE is_bot = 1
            ")->execute();

      $this->stdout(sprintf("Deleted %d bot sessions\n", $deletedSessions), Console::FG_YELLOW);

    } catch (\Exception $e) {
      $this->stderr("Error deleting bot traffic: " . $e->getMessage() . "\n", Console::FG_RED);
    }
  }

  /**
   * Show detection summary with confidence breakdown
   */
  protected function showDetectionSummary()
  {
    $db = Yii::$app->db;

    $this->stdout("\n" . str_repeat('=', 50) . "\n", Console::FG_GREEN);
    $this->stdout("Bot Detection Summary\n", Console::FG_GREEN);
    $this->stdout(str_repeat('=', 50) . "\n", Console::FG_GREEN);

    // Get stats with confidence breakdown
    $stats = $db->createCommand("
      SELECT
        (SELECT COUNT(*) FROM analytics_sessions) as total_sessions,
        (SELECT COUNT(*) FROM analytics_page_views) as total_page_views,
        (SELECT COUNT(*) FROM analytics_sessions WHERE bot_score >= :high) as high_confidence,
        (SELECT COUNT(*) FROM analytics_sessions WHERE bot_score >= :medium AND bot_score < :high2) as medium_confidence,
        (SELECT COUNT(*) FROM analytics_sessions WHERE bot_score > 0 AND bot_score < :medium2) as low_confidence,
        (SELECT COUNT(*) FROM analytics_sessions WHERE bot_score IS NULL OR bot_score = 0) as no_score
    ")
      ->bindValue(':high', self::SCORE_HIGH_CONFIDENCE)
      ->bindValue(':high2', self::SCORE_HIGH_CONFIDENCE)
      ->bindValue(':medium', self::SCORE_MEDIUM_CONFIDENCE)
      ->bindValue(':medium2', self::SCORE_MEDIUM_CONFIDENCE)
      ->queryOne();

    $this->stdout("\nRemaining traffic:\n");
    $this->stdout(sprintf("  Sessions:   %s\n", number_format($stats['total_sessions'])));
    $this->stdout(sprintf("  Page Views: %s\n", number_format($stats['total_page_views'])));

    $this->stdout("\nConfidence breakdown:\n");
    $this->stdout(sprintf("  HIGH (deleted):      %s\n", number_format($stats['high_confidence'])), Console::FG_RED);
    $this->stdout(sprintf("  MEDIUM (for review): %s\n", number_format($stats['medium_confidence'])), Console::FG_YELLOW);
    $this->stdout(sprintf("  LOW (kept):          %s\n", number_format($stats['low_confidence'])), Console::FG_GREEN);
    $this->stdout(sprintf("  No score:            %s\n", number_format($stats['no_score'])));

    if ($stats['medium_confidence'] > 0) {
      $this->stdout("\nTip: Use 'bot-detection/review' to review medium-confidence sessions\n", Console::FG_CYAN);
    }

    if ($this->dryRun) {
      $this->stdout("\nDRY RUN completed - no changes were made\n", Console::FG_YELLOW);
    } else {
      $this->stdout("\nConfidence-based bot detection completed!\n", Console::FG_GREEN);
    }
  }

  /**
   * Review medium-confidence sessions
   */
  public function actionReview()
  {
    $db = Yii::$app->db;

    $this->stdout("Medium-Confidence Sessions for Review\n", Console::FG_YELLOW);
    $this->stdout(str_repeat('=', 70) . "\n");

    $sessions = $db->createCommand("
      SELECT s.session_id, s.ip_address, s.user_agent, s.bot_score,
        s.created_at, s.total_pages,
        (SELECT COUNT(*) FROM analytics_page_views pv WHERE pv.session_id = s.session_id) as page_views
      FROM analytics_sessions s
      WHERE s.bot_score >= :medium AND s.bot_score < :high
      ORDER BY s.bot_score DESC
      LIMIT 50
    ")
      ->bindValue(':medium', self::SCORE_MEDIUM_CONFIDENCE)
      ->bindValue(':high', self::SCORE_HIGH_CONFIDENCE)
      ->queryAll();

    if (empty($sessions)) {
      $this->stdout("\nNo medium-confidence sessions to review.\n", Console::FG_GREEN);
      return ExitCode::OK;
    }

    $this->stdout(sprintf("\nFound %d medium-confidence sessions (showing top 50):\n\n", count($sessions)));

    foreach ($sessions as $session) {
      // Get bot_reason if available
      $reason = '';
      if ($this->hasColumn('analytics_sessions', 'bot_reason')) {
        $reason = $db->createCommand("SELECT bot_reason FROM analytics_sessions WHERE session_id = :id")
          ->bindValue(':id', $session['session_id'])
          ->queryScalar();
      }

      $this->stdout(sprintf(
        "Session: %s\n",
        substr($session['session_id'], 0, 16)
      ), Console::FG_CYAN);
      $this->stdout(sprintf("  Score: %d | IP: %s | Pages: %d\n",
        $session['bot_score'],
        $session['ip_address'] ?? 'N/A',
        $session['page_views']
      ));
      $this->stdout(sprintf("  UA: %s\n", substr($session['user_agent'] ?? 'N/A', 0, 80)));
      if ($reason) {
        $this->stdout(sprintf("  Reasons: %s\n", $reason), Console::FG_YELLOW);
      }
      $this->stdout("\n");
    }

    $this->stdout("Actions available:\n", Console::FG_CYAN);
    $this->stdout("  bot-detection/promote <session_id>  - Mark as HIGH confidence (delete)\n");
    $this->stdout("  bot-detection/demote <session_id>   - Mark as legitimate (clear score)\n");

    return ExitCode::OK;
  }

  /**
   * Promote a session to high confidence (will be deleted)
   */
  public function actionPromote($sessionId)
  {
    $db = Yii::$app->db;

    $session = $db->createCommand("SELECT * FROM analytics_sessions WHERE session_id LIKE :id")
      ->bindValue(':id', $sessionId . '%')
      ->queryOne();

    if (!$session) {
      $this->stderr("Session not found: {$sessionId}\n", Console::FG_RED);
      return ExitCode::DATAERR;
    }

    $db->createCommand()
      ->update('analytics_sessions', [
        'is_bot' => 1,
        'bot_score' => self::SCORE_HIGH_CONFIDENCE,
      ], ['session_id' => $session['session_id']])
      ->execute();

    $db->createCommand()
      ->update('analytics_page_views', ['is_bot' => 1], ['session_id' => $session['session_id']])
      ->execute();

    $this->stdout("Session {$session['session_id']} promoted to HIGH confidence.\n", Console::FG_GREEN);
    $this->stdout("Run 'bot-detection' to delete it.\n");

    return ExitCode::OK;
  }

  /**
   * Demote a session to legitimate (clear bot score)
   */
  public function actionDemote($sessionId)
  {
    $db = Yii::$app->db;

    $session = $db->createCommand("SELECT * FROM analytics_sessions WHERE session_id LIKE :id")
      ->bindValue(':id', $sessionId . '%')
      ->queryOne();

    if (!$session) {
      $this->stderr("Session not found: {$sessionId}\n", Console::FG_RED);
      return ExitCode::DATAERR;
    }

    $db->createCommand()
      ->update('analytics_sessions', [
        'is_bot' => 0,
        'bot_score' => 0,
      ], ['session_id' => $session['session_id']])
      ->execute();

    $this->stdout("Session {$session['session_id']} marked as legitimate.\n", Console::FG_GREEN);

    return ExitCode::OK;
  }

  /**
   * Show statistics about bot traffic with confidence breakdown
   */
  public function actionStats()
  {
    $this->stdout("Bot Traffic Statistics\n", Console::FG_GREEN);
    $this->stdout(str_repeat('=', 50) . "\n");

    $db = Yii::$app->db;

    // Page views stats
    $this->stdout("\nPage Views:\n", Console::FG_CYAN);
    $pvStats = $db->createCommand("
      SELECT
        COUNT(*) as total_records,
        SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_records,
        SUM(CASE WHEN is_bot = 0 THEN 1 ELSE 0 END) as human_records
      FROM analytics_page_views
    ")->queryOne();

    $this->stdout(sprintf(
      "  Total: %s | Bots: %s (%.2f%%) | Humans: %s (%.2f%%)\n",
      number_format($pvStats['total_records']),
      number_format($pvStats['bot_records']),
      $pvStats['total_records'] > 0 ? ($pvStats['bot_records'] / $pvStats['total_records'] * 100) : 0,
      number_format($pvStats['human_records']),
      $pvStats['total_records'] > 0 ? ($pvStats['human_records'] / $pvStats['total_records'] * 100) : 0
    ));

    // Sessions stats
    $this->stdout("\nSessions:\n", Console::FG_CYAN);
    $sessionStats = $db->createCommand("
      SELECT
        COUNT(*) as total_records,
        SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_records,
        SUM(CASE WHEN is_bot = 0 THEN 1 ELSE 0 END) as human_records
      FROM analytics_sessions
    ")->queryOne();

    $this->stdout(sprintf(
      "  Total: %s | Bots: %s (%.2f%%) | Humans: %s (%.2f%%)\n",
      number_format($sessionStats['total_records']),
      number_format($sessionStats['bot_records']),
      $sessionStats['total_records'] > 0 ? ($sessionStats['bot_records'] / $sessionStats['total_records'] * 100) : 0,
      number_format($sessionStats['human_records']),
      $sessionStats['total_records'] > 0 ? ($sessionStats['human_records'] / $sessionStats['total_records'] * 100) : 0
    ));

    // Confidence level breakdown
    $this->stdout("\nConfidence Level Breakdown:\n", Console::FG_YELLOW);
    $confidenceStats = $db->createCommand("
      SELECT
        SUM(CASE WHEN bot_score >= :high THEN 1 ELSE 0 END) as high_confidence,
        SUM(CASE WHEN bot_score >= :medium AND bot_score < :high2 THEN 1 ELSE 0 END) as medium_confidence,
        SUM(CASE WHEN bot_score > 0 AND bot_score < :medium2 THEN 1 ELSE 0 END) as low_confidence,
        SUM(CASE WHEN bot_score IS NULL OR bot_score = 0 THEN 1 ELSE 0 END) as no_score
      FROM analytics_sessions
    ")
      ->bindValue(':high', self::SCORE_HIGH_CONFIDENCE)
      ->bindValue(':high2', self::SCORE_HIGH_CONFIDENCE)
      ->bindValue(':medium', self::SCORE_MEDIUM_CONFIDENCE)
      ->bindValue(':medium2', self::SCORE_MEDIUM_CONFIDENCE)
      ->queryOne();

    $this->stdout(sprintf("  HIGH (>=%d, delete):  %s\n",
      self::SCORE_HIGH_CONFIDENCE,
      number_format($confidenceStats['high_confidence'] ?? 0)
    ), Console::FG_RED);
    $this->stdout(sprintf("  MEDIUM (%d-%d, review): %s\n",
      self::SCORE_MEDIUM_CONFIDENCE,
      self::SCORE_HIGH_CONFIDENCE - 1,
      number_format($confidenceStats['medium_confidence'] ?? 0)
    ), Console::FG_YELLOW);
    $this->stdout(sprintf("  LOW (1-%d, keep):       %s\n",
      self::SCORE_MEDIUM_CONFIDENCE - 1,
      number_format($confidenceStats['low_confidence'] ?? 0)
    ), Console::FG_GREEN);
    $this->stdout(sprintf("  No score:              %s\n",
      number_format($confidenceStats['no_score'] ?? 0)
    ));

    // Score distribution
    $this->stdout("\nScore Distribution:\n", Console::FG_CYAN);
    $distribution = $db->createCommand("
      SELECT
        CASE
          WHEN bot_score IS NULL OR bot_score = 0 THEN '0 (clean)'
          WHEN bot_score BETWEEN 1 AND 19 THEN '1-19'
          WHEN bot_score BETWEEN 20 AND 29 THEN '20-29'
          WHEN bot_score BETWEEN 30 AND 49 THEN '30-49'
          WHEN bot_score BETWEEN 50 AND 69 THEN '50-69'
          WHEN bot_score BETWEEN 70 AND 89 THEN '70-89'
          WHEN bot_score >= 90 THEN '90-100'
        END as score_range,
        COUNT(*) as count
      FROM analytics_sessions
      GROUP BY score_range
      ORDER BY
        CASE score_range
          WHEN '0 (clean)' THEN 0
          WHEN '1-19' THEN 1
          WHEN '20-29' THEN 2
          WHEN '30-49' THEN 3
          WHEN '50-69' THEN 4
          WHEN '70-89' THEN 5
          WHEN '90-100' THEN 6
        END
    ")->queryAll();

    foreach ($distribution as $row) {
      $this->stdout(sprintf("  %s: %s\n",
        str_pad($row['score_range'], 10),
        number_format($row['count'])
      ));
    }

    // Bot reasons breakdown (if column exists)
    if ($this->hasColumn('analytics_sessions', 'bot_reason')) {
      $this->stdout("\nTop Detection Reasons:\n", Console::FG_YELLOW);
      $reasons = $db->createCommand("
        SELECT bot_reason, COUNT(*) as count
        FROM analytics_sessions
        WHERE bot_score > 0 AND bot_reason IS NOT NULL
        GROUP BY bot_reason
        ORDER BY count DESC
        LIMIT 15
      ")->queryAll();

      foreach ($reasons as $reason) {
        $this->stdout(sprintf("  %s: %s\n",
          str_pad(substr($reason['bot_reason'], 0, 40), 40),
          number_format($reason['count'])
        ));
      }
    }

    return ExitCode::OK;
  }
}