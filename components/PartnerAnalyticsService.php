<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\Component;
use yii\db\Query;

/**
 * Partner Analytics Service
 *
 * Provides high-level methods to query aggregated analytics data for partners.
 * Use this service to build partner dashboards and statistics pages.
 *
 * Usage in config:
 * ```php
 * 'components' => [
 *     'partnerAnalytics' => [
 *         'class' => 'giantbits\crelish\components\PartnerAnalyticsService',
 *     ],
 * ],
 * ```
 *
 * Usage in code:
 * ```php
 * // Get stats for a specific element
 * $stats = Yii::$app->partnerAnalytics->getElementStats($elementUuid, 'click', '30days');
 *
 * // Get all elements for a partner with stats
 * $elements = Yii::$app->partnerAnalytics->getPartnerElements($partnerId, 'news', '30days');
 *
 * // Get trending elements
 * $trending = Yii::$app->partnerAnalytics->getTrendingElements($partnerId, 10, '7days');
 * ```
 */
class PartnerAnalyticsService extends Component
{
    /**
     * Get statistics for a specific element
     *
     * @param string $elementUuid Element UUID
     * @param string|null $eventType Optional event type filter (list, detail, click, download)
     * @param string $period Period to query (today, 7days, 30days, month, year, all)
     * @return array Array of statistics grouped by event type
     */
    public function getElementStats($elementUuid, $eventType = null, $period = '30days')
    {
        $db = Yii::$app->db;

        list($startDate, $endDate) = $this->getPeriodDates($period);

        $query = "
            SELECT
                event_type,
                SUM(total_views) as views,
                SUM(unique_sessions) as sessions,
                SUM(unique_users) as users
            FROM {{%analytics_element_daily}}
            WHERE element_uuid = :uuid
                AND date >= :start
                AND date <= :end
        ";

        $params = [':uuid' => $elementUuid, ':start' => $startDate, ':end' => $endDate];

        if ($eventType) {
            $query .= " AND event_type = :event";
            $params[':event'] = $eventType;
        }

        $query .= " GROUP BY event_type ORDER BY event_type";

        $results = $db->createCommand($query)->bindValues($params)->queryAll();

        // Convert to associative array with event type as key
        $stats = [];
        foreach ($results as $row) {
            $stats[$row['event_type']] = [
                'views' => (int)$row['views'],
                'sessions' => (int)$row['sessions'],
                'users' => (int)$row['users'],
            ];
        }

        return $stats;
    }

    /**
     * Get detailed stats for an element with daily breakdown
     *
     * @param string $elementUuid Element UUID
     * @param string $eventType Event type (list, detail, click, download)
     * @param string $period Period to query
     * @return array Daily breakdown of stats
     */
    public function getElementStatsByDay($elementUuid, $eventType, $period = '30days')
    {
        $db = Yii::$app->db;

        list($startDate, $endDate) = $this->getPeriodDates($period);

        return $db->createCommand("
            SELECT
                date,
                total_views as views,
                unique_sessions as sessions,
                unique_users as users
            FROM {{%analytics_element_daily}}
            WHERE element_uuid = :uuid
                AND event_type = :event
                AND date >= :start
                AND date <= :end
            ORDER BY date ASC
        ")
            ->bindValue(':uuid', $elementUuid)
            ->bindValue(':event', $eventType)
            ->bindValue(':start', $startDate)
            ->bindValue(':end', $endDate)
            ->queryAll();
    }

    /**
     * Get all elements for a partner with their aggregate stats
     *
     * @param int $partnerId Partner/Owner ID
     * @param string|null $elementType Optional element type filter (news, job, company, etc.)
     * @param string $period Period to query
     * @return array Array of elements with their stats
     */
    public function getPartnerElements($partnerId, $elementType = null, $period = '30days')
    {
        $db = Yii::$app->db;

        list($startDate, $endDate) = $this->getPeriodDates($period);

        $typeFilter = $elementType ? "AND e.ctype = :ctype" : "";

        $results = $db->createCommand("
            SELECT
                e.uuid,
                e.ctype,
                e.title,
                d.event_type,
                COALESCE(SUM(d.total_views), 0) as views,
                COALESCE(SUM(d.unique_sessions), 0) as sessions,
                COALESCE(SUM(d.unique_users), 0) as users
            FROM {{%crelish_elements}} e
            LEFT JOIN {{%analytics_element_daily}} d ON e.uuid = d.element_uuid
                AND d.date >= :start
                AND d.date <= :end
            WHERE e.owner_id = :partnerId
                {$typeFilter}
            GROUP BY e.uuid, e.ctype, e.title, d.event_type
            ORDER BY views DESC
        ")
            ->bindValue(':partnerId', $partnerId)
            ->bindValue(':start', $startDate)
            ->bindValue(':end', $endDate)
            ->bindValue(':ctype', $elementType)
            ->queryAll();

        return $this->restructureResults($results);
    }

    /**
     * Get partner elements with pagination
     *
     * @param int $partnerId Partner ID
     * @param string|null $elementType Element type filter
     * @param string $period Period
     * @param int $page Page number (1-based)
     * @param int $pageSize Items per page
     * @return array ['items' => [...], 'total' => int, 'pages' => int]
     */
    public function getPartnerElementsPaginated($partnerId, $elementType = null, $period = '30days', $page = 1, $pageSize = 20)
    {
        $db = Yii::$app->db;

        list($startDate, $endDate) = $this->getPeriodDates($period);

        $typeFilter = $elementType ? "AND e.ctype = :ctype" : "";

        // Count total
        $total = (int)$db->createCommand("
            SELECT COUNT(DISTINCT e.uuid)
            FROM {{%crelish_elements}} e
            WHERE e.owner_id = :partnerId
                {$typeFilter}
        ")
            ->bindValue(':partnerId', $partnerId)
            ->bindValue(':ctype', $elementType)
            ->queryScalar();

        $offset = ($page - 1) * $pageSize;

        // Get paginated results
        $results = $db->createCommand("
            SELECT
                e.uuid,
                e.ctype,
                e.title,
                e.created_at,
                d.event_type,
                COALESCE(SUM(d.total_views), 0) as views,
                COALESCE(SUM(d.unique_sessions), 0) as sessions,
                COALESCE(SUM(d.unique_users), 0) as users
            FROM {{%crelish_elements}} e
            LEFT JOIN {{%analytics_element_daily}} d ON e.uuid = d.element_uuid
                AND d.date >= :start
                AND d.date <= :end
            WHERE e.owner_id = :partnerId
                {$typeFilter}
            GROUP BY e.uuid, e.ctype, e.title, e.created_at, d.event_type
            ORDER BY views DESC
            LIMIT :limit OFFSET :offset
        ")
            ->bindValue(':partnerId', $partnerId)
            ->bindValue(':start', $startDate)
            ->bindValue(':end', $endDate)
            ->bindValue(':ctype', $elementType)
            ->bindValue(':limit', $pageSize)
            ->bindValue(':offset', $offset)
            ->queryAll();

        return [
            'items' => $this->restructureResults($results),
            'total' => $total,
            'pages' => ceil($total / $pageSize),
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Get trending elements (most viewed in period)
     *
     * @param int $partnerId Partner ID
     * @param int $limit Number of results
     * @param string $period Period to analyze
     * @return array Top trending elements
     */
    public function getTrendingElements($partnerId, $limit = 10, $period = '7days')
    {
        $db = Yii::$app->db;

        list($startDate, $endDate) = $this->getPeriodDates($period);

        return $db->createCommand("
            SELECT
                e.uuid,
                e.ctype,
                e.title,
                SUM(CASE WHEN d.event_type = 'list' THEN d.total_views ELSE 0 END) as list_views,
                SUM(CASE WHEN d.event_type = 'detail' THEN d.total_views ELSE 0 END) as detail_views,
                SUM(CASE WHEN d.event_type = 'click' THEN d.total_views ELSE 0 END) as clicks,
                SUM(CASE WHEN d.event_type = 'download' THEN d.total_views ELSE 0 END) as downloads,
                SUM(d.total_views) as total_views
            FROM {{%crelish_elements}} e
            INNER JOIN {{%analytics_element_daily}} d ON e.uuid = d.element_uuid
            WHERE e.owner_id = :partnerId
                AND d.date >= :start
                AND d.date <= :end
            GROUP BY e.uuid, e.ctype, e.title
            HAVING total_views > 0
            ORDER BY detail_views DESC, total_views DESC
            LIMIT :limit
        ")
            ->bindValue(':partnerId', $partnerId)
            ->bindValue(':start', $startDate)
            ->bindValue(':end', $endDate)
            ->bindValue(':limit', $limit)
            ->queryAll();
    }

    /**
     * Get partner overview statistics
     *
     * @param int $partnerId Partner ID
     * @param string $period Period to analyze
     * @return array Summary statistics
     */
    public function getPartnerOverview($partnerId, $period = '30days')
    {
        $db = Yii::$app->db;

        list($startDate, $endDate) = $this->getPeriodDates($period);

        // Get total stats across all elements
        $stats = $db->createCommand("
            SELECT
                COUNT(DISTINCT e.uuid) as total_elements,
                COUNT(DISTINCT e.ctype) as element_types,
                SUM(CASE WHEN d.event_type = 'list' THEN d.total_views ELSE 0 END) as list_views,
                SUM(CASE WHEN d.event_type = 'detail' THEN d.total_views ELSE 0 END) as detail_views,
                SUM(CASE WHEN d.event_type = 'click' THEN d.total_views ELSE 0 END) as clicks,
                SUM(CASE WHEN d.event_type = 'download' THEN d.total_views ELSE 0 END) as downloads,
                COUNT(DISTINCT d.session_id) as unique_sessions
            FROM {{%crelish_elements}} e
            LEFT JOIN {{%analytics_element_daily}} d ON e.uuid = d.element_uuid
                AND d.date >= :start
                AND d.date <= :end
            WHERE e.owner_id = :partnerId
        ")
            ->bindValue(':partnerId', $partnerId)
            ->bindValue(':start', $startDate)
            ->bindValue(':end', $endDate)
            ->queryOne();

        // Get breakdown by element type
        $byType = $db->createCommand("
            SELECT
                e.ctype,
                COUNT(DISTINCT e.uuid) as element_count,
                SUM(CASE WHEN d.event_type = 'detail' THEN d.total_views ELSE 0 END) as detail_views,
                SUM(CASE WHEN d.event_type = 'click' THEN d.total_views ELSE 0 END) as clicks
            FROM {{%crelish_elements}} e
            LEFT JOIN {{%analytics_element_daily}} d ON e.uuid = d.element_uuid
                AND d.date >= :start
                AND d.date <= :end
            WHERE e.owner_id = :partnerId
            GROUP BY e.ctype
            ORDER BY detail_views DESC
        ")
            ->bindValue(':partnerId', $partnerId)
            ->bindValue(':start', $startDate)
            ->bindValue(':end', $endDate)
            ->queryAll();

        return [
            'summary' => [
                'total_elements' => (int)$stats['total_elements'],
                'element_types' => (int)$stats['element_types'],
                'list_views' => (int)$stats['list_views'],
                'detail_views' => (int)$stats['detail_views'],
                'clicks' => (int)$stats['clicks'],
                'downloads' => (int)$stats['downloads'],
                'unique_sessions' => (int)$stats['unique_sessions'],
            ],
            'by_type' => $byType,
        ];
    }

    /**
     * Get performance comparison between two periods
     *
     * @param string $elementUuid Element UUID
     * @param string $eventType Event type
     * @param string $period Current period
     * @param string $comparePeriod Comparison period
     * @return array Comparison data with percentage changes
     */
    public function comparePerformance($elementUuid, $eventType, $period = '7days', $comparePeriod = '7days')
    {
        $db = Yii::$app->db;

        // Current period
        list($currentStart, $currentEnd) = $this->getPeriodDates($period);

        $current = $db->createCommand("
            SELECT
                SUM(total_views) as views,
                SUM(unique_sessions) as sessions
            FROM {{%analytics_element_daily}}
            WHERE element_uuid = :uuid
                AND event_type = :event
                AND date >= :start
                AND date <= :end
        ")
            ->bindValue(':uuid', $elementUuid)
            ->bindValue(':event', $eventType)
            ->bindValue(':start', $currentStart)
            ->bindValue(':end', $currentEnd)
            ->queryOne();

        // Previous period
        $daysDiff = (strtotime($currentEnd) - strtotime($currentStart)) / 86400;
        $previousStart = date('Y-m-d', strtotime($currentStart . " -{$daysDiff} days"));
        $previousEnd = date('Y-m-d', strtotime($currentStart . " -1 day"));

        $previous = $db->createCommand("
            SELECT
                SUM(total_views) as views,
                SUM(unique_sessions) as sessions
            FROM {{%analytics_element_daily}}
            WHERE element_uuid = :uuid
                AND event_type = :event
                AND date >= :start
                AND date <= :end
        ")
            ->bindValue(':uuid', $elementUuid)
            ->bindValue(':event', $eventType)
            ->bindValue(':start', $previousStart)
            ->bindValue(':end', $previousEnd)
            ->queryOne();

        return [
            'current' => [
                'views' => (int)($current['views'] ?? 0),
                'sessions' => (int)($current['sessions'] ?? 0),
                'start' => $currentStart,
                'end' => $currentEnd,
            ],
            'previous' => [
                'views' => (int)($previous['views'] ?? 0),
                'sessions' => (int)($previous['sessions'] ?? 0),
                'start' => $previousStart,
                'end' => $previousEnd,
            ],
            'change' => [
                'views' => $this->calculatePercentageChange(
                    $previous['views'] ?? 0,
                    $current['views'] ?? 0
                ),
                'sessions' => $this->calculatePercentageChange(
                    $previous['sessions'] ?? 0,
                    $current['sessions'] ?? 0
                ),
            ],
        ];
    }

    /**
     * Get monthly trend data for charting
     *
     * @param string $elementUuid Element UUID
     * @param string $eventType Event type
     * @param int $months Number of months to include
     * @return array Monthly data points
     */
    public function getMonthlyTrend($elementUuid, $eventType, $months = 12)
    {
        $db = Yii::$app->db;

        return $db->createCommand("
            SELECT
                year,
                month,
                total_views as views,
                unique_sessions as sessions
            FROM {{%analytics_element_monthly}}
            WHERE element_uuid = :uuid
                AND event_type = :event
                AND (year * 100 + month) >= :startPeriod
            ORDER BY year ASC, month ASC
        ")
            ->bindValue(':uuid', $elementUuid)
            ->bindValue(':event', $eventType)
            ->bindValue(':startPeriod', (int)date('Ym', strtotime("-{$months} months")))
            ->queryAll();
    }

    /**
     * Calculate percentage change between two values
     *
     * @param float $oldValue Old value
     * @param float $newValue New value
     * @return float Percentage change
     */
    protected function calculatePercentageChange($oldValue, $newValue)
    {
        if ($oldValue == 0) {
            return $newValue > 0 ? 100 : 0;
        }

        return round((($newValue - $oldValue) / $oldValue) * 100, 2);
    }

    /**
     * Convert period string to date range
     *
     * @param string $period Period identifier
     * @return array [startDate, endDate] in Y-m-d format
     */
    protected function getPeriodDates($period)
    {
        $endDate = date('Y-m-d');

        $periods = [
            'today' => date('Y-m-d'),
            'yesterday' => date('Y-m-d', strtotime('-1 day')),
            '7days' => date('Y-m-d', strtotime('-7 days')),
            '30days' => date('Y-m-d', strtotime('-30 days')),
            'month' => date('Y-m-01'),
            'last_month' => date('Y-m-01', strtotime('first day of last month')),
            'year' => date('Y-01-01'),
            'all' => '2020-01-01', // Adjust based on when you started tracking
        ];

        $startDate = $periods[$period] ?? $periods['30days'];

        // For last_month, also adjust end date
        if ($period === 'last_month') {
            $endDate = date('Y-m-t', strtotime('last month'));
        }

        return [$startDate, $endDate];
    }

    /**
     * Restructure flat results into hierarchical array
     *
     * @param array $results Flat query results
     * @return array Restructured array
     */
    protected function restructureResults($results)
    {
        $structured = [];

        foreach ($results as $row) {
            $uuid = $row['uuid'];

            if (!isset($structured[$uuid])) {
                $structured[$uuid] = [
                    'uuid' => $uuid,
                    'type' => $row['ctype'],
                    'title' => $row['title'],
                    'stats' => [],
                    'total_views' => 0,
                ];
            }

            if ($row['event_type']) {
                $views = (int)$row['views'];
                $structured[$uuid]['stats'][$row['event_type']] = [
                    'views' => $views,
                    'sessions' => (int)$row['sessions'],
                    'users' => (int)$row['users'],
                ];
                $structured[$uuid]['total_views'] += $views;
            }
        }

        return array_values($structured);
    }
}