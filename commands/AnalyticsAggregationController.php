<?php

namespace giantbits\crelish\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Analytics Aggregation Controller
 *
 * Aggregates analytics data to reduce storage requirements while maintaining
 * granular element-level statistics for partners.
 *
 * Usage:
 *   yii crelish/analytics-aggregation/daily              # Aggregate yesterday's data
 *   yii crelish/analytics-aggregation/monthly            # Aggregate last month
 *   yii crelish/analytics-aggregation/partner-stats      # Build partner stats cache
 *   yii crelish/analytics-aggregation/cleanup            # Delete old raw data
 *   yii crelish/analytics-aggregation/backfill 30        # Backfill last 30 days
 */
class AnalyticsAggregationController extends Controller
{
    /**
     * @var bool Dry run mode - show what would be done without making changes
     */
    public $dryRun = false;

    /**
     * @var int Days to keep raw data (default 30)
     */
    public $retentionDays = 30;

    /**
     * @var bool Verbose output
     */
    public $verbose = false;

    /**
     * Define command options
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'dryRun',
            'retentionDays',
            'verbose',
        ]);
    }

    /**
     * Define option aliases
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'd' => 'dryRun',
            'r' => 'retentionDays',
            'v' => 'verbose',
        ]);
    }

    /**
     * Aggregate yesterday's data (run daily via cron)
     *
     * @param string|null $date Optional date in Y-m-d format (default: yesterday)
     * @return int
     */
    public function actionDaily($date = null)
    {
        $targetDate = $date ?: date('Y-m-d', strtotime('-1 day'));

        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Daily Analytics Aggregation\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Target date: {$targetDate}\n\n");

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        $db = Yii::$app->db;

        // Check if we have data for this date
        // Note: analytics_element_views doesn't have is_bot, we join with sessions
        $recordCount = $db->createCommand("
            SELECT COUNT(*)
            FROM {{%analytics_element_views}} ev
            LEFT JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
            WHERE DATE(ev.created_at) = :date AND (s.is_bot = 0 OR s.is_bot IS NULL)
        ")->bindValue(':date', $targetDate)->queryScalar();

        if ($recordCount == 0) {
            $this->stdout("No element view data found for {$targetDate}\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Found {$recordCount} element view records to aggregate\n");

        if ($this->dryRun) {
            $this->stdout("Would aggregate this data (dry run)\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        // Aggregate element views by date, element, and event type
        // Filter out bot sessions by joining with analytics_sessions
        try {
            $aggregated = $db->createCommand("
                INSERT INTO {{%analytics_element_daily}}
                (date, element_uuid, element_type, page_uuid, event_type, total_views, unique_sessions, unique_users)
                SELECT
                    DATE(ev.created_at) as date,
                    ev.element_uuid,
                    ev.element_type,
                    ev.page_uuid,
                    ev.type as event_type,
                    COUNT(*) as total_views,
                    COUNT(DISTINCT ev.session_id) as unique_sessions,
                    COUNT(DISTINCT CASE WHEN ev.user_id IS NOT NULL AND ev.user_id > 0 THEN ev.user_id END) as unique_users
                FROM {{%analytics_element_views}} ev
                LEFT JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
                WHERE DATE(ev.created_at) = :date
                    AND (s.is_bot = 0 OR s.is_bot IS NULL)
                GROUP BY DATE(ev.created_at), ev.element_uuid, ev.element_type, ev.page_uuid, ev.type
                ON DUPLICATE KEY UPDATE
                    total_views = VALUES(total_views),
                    unique_sessions = VALUES(unique_sessions),
                    unique_users = VALUES(unique_users),
                    updated_at = NOW()
            ")->bindValue(':date', $targetDate)->execute();

            $this->stdout("✓ Aggregated {$aggregated} element view records\n", Console::FG_GREEN);

            // Also aggregate page views for the same date
            $pageViewCount = $db->createCommand("
                SELECT COUNT(*)
                FROM {{%analytics_page_views}}
                WHERE DATE(created_at) = :date AND is_bot = 0
            ")->bindValue(':date', $targetDate)->queryScalar();

            if ($pageViewCount > 0) {
                $this->stdout("Found {$pageViewCount} page view records\n");

                // Aggregate page views by date and page
                $pageAggregated = $db->createCommand("
                    INSERT INTO {{%analytics_page_daily}}
                    (date, page_uuid, page_url, total_views, unique_sessions, unique_users)
                    SELECT
                        DATE(created_at) as date,
                        page_uuid,
                        url,
                        COUNT(*) as total_views,
                        COUNT(DISTINCT session_id) as unique_sessions,
                        COUNT(DISTINCT CASE WHEN user_id IS NOT NULL AND user_id > 0 THEN user_id END) as unique_users
                    FROM {{%analytics_page_views}}
                    WHERE DATE(created_at) = :date AND is_bot = 0
                    GROUP BY DATE(created_at), page_uuid, url
                    ON DUPLICATE KEY UPDATE
                        total_views = VALUES(total_views),
                        unique_sessions = VALUES(unique_sessions),
                        unique_users = VALUES(unique_users),
                        updated_at = NOW()
                ")->bindValue(':date', $targetDate)->execute();

                $this->stdout("✓ Aggregated {$pageAggregated} page view records\n", Console::FG_GREEN);
            }

        } catch (\Exception $e) {
            $this->stderr("✗ Error aggregating data: " . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nDaily aggregation completed successfully\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Aggregate last month's data (run monthly via cron)
     *
     * @param string|null $yearMonth Optional year-month in Y-m format (default: last month)
     * @return int
     */
    public function actionMonthly($yearMonth = null)
    {
        if (!$yearMonth) {
            $yearMonth = date('Y-m', strtotime('first day of last month'));
        }

        list($year, $month) = explode('-', $yearMonth);

        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Monthly Analytics Aggregation\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Target period: {$year}-{$month}\n\n");

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        $db = Yii::$app->db;

        // Check if we have daily aggregates for this month (elements)
        $elementDailyCount = $db->createCommand("
            SELECT COUNT(*)
            FROM {{%analytics_element_daily}}
            WHERE YEAR(date) = :year AND MONTH(date) = :month
        ")
            ->bindValue(':year', $year)
            ->bindValue(':month', $month)
            ->queryScalar();

        // Check if we have daily aggregates for this month (pages)
        $pageDailyCount = $db->createCommand("
            SELECT COUNT(*)
            FROM {{%analytics_page_daily}}
            WHERE YEAR(date) = :year AND MONTH(date) = :month
        ")
            ->bindValue(':year', $year)
            ->bindValue(':month', $month)
            ->queryScalar();

        if ($elementDailyCount == 0 && $pageDailyCount == 0) {
            $this->stdout("No daily aggregates found for {$year}-{$month}\n", Console::FG_YELLOW);
            $this->stdout("Run daily aggregation first!\n", Console::FG_YELLOW);
            return ExitCode::DATAERR;
        }

        $this->stdout("Found {$elementDailyCount} element daily records and {$pageDailyCount} page daily records\n");

        if ($this->dryRun) {
            $this->stdout("Would create monthly aggregates (dry run)\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        // Aggregate from daily data (more efficient than raw data)
        try {
            $aggregated = $db->createCommand("
                INSERT INTO {{%analytics_element_monthly}}
                (year, month, element_uuid, element_type, event_type, total_views, unique_sessions, unique_users)
                SELECT
                    :year as year,
                    :month as month,
                    element_uuid,
                    element_type,
                    event_type,
                    SUM(total_views) as total_views,
                    SUM(unique_sessions) as unique_sessions,
                    SUM(unique_users) as unique_users
                FROM {{%analytics_element_daily}}
                WHERE YEAR(date) = :year AND MONTH(date) = :month
                GROUP BY element_uuid, element_type, event_type
                ON DUPLICATE KEY UPDATE
                    total_views = VALUES(total_views),
                    unique_sessions = VALUES(unique_sessions),
                    unique_users = VALUES(unique_users),
                    updated_at = NOW()
            ")
                ->bindValue(':year', $year)
                ->bindValue(':month', $month)
                ->execute();

            $this->stdout("✓ Aggregated {$aggregated} element monthly records\n", Console::FG_GREEN);

            // Also aggregate page views from daily data
            $pagesDailyCount = $db->createCommand("
                SELECT COUNT(*)
                FROM {{%analytics_page_daily}}
                WHERE YEAR(date) = :year AND MONTH(date) = :month
            ")
                ->bindValue(':year', $year)
                ->bindValue(':month', $month)
                ->queryScalar();

            if ($pagesDailyCount > 0) {
                $this->stdout("Found {$pagesDailyCount} daily page view aggregates\n");

                $pageAggregated = $db->createCommand("
                    INSERT INTO {{%analytics_page_monthly}}
                    (year, month, page_uuid, page_url, total_views, unique_sessions, unique_users)
                    SELECT
                        :year as year,
                        :month as month,
                        page_uuid,
                        page_url,
                        SUM(total_views) as total_views,
                        SUM(unique_sessions) as unique_sessions,
                        SUM(unique_users) as unique_users
                    FROM {{%analytics_page_daily}}
                    WHERE YEAR(date) = :year AND MONTH(date) = :month
                    GROUP BY page_uuid, page_url
                    ON DUPLICATE KEY UPDATE
                        total_views = VALUES(total_views),
                        unique_sessions = VALUES(unique_sessions),
                        unique_users = VALUES(unique_users),
                        updated_at = NOW()
                ")
                    ->bindValue(':year', $year)
                    ->bindValue(':month', $month)
                    ->execute();

                $this->stdout("✓ Aggregated {$pageAggregated} page monthly records\n", Console::FG_GREEN);
            }

        } catch (\Exception $e) {
            $this->stderr("✗ Error creating monthly aggregates: " . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nMonthly aggregation completed successfully\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Build partner statistics cache for quick dashboard queries
     *
     * @param int|null $partnerId Optional partner ID (default: all partners)
     * @return int
     */
    public function actionPartnerStats($partnerId = null)
    {
        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Partner Statistics Cache\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        $db = Yii::$app->db;

        // Get all partner elements (adjust based on your schema)
        $whereClause = '';
        $params = [];
        if ($partnerId) {
            $whereClause = "WHERE owner_id = :partnerId";
            $params[':partnerId'] = $partnerId;
            $this->stdout("Processing partner ID: {$partnerId}\n");
        } else {
            $this->stdout("Processing all partners\n");
        }

        // Check if crelish_elements table exists and has owner_id
        try {
            $elements = $db->createCommand("
                SELECT DISTINCT
                    owner_id as partner_id,
                    uuid as element_uuid,
                    ctype as element_type
                FROM {{%crelish_elements}}
                {$whereClause}
            ")->bindValues($params)->queryAll();

            if (empty($elements)) {
                $this->stdout("No partner elements found\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            $this->stdout("Found " . count($elements) . " partner elements\n\n");

            if ($this->dryRun) {
                $this->stdout("Would generate stats for these elements (dry run)\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            $processed = 0;
            $errors = 0;

            foreach ($elements as $element) {
                try {
                    $this->generatePartnerStats(
                        $element['partner_id'],
                        $element['element_uuid'],
                        $element['element_type']
                    );
                    $processed++;

                    if ($this->verbose) {
                        $this->stdout("  ✓ {$element['element_type']}: {$element['element_uuid']}\n");
                    } elseif ($processed % 100 == 0) {
                        $this->stdout("  Processed {$processed} elements...\n");
                    }
                } catch (\Exception $e) {
                    $errors++;
                    if ($this->verbose) {
                        $this->stderr("  ✗ Error: " . $e->getMessage() . "\n", Console::FG_RED);
                    }
                }
            }

            $this->stdout("\n✓ Partner statistics cache updated\n", Console::FG_GREEN);
            $this->stdout("  Processed: {$processed}\n");
            if ($errors > 0) {
                $this->stdout("  Errors: {$errors}\n", Console::FG_YELLOW);
            }

        } catch (\Exception $e) {
            $this->stderr("Error querying partner elements: " . $e->getMessage() . "\n", Console::FG_RED);
            $this->stdout("Note: Make sure crelish_elements table exists with owner_id field\n", Console::FG_YELLOW);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Generate statistics for a specific partner element
     *
     * @param int $partnerId Partner/Owner ID
     * @param string $elementUuid Element UUID
     * @param string $elementType Element type
     */
    protected function generatePartnerStats($partnerId, $elementUuid, $elementType)
    {
        $db = Yii::$app->db;

        $periods = [
            'day' => date('Y-m-d'),
            'week' => date('Y-m-d', strtotime('-7 days')),
            'month' => date('Y-m-d', strtotime('-1 month')),
            'year' => date('Y-m-d', strtotime('-1 year')),
        ];

        $eventTypes = ['list', 'detail', 'click', 'download'];

        foreach ($periods as $periodType => $periodStart) {
            foreach ($eventTypes as $eventType) {
                $stats = $db->createCommand("
                    SELECT
                        COALESCE(SUM(total_views), 0) as total_views,
                        COALESCE(SUM(unique_sessions), 0) as unique_sessions,
                        COALESCE(SUM(unique_users), 0) as unique_users
                    FROM {{%analytics_element_daily}}
                    WHERE element_uuid = :uuid
                        AND element_type = :type
                        AND event_type = :event
                        AND date >= :start
                ")
                    ->bindValue(':uuid', $elementUuid)
                    ->bindValue(':type', $elementType)
                    ->bindValue(':event', $eventType)
                    ->bindValue(':start', $periodStart)
                    ->queryOne();

                if ($stats && $stats['total_views'] > 0) {
                    $db->createCommand()->upsert('{{%analytics_partner_stats}}', [
                        'partner_id' => $partnerId,
                        'element_uuid' => $elementUuid,
                        'element_type' => $elementType,
                        'event_type' => $eventType,
                        'period_type' => $periodType,
                        'period_start' => $periodStart,
                        'total_views' => $stats['total_views'],
                        'unique_sessions' => $stats['unique_sessions'],
                        'unique_users' => $stats['unique_users'],
                    ])->execute();
                }
            }
        }
    }

    /**
     * Cleanup old raw data after aggregation
     *
     * @return int
     */
    public function actionCleanup()
    {
        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Analytics Data Cleanup\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Retention period: {$this->retentionDays} days\n\n");

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        $db = Yii::$app->db;
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$this->retentionDays} days"));

        $this->stdout("Cutoff date: {$cutoffDate}\n\n");

        // Check if we have aggregated data for the period we're about to delete
        $aggregatesCutoff = date('Y-m-d', strtotime("-{$this->retentionDays} days"));
        $hasElementAggregates = $db->createCommand("
            SELECT COUNT(*) FROM {{%analytics_element_daily}}
            WHERE date < :cutoff
        ")->bindValue(':cutoff', $aggregatesCutoff)->queryScalar();

        $hasPageAggregates = $db->createCommand("
            SELECT COUNT(*) FROM {{%analytics_page_daily}}
            WHERE date < :cutoff
        ")->bindValue(':cutoff', $aggregatesCutoff)->queryScalar();

        if ($hasElementAggregates == 0 && $hasPageAggregates == 0) {
            $this->stdout("⚠ No aggregated data found for period before {$aggregatesCutoff}\n", Console::FG_YELLOW);
            $this->stdout("Run daily aggregation first to preserve data!\n", Console::FG_YELLOW);

            if (!$this->confirm("Continue anyway?")) {
                return ExitCode::OK;
            }
        }

        // Count records to be deleted (element_views doesn't have is_bot)
        $elementViewsCount = $db->createCommand("
            SELECT COUNT(*) FROM {{%analytics_element_views}}
            WHERE created_at < :cutoff
        ")->bindValue(':cutoff', $cutoffDate)->queryScalar();

        $pageViewsCount = $db->createCommand("
            SELECT COUNT(*) FROM {{%analytics_page_views}}
            WHERE created_at < :cutoff AND is_bot = 0
        ")->bindValue(':cutoff', $cutoffDate)->queryScalar();

        $this->stdout("Records to delete:\n");
        $this->stdout("  Element views: " . number_format($elementViewsCount) . "\n");
        $this->stdout("  Page views: " . number_format($pageViewsCount) . "\n\n");

        if ($this->dryRun) {
            $this->stdout("Would delete these records (dry run)\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if ($elementViewsCount + $pageViewsCount == 0) {
            $this->stdout("No records to delete\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        if (!$this->confirm("Delete " . number_format($elementViewsCount + $pageViewsCount) . " records?")) {
            $this->stdout("Aborted\n");
            return ExitCode::OK;
        }

        // Delete old element views (no is_bot column)
        try {
            $deleted = $db->createCommand()
                ->delete('{{%analytics_element_views}}', ['<', 'created_at', $cutoffDate])
                ->execute();

            $this->stdout("✓ Deleted " . number_format($deleted) . " element view records\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("✗ Error deleting element views: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        // Delete old page views
        try {
            $deleted = $db->createCommand()
                ->delete('{{%analytics_page_views}}', [
                    'and',
                    ['<', 'created_at', $cutoffDate],
                    ['is_bot' => 0]
                ])
                ->execute();

            $this->stdout("✓ Deleted " . number_format($deleted) . " page view records\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("✗ Error deleting page views: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        // Optimize tables
        $this->stdout("\nOptimizing tables...\n");
        try {
            $db->createCommand("OPTIMIZE TABLE {{%analytics_element_views}}")->execute();
            $db->createCommand("OPTIMIZE TABLE {{%analytics_page_views}}")->execute();
            $this->stdout("✓ Tables optimized\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("✗ Error optimizing tables: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        $this->stdout("\nCleanup completed successfully\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Backfill aggregation for past dates
     *
     * @param int $days Number of days to backfill (default: 30)
     * @return int
     */
    public function actionBackfill($days = 30)
    {
        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Backfill Aggregation\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Processing last {$days} days\n\n");

        $successCount = 0;
        $errorCount = 0;

        for ($i = $days; $i >= 1; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $this->stdout("[{$date}] ", Console::FG_CYAN);

            $exitCode = $this->actionDaily($date);

            if ($exitCode === ExitCode::OK) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_GREEN);
        $this->stdout("Backfill completed\n", Console::FG_GREEN);
        $this->stdout("  Success: {$successCount} days\n");
        if ($errorCount > 0) {
            $this->stdout("  Errors: {$errorCount} days\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Show aggregation statistics
     *
     * @return int
     */
    public function actionStats()
    {
        $db = Yii::$app->db;

        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_GREEN);
        $this->stdout("Analytics Aggregation Statistics\n", Console::FG_GREEN);
        $this->stdout(str_repeat('=', 60) . "\n\n", Console::FG_GREEN);

        // Raw data stats
        $this->stdout("Raw Data:\n", Console::FG_CYAN);

        $elementViews = $db->createCommand("SELECT COUNT(*) FROM {{%analytics_element_views}}")->queryScalar();
        $pageViews = $db->createCommand("SELECT COUNT(*) FROM {{%analytics_page_views}} WHERE is_bot = 0")->queryScalar();

        $this->stdout("  Element views: " . number_format($elementViews) . "\n");
        $this->stdout("  Page views: " . number_format($pageViews) . "\n\n");

        // Aggregated data stats
        $this->stdout("Aggregated Data (Elements):\n", Console::FG_CYAN);

        $dailyRecords = $db->createCommand("SELECT COUNT(*) FROM {{%analytics_element_daily}}")->queryScalar();
        $monthlyRecords = $db->createCommand("SELECT COUNT(*) FROM {{%analytics_element_monthly}}")->queryScalar();
        $partnerCache = $db->createCommand("SELECT COUNT(*) FROM {{%analytics_partner_stats}}")->queryScalar();

        $this->stdout("  Daily aggregates: " . number_format($dailyRecords) . "\n");
        $this->stdout("  Monthly aggregates: " . number_format($monthlyRecords) . "\n");
        $this->stdout("  Partner cache: " . number_format($partnerCache) . "\n\n");

        // Page view aggregated data stats
        $this->stdout("Aggregated Data (Pages):\n", Console::FG_CYAN);

        $pageDailyRecords = $db->createCommand("SELECT COUNT(*) FROM {{%analytics_page_daily}}")->queryScalar();
        $pageMonthlyRecords = $db->createCommand("SELECT COUNT(*) FROM {{%analytics_page_monthly}}")->queryScalar();

        $this->stdout("  Daily aggregates: " . number_format($pageDailyRecords) . "\n");
        $this->stdout("  Monthly aggregates: " . number_format($pageMonthlyRecords) . "\n\n");

        // Date ranges
        $this->stdout("Date Ranges:\n", Console::FG_CYAN);

        $elementViewRange = $db->createCommand("
            SELECT MIN(created_at) as oldest, MAX(created_at) as newest
            FROM {{%analytics_element_views}}
        ")->queryOne();

        if ($elementViewRange['oldest']) {
            $this->stdout("  Raw data: {$elementViewRange['oldest']} to {$elementViewRange['newest']}\n");
        }

        $dailyRange = $db->createCommand("
            SELECT MIN(date) as oldest, MAX(date) as newest
            FROM {{%analytics_element_daily}}
        ")->queryOne();

        if ($dailyRange['oldest']) {
            $this->stdout("  Daily aggregates: {$dailyRange['oldest']} to {$dailyRange['newest']}\n");
        }

        return ExitCode::OK;
    }
}