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
 *   yii crelish/analytics-aggregation/backfill-page-uuid # Link job analytics to companies
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
     * @var int Rows deleted per statement during cleanup
     */
    public $batchSize = 5000;

    /**
     * @var bool Run OPTIMIZE TABLE after cleanup to return freed space to disk.
     *
     * Off by default: on InnoDB this rebuilds the table, needs roughly its size
     * again in free disk space, and holds a lock for the duration.
     */
    public $optimize = false;

    /**
     * @var bool Delete raw rows even for days that were never aggregated.
     *
     * Deliberately separate from --force: --force only answers the interactive
     * prompt, which cron must do, and must never double as permission to
     * destroy un-aggregated traffic.
     */
    public $skipAggregationCheck = false;

    /**
     * @var bool Skip the interactive confirmation in cleanup.
     *
     * Yii's confirm() returns its default (false) when stdin is empty, so an
     * unattended cron run silently aborts the cleanup unless this is set (or
     * the command is invoked with --interactive=0).
     */
    public $force = false;

    /**
     * Define command options
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'dryRun',
            'retentionDays',
            'verbose',
            'batchSize',
            'force',
            'optimize',
            'skipAggregationCheck',
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
            'b' => 'batchSize',
            'f' => 'force',
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

        // Half-open [start, end) range instead of DATE(created_at) = :date, which
        // is not sargable and forces a full table scan on every query below.
        $rangeStart = $targetDate . ' 00:00:00';
        $rangeEnd = date('Y-m-d', strtotime($targetDate . ' +1 day')) . ' 00:00:00';

        // Check if we have element view data for this date
        // Note: analytics_element_views doesn't have is_bot, we join with sessions
        // IMPORTANT: Use INNER JOIN to exclude orphaned element views without valid sessions
        $elementViewCount = $db->createCommand("
            SELECT COUNT(*)
            FROM {{%analytics_element_views}} ev
            INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
            WHERE ev.created_at >= :start AND ev.created_at < :end AND s.is_bot = 0
        ")->bindValue(':start', $rangeStart)->bindValue(':end', $rangeEnd)->queryScalar();

        // Check if we have page view data for this date
        $pageViewCount = $db->createCommand("
            SELECT COUNT(*)
            FROM {{%analytics_page_views}}
            WHERE created_at >= :start AND created_at < :end AND is_bot = 0
        ")->bindValue(':start', $rangeStart)->bindValue(':end', $rangeEnd)->queryScalar();

        if ($elementViewCount == 0 && $pageViewCount == 0) {
            $this->stdout("No data found for {$targetDate}\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Found {$elementViewCount} element view records and {$pageViewCount} page view records\n");

        if ($this->dryRun) {
            $this->stdout("Would aggregate this data (dry run)\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        // Aggregate element views by date, element, and event type
        // IMPORTANT: Use INNER JOIN to exclude orphaned element views
        if ($elementViewCount > 0) {
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
                    INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
                    WHERE ev.created_at >= :start AND ev.created_at < :end
                        AND s.is_bot = 0
                    GROUP BY DATE(ev.created_at), ev.element_uuid, ev.element_type, ev.page_uuid, ev.type
                    ON DUPLICATE KEY UPDATE
                        total_views = VALUES(total_views),
                        unique_sessions = VALUES(unique_sessions),
                        unique_users = VALUES(unique_users),
                        updated_at = NOW()
                ")->bindValue(':start', $rangeStart)->bindValue(':end', $rangeEnd)->execute();

                $this->stdout("✓ Aggregated {$aggregated} element view records\n", Console::FG_GREEN);

            } catch (\Exception $e) {
                $this->stderr("✗ Error aggregating element data: " . $e->getMessage() . "\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } else {
            $this->stdout("No element view data for this date\n", Console::FG_YELLOW);
        }

        // Aggregate page views for the same date (independent of element aggregation)
        if ($pageViewCount > 0) {
            try {
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
                    WHERE created_at >= :start AND created_at < :end AND is_bot = 0
                    GROUP BY DATE(created_at), page_uuid, url
                    ON DUPLICATE KEY UPDATE
                        total_views = VALUES(total_views),
                        unique_sessions = VALUES(unique_sessions),
                        unique_users = VALUES(unique_users),
                        updated_at = NOW()
                ")->bindValue(':start', $rangeStart)->bindValue(':end', $rangeEnd)->execute();

                $this->stdout("✓ Aggregated {$pageAggregated} page view records\n", Console::FG_GREEN);

            } catch (\Exception $e) {
                $this->stderr("✗ Error aggregating page data: " . $e->getMessage() . "\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } else {
            $this->stdout("No page view data for this date\n", Console::FG_YELLOW);
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

        // Aggregate element views from raw data to ensure accurate unique counts
        // Note: Cannot sum unique_sessions/unique_users from daily data as it would overcount
        if ($elementDailyCount > 0) {
            try {
                $aggregated = $db->createCommand("
                    INSERT INTO {{%analytics_element_monthly}}
                    (year, month, element_uuid, element_type, event_type, total_views, unique_sessions, unique_users)
                    SELECT
                        YEAR(ev.created_at) as year,
                        MONTH(ev.created_at) as month,
                        ev.element_uuid,
                        ev.element_type,
                        ev.type as event_type,
                        COUNT(*) as total_views,
                        COUNT(DISTINCT ev.session_id) as unique_sessions,
                        COUNT(DISTINCT CASE WHEN ev.user_id IS NOT NULL AND ev.user_id > 0 THEN ev.user_id END) as unique_users
                    FROM {{%analytics_element_views}} ev
                    INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
                    WHERE YEAR(ev.created_at) = :year
                        AND MONTH(ev.created_at) = :month
                        AND s.is_bot = 0
                    GROUP BY YEAR(ev.created_at), MONTH(ev.created_at), ev.element_uuid, ev.element_type, ev.type
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

            } catch (\Exception $e) {
                $this->stderr("✗ Error creating element monthly aggregates: " . $e->getMessage() . "\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } else {
            $this->stdout("No element daily data to aggregate for this month\n", Console::FG_YELLOW);
        }

        // Aggregate page views from raw data to ensure accurate unique counts
        // Note: Cannot sum unique_sessions/unique_users from daily data as it would overcount
        if ($pageDailyCount > 0) {
            try {
                $pageAggregated = $db->createCommand("
                    INSERT INTO {{%analytics_page_monthly}}
                    (year, month, page_uuid, page_url, total_views, unique_sessions, unique_users)
                    SELECT
                        YEAR(created_at) as year,
                        MONTH(created_at) as month,
                        page_uuid,
                        url,
                        COUNT(*) as total_views,
                        COUNT(DISTINCT session_id) as unique_sessions,
                        COUNT(DISTINCT CASE WHEN user_id IS NOT NULL AND user_id > 0 THEN user_id END) as unique_users
                    FROM {{%analytics_page_views}}
                    WHERE YEAR(created_at) = :year
                        AND MONTH(created_at) = :month
                        AND is_bot = 0
                    GROUP BY YEAR(created_at), MONTH(created_at), page_uuid, url
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

            } catch (\Exception $e) {
                $this->stderr("✗ Error creating page monthly aggregates: " . $e->getMessage() . "\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } else {
            $this->stdout("No page daily data to aggregate for this month\n", Console::FG_YELLOW);
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
     * Note: Queries raw data to get accurate unique counts (cannot sum from daily aggregates)
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
                // Query raw data for accurate unique counts
                // Summing unique_sessions/unique_users from daily aggregates would overcount
                $stats = $db->createCommand("
                    SELECT
                        COUNT(*) as total_views,
                        COUNT(DISTINCT ev.session_id) as unique_sessions,
                        COUNT(DISTINCT CASE WHEN ev.user_id IS NOT NULL AND ev.user_id > 0 THEN ev.user_id END) as unique_users
                    FROM {{%analytics_element_views}} ev
                    INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
                    WHERE ev.element_uuid = :uuid
                        AND ev.element_type = :type
                        AND ev.type = :event
                        AND DATE(ev.created_at) >= :start
                        AND s.is_bot = 0
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

        // Verify every day about to be deleted actually made it into the daily
        // aggregates. The previous check only asked whether *any* aggregate row
        // existed before the cutoff, which is true as soon as the site has any
        // history at all - so a multi-month aggregation outage sailed straight
        // past it and the raw rows were deleted unaggregated.
        if (!$this->skipAggregationCheck) {
            $unaggregated = $this->findUnaggregatedDays($cutoffDate);

            if (!empty($unaggregated)) {
                $shown = array_slice($unaggregated, 0, 10);

                $this->stderr("\n✗ Refusing to delete: " . count($unaggregated)
                    . " day(s) in the deletion range have raw traffic but no daily aggregate.\n", Console::FG_RED);
                $this->stderr("  " . implode(', ', $shown)
                    . (count($unaggregated) > count($shown) ? ', ...' : '') . "\n\n", Console::FG_RED);
                $this->stderr("Deleting now would lose this traffic permanently. Run:\n", Console::FG_YELLOW);
                $this->stderr("  yii crelish/analytics-aggregation/backfill <days>\n\n", Console::FG_YELLOW);
                $this->stderr("Override with --skipAggregationCheck=1 only if the loss is intended.\n", Console::FG_YELLOW);

                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("✓ Aggregate coverage verified for the deletion range\n\n", Console::FG_GREEN);
        } else {
            $this->stdout("⚠ Aggregation coverage check skipped (--skipAggregationCheck)\n\n", Console::FG_YELLOW);
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

        // Only orphans newer than the cutoff are counted here: anything older is
        // already covered by the age-based delete above, which runs first. This
        // keeps the reported total from double-counting the same rows.
        $orphanedElementViewsCount = $db->createCommand("
            SELECT COUNT(*) FROM {{%analytics_element_views}} ev
            LEFT JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
            WHERE s.session_id IS NULL AND ev.created_at >= :cutoff
        ")->bindValue(':cutoff', $cutoffDate)->queryScalar();

        $orphanedSessionsCount = $db->createCommand("
            SELECT COUNT(*) FROM {{%analytics_sessions}} s
            WHERE s.created_at < :cutoff
              AND NOT EXISTS (
                SELECT 1 FROM {{%analytics_page_views}} pv WHERE pv.session_id = s.session_id
              )
        ")->bindValue(':cutoff', $cutoffDate)->queryScalar();

        $this->stdout("Records to delete:\n");
        $this->stdout("  Element views (older than cutoff): " . number_format($elementViewsCount) . "\n");
        $this->stdout("  Element views (orphaned, any age): " . number_format($orphanedElementViewsCount) . "\n");
        $this->stdout("  Page views: " . number_format($pageViewsCount) . "\n");
        $this->stdout("  Sessions (orphaned, older than cutoff): " . number_format($orphanedSessionsCount) . "\n\n");

        if ($this->dryRun) {
            $this->stdout("Would delete these records (dry run)\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $totalToDelete = $elementViewsCount + $pageViewsCount
            + $orphanedElementViewsCount + $orphanedSessionsCount;

        if ($totalToDelete == 0) {
            $this->stdout("No records to delete\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        if (!$this->confirmDestructive("Delete " . number_format($totalToDelete) . " records?")) {
            $this->stdout("Aborted\n");
            return ExitCode::OK;
        }

        // Delete old element views (no is_bot column)
        try {
            $deleted = $this->deleteInBatches(
                "DELETE FROM {{%analytics_element_views}} WHERE created_at < :cutoff LIMIT :limit",
                [':cutoff' => $cutoffDate]
            );

            $this->stdout("✓ Deleted " . number_format($deleted) . " element view records\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("✗ Error deleting element views: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        // Delete old page views
        try {
            $deleted = $this->deleteInBatches(
                "DELETE FROM {{%analytics_page_views}} WHERE created_at < :cutoff AND is_bot = 0 LIMIT :limit",
                [':cutoff' => $cutoffDate]
            );

            $this->stdout("✓ Deleted " . number_format($deleted) . " page view records\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("✗ Error deleting page views: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        // Delete element views whose session no longer exists. These are invisible
        // to every report (all aggregation INNER JOINs analytics_sessions), so they
        // are pure dead weight. Previously this was only possible by hand, via
        // commands/CLEANUP_ORPHANED_ELEMENT_VIEWS.sql.
        try {
            $deleted = $this->deleteOrphanedElementViews();
            $this->stdout("✓ Deleted " . number_format($deleted) . " orphaned element view records\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("✗ Error deleting orphaned element views: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        // Delete sessions that no longer have any page views referencing them.
        // Without this analytics_sessions grows without bound - it was never
        // covered by cleanup, and its rows outlive the page views they describe.
        try {
            $deleted = $this->deleteInBatches(
                "DELETE FROM {{%analytics_sessions}}
                 WHERE created_at < :cutoff
                   AND NOT EXISTS (
                     SELECT 1 FROM {{%analytics_page_views}} pv
                     WHERE pv.session_id = {{%analytics_sessions}}.session_id
                   )
                 LIMIT :limit",
                [':cutoff' => $cutoffDate]
            );

            $this->stdout("✓ Deleted " . number_format($deleted) . " orphaned session records\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("✗ Error deleting sessions: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        // Optimize tables. On InnoDB this rebuilds the table to return freed pages
        // to the filesystem; it needs roughly the table's size in free disk space
        // and locks the table for the duration, so it is opt-in.
        if ($this->optimize) {
            $this->stdout("\nOptimizing tables...\n");
            foreach (['analytics_element_views', 'analytics_page_views', 'analytics_sessions'] as $table) {
                try {
                    $db->createCommand('OPTIMIZE TABLE ' . $db->quoteTableName($table))->execute();
                    $this->stdout("✓ Optimized {$table}\n", Console::FG_GREEN);
                } catch (\Exception $e) {
                    $this->stderr("✗ Error optimizing {$table}: " . $e->getMessage() . "\n", Console::FG_RED);
                }
            }
        } else {
            $this->stdout("\nSkipping OPTIMIZE TABLE (pass --optimize=1 to reclaim disk space)\n", Console::FG_YELLOW);
        }

        $this->stdout("\nCleanup completed successfully\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Find days in the deletion range that hold reportable raw traffic but have
     * no corresponding row in the daily aggregates.
     *
     * @param string $cutoffDate Rows older than this are the ones to be deleted
     * @return string[] Y-m-d dates, ascending
     */
    protected function findUnaggregatedDays(string $cutoffDate): array
    {
        // Both streams are checked independently: actionDaily() aggregates element
        // views and page views in separate statements, so one can succeed while the
        // other throws, leaving a day half-covered.
        $days = array_merge(
            $this->findGapDays(
                $cutoffDate,
                '{{%analytics_element_daily}}',
                "SELECT EXISTS (
                    SELECT 1
                    FROM {{%analytics_element_views}} ev
                    INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
                    WHERE ev.created_at >= :start AND ev.created_at < :end
                      AND s.is_bot = 0
                 )",
                "SELECT MIN(created_at) FROM {{%analytics_element_views}} WHERE created_at < :cutoff"
            ),
            $this->findGapDays(
                $cutoffDate,
                '{{%analytics_page_daily}}',
                "SELECT EXISTS (
                    SELECT 1
                    FROM {{%analytics_page_views}}
                    WHERE created_at >= :start AND created_at < :end
                      AND is_bot = 0
                 )",
                "SELECT MIN(created_at) FROM {{%analytics_page_views}} WHERE created_at < :cutoff AND is_bot = 0"
            )
        );

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * Days holding reportable raw traffic with no row in the given aggregate table.
     *
     * @param string $cutoffDate Rows older than this are the ones to be deleted
     * @param string $aggregateTable Aggregate table to test coverage against
     * @param string $probeSql EXISTS query taking :start and :end
     * @param string $firstRawSql MIN(created_at) query taking :cutoff
     * @return string[] Y-m-d dates
     */
    protected function findGapDays(
        string $cutoffDate,
        string $aggregateTable,
        string $probeSql,
        string $firstRawSql
    ): array {
        $db = Yii::$app->db;
        $cutoffDay = substr($cutoffDate, 0, 10);

        $firstRaw = $db->createCommand($firstRawSql)
            ->bindValue(':cutoff', $cutoffDate)
            ->queryScalar();

        if ($firstRaw === null) {
            return [];
        }

        // Day list comes from the small aggregate table, so the expensive per-day
        // probe below only runs for days already missing a row - normally none.
        $covered = array_flip($db->createCommand("
            SELECT DISTINCT date FROM {$aggregateTable}
            WHERE date >= :from AND date < :to
        ")
            ->bindValue(':from', substr($firstRaw, 0, 10))
            ->bindValue(':to', $cutoffDay)
            ->queryColumn());

        $gaps = [];
        $day = new \DateTimeImmutable(substr($firstRaw, 0, 10));
        $end = new \DateTimeImmutable($cutoffDay);
        $oneDay = new \DateInterval('P1D');

        for (; $day < $end; $day = $day->add($oneDay)) {
            $date = $day->format('Y-m-d');

            if (isset($covered[$date])) {
                continue;
            }

            // A day whose only raw rows are bot or orphaned traffic is not a gap:
            // aggregation legitimately produces nothing for it, and reporting it
            // would block cleanup permanently.
            $hasReportable = $db->createCommand($probeSql)
                ->bindValue(':start', $date . ' 00:00:00')
                ->bindValue(':end', $day->add($oneDay)->format('Y-m-d') . ' 00:00:00')
                ->queryScalar();

            if ($hasReportable) {
                $gaps[] = $date;
            }
        }

        return $gaps;
    }

    /**
     * Delete element views whose session row no longer exists, in batches.
     *
     * analytics_element_views has no index on session_id and MariaDB rejects
     * LIMIT on a multi-table DELETE, so rows are located by walking the primary
     * key forward and deleted by id list.
     *
     * @return int Total rows deleted
     */
    protected function deleteOrphanedElementViews(): int
    {
        $db = Yii::$app->db;
        $batchSize = max(1, (int)$this->batchSize);
        $total = 0;
        $lastId = 0;

        do {
            $ids = $db->createCommand("
                SELECT ev.id
                FROM {{%analytics_element_views}} ev
                LEFT JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
                WHERE s.session_id IS NULL
                  AND ev.id > :lastId
                ORDER BY ev.id
                LIMIT :limit
            ")
                ->bindValue(':lastId', $lastId)
                ->bindValue(':limit', $batchSize)
                ->queryColumn();

            if (empty($ids)) {
                break;
            }

            $lastId = end($ids);
            $total += $db->createCommand()
                ->delete('{{%analytics_element_views}}', ['id' => $ids])
                ->execute();

            if ($this->verbose) {
                $this->stdout("  orphaned element views deleted: " . number_format($total) . "\r");
            }
        } while (count($ids) == $batchSize);

        return $total;
    }

    /**
     * Confirm a destructive step, unless running unattended.
     *
     * Yii's confirm() reads stdin and falls back to its default (false) when
     * stdin is empty, so under cron the answer is always "no" and the step is
     * silently skipped. --force (or --interactive=0) bypasses the prompt.
     *
     * @param string $message
     * @return bool
     */
    protected function confirmDestructive(string $message): bool
    {
        if ($this->force || !$this->interactive) {
            return true;
        }

        return $this->confirm($message);
    }

    /**
     * Run a DELETE ... LIMIT :limit statement repeatedly until no rows match.
     *
     * Deleting a multi-month backlog in one statement builds a single very large
     * transaction and undo log, which on shared hosting usually ends in a lock
     * wait timeout and leaves nothing deleted.
     *
     * @param string $sql    Statement containing a :limit placeholder
     * @param array  $params Additional bound parameters
     * @return int Total rows deleted
     */
    protected function deleteInBatches(string $sql, array $params = []): int
    {
        $db = Yii::$app->db;
        $batchSize = max(1, (int)$this->batchSize);
        $total = 0;

        do {
            $command = $db->createCommand($sql)->bindValue(':limit', $batchSize);
            foreach ($params as $name => $value) {
                $command->bindValue($name, $value);
            }

            $deleted = $command->execute();
            $total += $deleted;

            if ($this->verbose && $deleted > 0) {
                $this->stdout("  deleted so far: " . number_format($total) . "\r");
            }
        } while ($deleted == $batchSize);

        return $total;
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
     * Backfill page_uuid for job records in analytics tables.
     *
     * Overwrites page_uuid with the company UUID (from job.company) for all job rows.
     * Old data has page_uuid set to the page where the job was displayed, but the
     * company analytics dashboard needs page_uuid = company UUID.
     *
     * Because the unique constraint includes page_uuid, rows that were tracked on
     * different pages for the same job+date+event get merged (totals summed).
     *
     * Usage:
     *   yii crelish/analytics-aggregation/backfill-page-uuid          # Run backfill
     *   yii crelish/analytics-aggregation/backfill-page-uuid -d       # Dry run
     *
     * @return int
     */
    public function actionBackfillPageUuid()
    {
        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Backfill page_uuid for Job Analytics\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        $db = Yii::$app->db;

        // --- 1. Backfill analytics_element_daily ---

        // Find job rows where page_uuid doesn't match the job's company
        $dailyCount = $db->createCommand("
            SELECT COUNT(*)
            FROM {{%analytics_element_daily}} aed
            INNER JOIN {{%job}} j ON aed.element_uuid = j.uuid
            WHERE aed.element_type = 'job'
              AND j.company IS NOT NULL
              AND j.company != ''
              AND (aed.page_uuid IS NULL OR aed.page_uuid != j.company)
        ")->queryScalar();

        $this->stdout("Daily rows to backfill: " . number_format($dailyCount) . "\n");

        if ($dailyCount > 0 && $this->verbose) {
            $samples = $db->createCommand("
                SELECT aed.date, aed.element_uuid, aed.event_type, aed.total_views,
                       aed.page_uuid as old_page_uuid, j.company, j.systitle
                FROM {{%analytics_element_daily}} aed
                INNER JOIN {{%job}} j ON aed.element_uuid = j.uuid
                WHERE aed.element_type = 'job'
                  AND j.company IS NOT NULL
                  AND j.company != ''
                  AND (aed.page_uuid IS NULL OR aed.page_uuid != j.company)
                ORDER BY aed.total_views DESC
                LIMIT 5
            ")->queryAll();

            $this->stdout("\nSample rows:\n");
            foreach ($samples as $row) {
                $this->stdout("  [{$row['date']}] {$row['systitle']} ({$row['event_type']}: {$row['total_views']} views)\n");
                $this->stdout("    page_uuid: {$row['old_page_uuid']} -> {$row['company']}\n");
            }
            $this->stdout("\n");
        }

        if ($dailyCount > 0 && !$this->dryRun) {
            try {
                // Step 1: Upsert rows with company UUID as page_uuid.
                // Multiple rows for the same job on different pages get merged (totals summed).
                $upserted = $db->createCommand("
                    INSERT INTO {{%analytics_element_daily}}
                        (date, element_uuid, element_type, page_uuid, event_type, total_views, unique_sessions, unique_users)
                    SELECT
                        aed.date, aed.element_uuid, aed.element_type, j.company, aed.event_type,
                        SUM(aed.total_views), SUM(aed.unique_sessions), SUM(aed.unique_users)
                    FROM {{%analytics_element_daily}} aed
                    INNER JOIN {{%job}} j ON aed.element_uuid = j.uuid
                    WHERE aed.element_type = 'job'
                      AND j.company IS NOT NULL
                      AND j.company != ''
                      AND (aed.page_uuid IS NULL OR aed.page_uuid != j.company)
                    GROUP BY aed.date, aed.element_uuid, aed.element_type, j.company, aed.event_type
                    ON DUPLICATE KEY UPDATE
                        total_views = {{%analytics_element_daily}}.total_views + VALUES(total_views),
                        unique_sessions = {{%analytics_element_daily}}.unique_sessions + VALUES(unique_sessions),
                        unique_users = {{%analytics_element_daily}}.unique_users + VALUES(unique_users),
                        updated_at = NOW()
                ")->execute();

                $this->stdout("  Upserted rows with company page_uuid\n", Console::FG_GREEN);

                // Step 2: Delete the old rows (data has been moved/merged above)
                $deleted = $db->createCommand("
                    DELETE aed FROM {{%analytics_element_daily}} aed
                    INNER JOIN {{%job}} j ON aed.element_uuid = j.uuid
                    WHERE aed.element_type = 'job'
                      AND j.company IS NOT NULL
                      AND j.company != ''
                      AND aed.page_uuid != j.company
                ")->execute();

                $this->stdout("  Cleaned up {$deleted} old rows\n", Console::FG_GREEN);

            } catch (\Exception $e) {
                $this->stderr("Error backfilling daily data: " . $e->getMessage() . "\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // --- 2. Backfill analytics_element_views (raw data, if it still exists) ---

        try {
            $rawCount = $db->createCommand("
                SELECT COUNT(*)
                FROM {{%analytics_element_views}} ev
                INNER JOIN {{%job}} j ON ev.element_uuid = j.uuid
                WHERE ev.element_type = 'job'
                  AND j.company IS NOT NULL
                  AND j.company != ''
                  AND (ev.page_uuid IS NULL OR ev.page_uuid != j.company)
            ")->queryScalar();

            $this->stdout("Raw element views to backfill: " . number_format($rawCount) . "\n");

            if ($rawCount > 0 && !$this->dryRun) {
                $updated = $db->createCommand("
                    UPDATE {{%analytics_element_views}} ev
                    INNER JOIN {{%job}} j ON ev.element_uuid = j.uuid
                    SET ev.page_uuid = j.company
                    WHERE ev.element_type = 'job'
                      AND j.company IS NOT NULL
                      AND j.company != ''
                      AND (ev.page_uuid IS NULL OR ev.page_uuid != j.company)
                ")->execute();

                $this->stdout("  Updated {$updated} raw view rows\n", Console::FG_GREEN);
            }
        } catch (\Exception $e) {
            // Raw data may have been cleaned up already
            $this->stdout("Raw data table not available (may have been cleaned up)\n", Console::FG_YELLOW);
        }

        // --- Summary ---

        if ($this->dryRun) {
            $this->stdout("\nDry run complete - no changes made\n", Console::FG_YELLOW);
        } else {
            $this->stdout("\nBackfill completed successfully\n", Console::FG_GREEN);
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