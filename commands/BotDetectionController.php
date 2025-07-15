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
   * Detect and flag bot traffic in both analytics tables
   *
   * @return int Exit code
   */
  public function actionIndex()
  {
    $this->stdout("Starting bot detection process...\n", Console::FG_GREEN);

    if ($this->dryRun) {
      $this->stdout("Running in DRY RUN mode - no changes will be made\n", Console::FG_YELLOW);
    }

    // Process page views first
    $this->stdout("\n[1/2] Processing analytics_page_views...\n", Console::FG_CYAN);
    $pageViewsResult = $this->processPageViews();

    // Then process sessions
    $this->stdout("\n[2/2] Processing analytics_sessions...\n", Console::FG_CYAN);
    $sessionsResult = $this->processSessions();

    // Also update sessions based on their page views
    $this->stdout("\nUpdating sessions based on page view bot status...\n", Console::FG_CYAN);
    $this->updateSessionsFromPageViews();

    // Summary
    $this->stdout("\n" . str_repeat('=', 50) . "\n", Console::FG_GREEN);
    $this->stdout("Bot detection completed!\n", Console::FG_GREEN);

    if ($this->dryRun) {
      $this->stdout("\nDRY RUN - No changes were made to the database\n", Console::FG_YELLOW);
    }

    return ExitCode::OK;
  }

  /**
   * Process page views table
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
   * Process sessions table
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
   * Update sessions based on their page views
   * If a session has bot page views, mark the session as bot
   */
  protected function updateSessionsFromPageViews()
  {
    if ($this->dryRun) {
      $this->stdout("Skipping session updates in dry run mode\n");
      return;
    }

    $db = Yii::$app->db;

    // Find sessions that have bot page views but aren't marked as bots
    $updated = $db->createCommand("
            UPDATE analytics_sessions s
            SET s.is_bot = 1
            WHERE s.is_bot = 0
            AND EXISTS (
                SELECT 1 
                FROM analytics_page_views pv 
                WHERE pv.session_id = s.session_id 
                AND pv.is_bot = 1
                LIMIT 1
            )
        ")->execute();

    $this->stdout(sprintf(
      "Updated %d sessions based on their page view bot status\n",
      $updated
    ), Console::FG_YELLOW);
  }

  /**
   * Check if user agent is a bot
   */
  protected function isBot($userAgent, CrawlerDetect $detector)
  {
    // First check with the library
    if ($detector->isCrawler($userAgent)) {
      return true;
    }

    // Custom rules for edge cases
    // Email in user agent
    if (preg_match('/@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $userAgent)) {
      return true;
    }

    // Very old Chrome/Firefox versions (< 100)
    if (preg_match('/Chrome\/[0-9]{1,2}\./', $userAgent) ||
      preg_match('/Firefox\/[0-9]{1,2}\./', $userAgent)) {
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

    return false;
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

    // Top pages hit by bots
    $this->stdout("\nTop 10 Pages Hit by Bots:\n", Console::FG_YELLOW);
    $this->stdout(str_repeat('-', 50) . "\n");

    $topPages = $db->createCommand("
            SELECT 
                url,
                COUNT(*) as bot_hits,
                COUNT(DISTINCT session_id) as bot_sessions
            FROM analytics_page_views
            WHERE is_bot = 1
            GROUP BY url
            ORDER BY bot_hits DESC
            LIMIT 10
        ")->queryAll();

    foreach ($topPages as $i => $page) {
      $this->stdout(sprintf(
        "%2d. %s\n    Hits: %s | Sessions: %s\n",
        $i + 1,
        substr($page['url'], 0, 70) . (strlen($page['url']) > 70 ? '...' : ''),
        number_format($page['bot_hits']),
        number_format($page['bot_sessions'])
      ));
    }

    return ExitCode::OK;
  }

  /**
   * Sync bot status between tables (useful for maintenance)
   */
  public function actionSync()
  {
    $this->stdout("Syncing bot status between tables...\n", Console::FG_GREEN);

    if ($this->dryRun) {
      $this->stdout("Running in DRY RUN mode\n", Console::FG_YELLOW);
    }

    $db = Yii::$app->db;

    if (!$this->dryRun) {
      // Update page views based on session bot status
      $updated1 = $db->createCommand("
                UPDATE analytics_page_views pv
                INNER JOIN analytics_sessions s ON pv.session_id = s.session_id
                SET pv.is_bot = 1
                WHERE s.is_bot = 1 AND pv.is_bot = 0
            ")->execute();

      $this->stdout(sprintf(
        "Updated %d page views based on session bot status\n",
        $updated1
      ));

      // Update sessions based on page view bot status
      $updated2 = $db->createCommand("
                UPDATE analytics_sessions s
                SET s.is_bot = 1
                WHERE s.is_bot = 0
                AND EXISTS (
                    SELECT 1 
                    FROM analytics_page_views pv 
                    WHERE pv.session_id = s.session_id 
                    AND pv.is_bot = 1
                    LIMIT 1
                )
            ")->execute();

      $this->stdout(sprintf(
        "Updated %d sessions based on page view bot status\n",
        $updated2
      ));
    }

    return ExitCode::OK;
  }
}