<?php

namespace giantbits\crelish\commands;

use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

class BotDetectionController extends Controller
{
  /**
   * @var int Number of records to process in each batch
   */
  public $batchSize = 1000;

  /**
   * @var bool Whether to run in dry-run mode (no updates)
   */
  public $dryRun = false;

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
   * Main entry point - runs all detection methods
   */
  public function actionIndex()
  {
    $this->stdout("Starting enhanced bot detection and cleanup process...\n", Console::FG_GREEN);

    if ($this->dryRun) {
      $this->stdout("Running in DRY RUN mode - no changes will be made\n", Console::FG_YELLOW);
    }

    // Load custom thresholds if available
    $this->loadCustomThresholds();

    // Track deletion stats
    $totalDeleted = [
      'page_views' => 0,
      'sessions' => 0,
    ];

    // 1. User agent based detection
    $this->stdout("\n[1/5] Processing user agent-based detection...\n", Console::FG_CYAN);
    $result = $this->processPageViews();
    $totalDeleted['page_views'] += $result['deleted'];

    $result = $this->processSessions();
    $totalDeleted['sessions'] += $result['deleted'];

    // 2. Volume-based anomaly detection
    $this->stdout("\n[2/5] Detecting high-volume anomalies...\n", Console::FG_CYAN);
    $deleted = $this->detectVolumeAnomalies();
    $totalDeleted['page_views'] += $deleted['page_views'];
    $totalDeleted['sessions'] += $deleted['sessions'];

    // 3. Timing pattern detection
    $this->stdout("\n[3/5] Analyzing timing patterns...\n", Console::FG_CYAN);
    $deleted = $this->detectTimingPatterns();
    $totalDeleted['page_views'] += $deleted['page_views'];
    $totalDeleted['sessions'] += $deleted['sessions'];

    // 4. Crawling pattern detection
    $this->stdout("\n[4/5] Detecting crawling patterns...\n", Console::FG_CYAN);
    $deleted = $this->detectCrawlingPatterns();
    $totalDeleted['page_views'] += $deleted['page_views'];
    $totalDeleted['sessions'] += $deleted['sessions'];

    // 5. Clean up orphaned records
    $this->stdout("\n[5/5] Cleaning up orphaned records...\n", Console::FG_CYAN);
    $deleted = $this->cleanupOrphanedRecords();
    $totalDeleted['page_views'] += $deleted['page_views'];
    $totalDeleted['sessions'] += $deleted['sessions'];

    // Show summary
    $this->showDetectionSummary($totalDeleted);

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
   * Process page views table - delete bot traffic
   */
  protected function processPageViews()
  {
    $detector = new CrawlerDetect;
    $db = Yii::$app->db;

    $totalProcessed = 0;
    $totalDeleted = 0;
    $offset = 0;

    do {
      $records = $db->createCommand("
                SELECT id, user_agent
                FROM analytics_page_views
                LIMIT :limit OFFSET :offset
            ")
        ->bindValue(':limit', $this->batchSize)
        ->bindValue(':offset', $offset)
        ->queryAll();

      if (empty($records)) {
        break;
      }

      $deletedInBatch = 0;
      $transaction = $db->beginTransaction();

      try {
        $idsToDelete = [];
        foreach ($records as $record) {
          if ($this->isBot($record['user_agent'], $detector)) {
            $idsToDelete[] = $record['id'];
            $deletedInBatch++;
            $totalDeleted++;
          }
        }

        if (!empty($idsToDelete) && !$this->dryRun) {
          $db->createCommand()
            ->delete('analytics_page_views', ['in', 'id', $idsToDelete])
            ->execute();
        }

        $transaction->commit();
      } catch (\Exception $e) {
        $transaction->rollBack();
        $this->stderr("Error processing page views batch: " . $e->getMessage() . "\n", Console::FG_RED);
        return ['processed' => 0, 'deleted' => 0];
      }

      $totalProcessed += count($records);
      $offset += $this->batchSize;

      $this->stdout(sprintf(
        "Processed %d page view records (deleted %d bots)\n",
        $totalProcessed,
        $deletedInBatch
      ));

    } while (count($records) == $this->batchSize);

    $this->stdout(sprintf(
      "Page views: %d records processed, %d bots deleted (%.2f%%)\n",
      $totalProcessed,
      $totalDeleted,
      $totalProcessed > 0 ? ($totalDeleted / $totalProcessed * 100) : 0
    ), Console::FG_YELLOW);

    return ['processed' => $totalProcessed, 'deleted' => $totalDeleted];
  }

  /**
   * Process sessions table - delete bot traffic
   */
  protected function processSessions()
  {
    $detector = new CrawlerDetect;
    $db = Yii::$app->db;

    $totalProcessed = 0;
    $totalDeleted = 0;
    $offset = 0;

    do {
      $records = $db->createCommand("
                SELECT session_id, user_agent
                FROM analytics_sessions
                LIMIT :limit OFFSET :offset
            ")
        ->bindValue(':limit', $this->batchSize)
        ->bindValue(':offset', $offset)
        ->queryAll();

      if (empty($records)) {
        break;
      }

      $deletedInBatch = 0;
      $transaction = $db->beginTransaction();

      try {
        $sessionIdsToDelete = [];
        foreach ($records as $record) {
          if ($this->isBot($record['user_agent'], $detector)) {
            $sessionIdsToDelete[] = $record['session_id'];
            $deletedInBatch++;
            $totalDeleted++;
          }
        }

        if (!empty($sessionIdsToDelete) && !$this->dryRun) {
          // Delete page views first (foreign key constraint)
          $db->createCommand()
            ->delete('analytics_page_views', ['in', 'session_id', $sessionIdsToDelete])
            ->execute();

          // Then delete sessions
          $db->createCommand()
            ->delete('analytics_sessions', ['in', 'session_id', $sessionIdsToDelete])
            ->execute();
        }

        $transaction->commit();
      } catch (\Exception $e) {
        $transaction->rollBack();
        $this->stderr("Error processing sessions batch: " . $e->getMessage() . "\n", Console::FG_RED);
        return ['processed' => 0, 'deleted' => 0];
      }

      $totalProcessed += count($records);
      $offset += $this->batchSize;

      $this->stdout(sprintf(
        "Processed %d session records (deleted %d bots)\n",
        $totalProcessed,
        $deletedInBatch
      ));

    } while (count($records) == $this->batchSize);

    $this->stdout(sprintf(
      "Sessions: %d records processed, %d bots deleted (%.2f%%)\n",
      $totalProcessed,
      $totalDeleted,
      $totalProcessed > 0 ? ($totalDeleted / $totalProcessed * 100) : 0
    ), Console::FG_YELLOW);

    return ['processed' => $totalProcessed, 'deleted' => $totalDeleted];
  }

  /**
   * Detect volume-based anomalies and delete
   */
  protected function detectVolumeAnomalies()
  {
    $db = Yii::$app->db;
    $sessionIdsToDelete = [];

    // Check hourly volumes
    $this->stdout("Checking hourly request volumes...\n");
    $hourlyAnomalies = $db->createCommand("
            SELECT
                s.session_id,
                COUNT(*) as request_count
            FROM analytics_sessions s
            JOIN analytics_page_views pv ON s.session_id = pv.session_id
            WHERE pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY s.session_id
            HAVING request_count > :threshold
        ")
      ->bindValue(':threshold', $this->thresholds['session_requests_per_hour'])
      ->queryAll();

    foreach ($hourlyAnomalies as $anomaly) {
      $sessionIdsToDelete[$anomaly['session_id']] = true;
    }

    // Check daily volumes
    $this->stdout("Checking daily request volumes...\n");
    $dailyAnomalies = $db->createCommand("
            SELECT
                s.session_id,
                COUNT(*) as request_count
            FROM analytics_sessions s
            JOIN analytics_page_views pv ON s.session_id = pv.session_id
            WHERE pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            GROUP BY s.session_id
            HAVING request_count > :threshold
        ")
      ->bindValue(':threshold', $this->thresholds['session_requests_per_day'])
      ->queryAll();

    foreach ($dailyAnomalies as $anomaly) {
      $sessionIdsToDelete[$anomaly['session_id']] = true;
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
            WHERE pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            GROUP BY s.session_id
            HAVING total_requests > :min_requests
                AND url_diversity > :diversity_threshold
        ")
      ->bindValue(':min_requests', $this->thresholds['min_requests_for_pattern_detection'])
      ->bindValue(':diversity_threshold', $this->thresholds['url_diversity_threshold'])
      ->queryAll();

    foreach ($crawlers as $crawler) {
      $sessionIdsToDelete[$crawler['session_id']] = true;
    }

    $deletedCount = $this->deleteBotSessions(array_keys($sessionIdsToDelete));

    $this->stdout(sprintf(
      "Volume anomaly detection: deleted %d bot sessions\n",
      $deletedCount
    ), Console::FG_YELLOW);

    return $deletedCount;
  }

  /**
   * Detect timing-based patterns and delete
   */
  protected function detectTimingPatterns()
  {
    $db = Yii::$app->db;
    $sessionIdsToDelete = [];

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
                    session_id,
                    TIMESTAMPDIFF(SECOND,
                        LAG(created_at) OVER (PARTITION BY session_id ORDER BY created_at),
                        created_at
                    ) as time_diff
                FROM analytics_page_views
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
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
      $sessionIdsToDelete[] = $anomaly['session_id'];
    }

    $deletedCount = $this->deleteBotSessions($sessionIdsToDelete);

    $this->stdout(sprintf(
      "Timing pattern detection: deleted %d bot sessions\n",
      $deletedCount
    ), Console::FG_YELLOW);

    return $deletedCount;
  }

  /**
   * Detect crawling patterns and delete
   */
  protected function detectCrawlingPatterns()
  {
    $db = Yii::$app->db;
    $sessionIdsToDelete = [];

    // Detect sequential pagination crawling
    $this->stdout("Checking for sequential pagination patterns...\n");

    $paginationCrawlers = $db->createCommand("
            SELECT
                session_id,
                GROUP_CONCAT(DISTINCT url ORDER BY created_at) as url_sequence
            FROM analytics_page_views
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                AND (url LIKE '%page=%' OR url LIKE '%/page/%' OR url LIKE '%&p=%')
            GROUP BY session_id
            HAVING COUNT(*) > :threshold
        ")
      ->bindValue(':threshold', $this->thresholds['sequential_page_threshold'])
      ->queryAll();

    foreach ($paginationCrawlers as $crawler) {
      if ($this->hasSequentialPattern($crawler['url_sequence'])) {
        $sessionIdsToDelete[] = $crawler['session_id'];
      }
    }

    $deletedCount = $this->deleteBotSessions($sessionIdsToDelete);

    $this->stdout(sprintf(
      "Crawling pattern detection: deleted %d bot sessions\n",
      $deletedCount
    ), Console::FG_YELLOW);

    return $deletedCount;
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

    // Check for outdated browser versions
    if ($this->hasOutdatedBrowser($userAgent)) {
      return true;
    }

    // Custom rules for edge cases
    // Email in user agent
    if (preg_match('/@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $userAgent)) {
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

    // Any old IE
    if (preg_match('/MSIE (?:[67]\.|8\.0|9\.0)/', $userAgent)) {
      return true;
    }

    return false;
  }

  /**
   * Check for outdated browser versions
   */
  protected function hasOutdatedBrowser($userAgent)
  {
    // Chrome version check
    if (preg_match('/Chrome\/(\d+)\./', $userAgent, $matches)) {
      if (intval($matches[1]) < $this->thresholds['min_chrome_version']) {
        return true;
      }
    }

    // Firefox version check
    if (preg_match('/Firefox\/(\d+)\./', $userAgent, $matches)) {
      if (intval($matches[1]) < $this->thresholds['min_firefox_version']) {
        return true;
      }
    }

    // Safari version check
    if (preg_match('/Safari\/(\d+)\./', $userAgent, $matches)) {
      if (intval($matches[1]) < $this->thresholds['min_safari_version']) {
        return true;
      }
    }

    return false;
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
   * Delete bot sessions and their associated page views
   */
  protected function deleteBotSessions($sessionIds)
  {
    if (empty($sessionIds)) {
      return ['page_views' => 0, 'sessions' => 0];
    }

    if ($this->dryRun) {
      $this->stdout(sprintf("Would delete %d bot sessions\n", count($sessionIds)));
      return ['page_views' => 0, 'sessions' => 0];
    }

    $db = Yii::$app->db;
    $transaction = $db->beginTransaction();

    try {
      // Count page views before deletion for reporting
      $pageViewCount = $db->createCommand("
                SELECT COUNT(*) FROM analytics_page_views
                WHERE session_id IN (" . implode(',', array_fill(0, count($sessionIds), '?')) . ")
            ")
        ->bindValues($sessionIds)
        ->queryScalar();

      // Delete page views first (foreign key constraint)
      $db->createCommand()
        ->delete('analytics_page_views', ['in', 'session_id', $sessionIds])
        ->execute();

      // Then delete sessions
      $db->createCommand()
        ->delete('analytics_sessions', ['in', 'session_id', $sessionIds])
        ->execute();

      $transaction->commit();

      return [
        'page_views' => $pageViewCount,
        'sessions' => count($sessionIds)
      ];
    } catch (\Exception $e) {
      $transaction->rollBack();
      $this->stderr("Error deleting bot sessions: " . $e->getMessage() . "\n", Console::FG_RED);
      return ['page_views' => 0, 'sessions' => 0];
    }
  }

  /**
   * Clean up orphaned records
   */
  protected function cleanupOrphanedRecords()
  {
    if ($this->dryRun) {
      $this->stdout("Skipping orphaned record cleanup in dry run mode\n");
      return ['page_views' => 0, 'sessions' => 0];
    }

    $db = Yii::$app->db;
    $deletedPageViews = 0;
    $deletedSessions = 0;

    try {
      // Delete page views with no matching session
      $deletedPageViews = $db->createCommand("
                DELETE pv FROM analytics_page_views pv
                LEFT JOIN analytics_sessions s ON pv.session_id = s.session_id
                WHERE s.session_id IS NULL
            ")->execute();

      $this->stdout(sprintf("Deleted %d orphaned page views\n", $deletedPageViews));
    } catch (\Exception $e) {
      $this->stderr("Error cleaning up orphaned page views: " . $e->getMessage() . "\n", Console::FG_RED);
    }

    return ['page_views' => $deletedPageViews, 'sessions' => $deletedSessions];
  }

  /**
   * Show detection summary
   */
  protected function showDetectionSummary($totalDeleted)
  {
    $db = Yii::$app->db;

    $this->stdout("\n" . str_repeat('=', 50) . "\n", Console::FG_GREEN);
    $this->stdout("Bot Detection and Cleanup Summary\n", Console::FG_GREEN);
    $this->stdout(str_repeat('=', 50) . "\n", Console::FG_GREEN);

    // Show deletion stats
    $this->stdout(sprintf(
      "\nDeleted: %s sessions, %s page views\n",
      number_format($totalDeleted['sessions']),
      number_format($totalDeleted['page_views'])
    ), Console::FG_YELLOW);

    // Get current stats
    try {
      $stats = $db->createCommand("
                SELECT
                    (SELECT COUNT(*) FROM analytics_sessions) as total_sessions,
                    (SELECT COUNT(*) FROM analytics_page_views) as total_page_views
            ")->queryOne();

      $this->stdout(sprintf(
        "\nRemaining: %s sessions, %s page views\n",
        number_format($stats['total_sessions']),
        number_format($stats['total_page_views'])
      ));
    } catch (\Exception $e) {
      $this->stderr("Error retrieving final stats: " . $e->getMessage() . "\n", Console::FG_RED);
    }

    if ($this->dryRun) {
      $this->stdout("\nDRY RUN completed - no changes were made\n", Console::FG_YELLOW);
    } else {
      $this->stdout("\nBot detection and cleanup completed successfully!\n", Console::FG_GREEN);
    }
  }

  /**
   * Show statistics about current data
   */
  public function actionStats()
  {
    $this->stdout("Analytics Statistics\n", Console::FG_GREEN);
    $this->stdout(str_repeat('=', 50) . "\n");

    $db = Yii::$app->db;

    // Page views stats
    $this->stdout("\nPage Views:\n", Console::FG_CYAN);
    $pvStats = $db->createCommand("
            SELECT COUNT(*) as total_records
            FROM analytics_page_views
        ")->queryOne();

    $this->stdout(sprintf(
      "  Total: %s page views\n",
      number_format($pvStats['total_records'])
    ));

    // Sessions stats
    $this->stdout("\nSessions:\n", Console::FG_CYAN);
    $sessionStats = $db->createCommand("
            SELECT COUNT(*) as total_records
            FROM analytics_sessions
        ")->queryOne();

    $this->stdout(sprintf(
      "  Total: %s sessions\n",
      number_format($sessionStats['total_records'])
    ));

    // Recent activity
    $this->stdout("\nRecent Activity:\n", Console::FG_CYAN);
    try {
      $recentStats = $db->createCommand("
                SELECT
                    (SELECT COUNT(*) FROM analytics_page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as last_24h_views,
                    (SELECT COUNT(*) FROM analytics_sessions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as last_24h_sessions,
                    (SELECT COUNT(*) FROM analytics_page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as last_7d_views,
                    (SELECT COUNT(*) FROM analytics_sessions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as last_7d_sessions
            ")->queryOne();

      $this->stdout(sprintf(
        "  Last 24 hours: %s page views, %s sessions\n",
        number_format($recentStats['last_24h_views']),
        number_format($recentStats['last_24h_sessions'])
      ));

      $this->stdout(sprintf(
        "  Last 7 days: %s page views, %s sessions\n",
        number_format($recentStats['last_7d_views']),
        number_format($recentStats['last_7d_sessions'])
      ));
    } catch (\Exception $e) {
      $this->stderr("Error retrieving recent stats: " . $e->getMessage() . "\n", Console::FG_RED);
    }

    $this->stdout("\nNote: All bot traffic has been removed from the database.\n", Console::FG_YELLOW);

    return ExitCode::OK;
  }
}