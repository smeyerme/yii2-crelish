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
    $this->stdout("Starting enhanced bot detection process...\n", Console::FG_GREEN);

    if ($this->dryRun) {
      $this->stdout("Running in DRY RUN mode - no changes will be made\n", Console::FG_YELLOW);
    }

    // Load custom thresholds if available
    $this->loadCustomThresholds();

    // 1. User agent based detection
    $this->stdout("\n[1/5] Processing user agent-based detection...\n", Console::FG_CYAN);
    $this->processPageViews();
    $this->processSessions();

    // 2. Volume-based anomaly detection
    $this->stdout("\n[2/5] Detecting high-volume anomalies...\n", Console::FG_CYAN);
    $this->detectVolumeAnomalies();

    // 3. Timing pattern detection
    $this->stdout("\n[3/5] Analyzing timing patterns...\n", Console::FG_CYAN);
    $this->detectTimingPatterns();

    // 4. Crawling pattern detection
    $this->stdout("\n[4/5] Detecting crawling patterns...\n", Console::FG_CYAN);
    $this->detectCrawlingPatterns();

    // 5. Sync bot status between tables
    $this->stdout("\n[5/5] Syncing bot status...\n", Console::FG_CYAN);
    $this->updateSessionsFromPageViews();

    // 6. Delete all bot traffic from database
    $this->stdout("\n[6/6] Deleting bot traffic from database...\n", Console::FG_CYAN);
    $this->deleteBotTraffic();

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
   * Show detection summary
   */
  protected function showDetectionSummary()
  {
    $db = Yii::$app->db;

    $this->stdout("\n" . str_repeat('=', 50) . "\n", Console::FG_GREEN);
    $this->stdout("Bot Detection Summary\n", Console::FG_GREEN);
    $this->stdout(str_repeat('=', 50) . "\n", Console::FG_GREEN);

    // Get stats - only human traffic remains after deletion
    $stats = $db->createCommand("
            SELECT
                (SELECT COUNT(*) FROM analytics_sessions) as total_sessions,
                (SELECT COUNT(*) FROM analytics_page_views) as total_page_views
        ")->queryOne();

    $this->stdout(sprintf(
      "\nRemaining (human traffic only):\n"
    ));

    $this->stdout(sprintf(
      "Sessions:   %s\n",
      number_format($stats['total_sessions'])
    ));

    $this->stdout(sprintf(
      "Page Views: %s\n",
      number_format($stats['total_page_views'])
    ));

    if ($this->dryRun) {
      $this->stdout("\nDRY RUN completed - no changes were made\n", Console::FG_YELLOW);
    } else {
      $this->stdout("\nBot detection and cleanup completed successfully!\n", Console::FG_GREEN);
    }
  }

  /**
   * Show statistics about bot traffic
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

    // Bot reasons breakdown (if column exists)
    if ($this->hasColumn('analytics_sessions', 'bot_reason')) {
      $this->stdout("\nBot Detection Reasons:\n", Console::FG_YELLOW);
      $reasons = $db->createCommand("
                SELECT bot_reason, COUNT(*) as count
                FROM analytics_sessions
                WHERE is_bot = 1 AND bot_reason IS NOT NULL
                GROUP BY bot_reason
                ORDER BY count DESC
            ")->queryAll();

      foreach ($reasons as $reason) {
        $this->stdout(sprintf(
          "  %s: %s sessions\n",
          str_pad($reason['bot_reason'], 20),
          number_format($reason['count'])
        ));
      }
    }

    return ExitCode::OK;
  }
}