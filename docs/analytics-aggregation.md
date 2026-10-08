# Analytics Data Aggregation

This document explains the analytics aggregation system that reduces database storage requirements while preserving granular element-level statistics for partners.

## Overview

The aggregation system:

- **Reduces storage by 90%+** by archiving raw data into daily and monthly summaries
- **Preserves all granularity** partners need (element UUID, event type, page context)
- **Maintains separation** between different event types (list, detail, click, download)
- **Enables fast queries** through pre-calculated aggregates and caching

## Architecture

### Data Flow

```
Raw Data (30 days) → Daily Aggregates (12 months) → Monthly Aggregates (forever)
                   ↓
              Partner Stats Cache (for dashboards)
```

### Tables

1. **analytics_element_views** (raw data)
   - Keeps 30 days by default
   - Full granularity with timestamps

2. **analytics_element_daily** (daily aggregates)
   - Keeps 12 months
   - Grouped by: date, element_uuid, element_type, event_type, page_uuid

3. **analytics_element_monthly** (monthly aggregates)
   - Keeps forever (small size)
   - Grouped by: year, month, element_uuid, element_type, event_type

4. **analytics_partner_stats** (cache)
   - Pre-calculated stats for common queries
   - Refreshed daily

5. **analytics_visits_daily** (visits)
   - One row per day, source, owner and event type
   - `source`: `pages` (from `analytics_page_views`) or `elements` (from `analytics_element_views`)
   - `owner_uuid`: `''` is the whole site; otherwise the owner of the element views. A session counts for an owner when it saw an element whose `page_uuid` is the owner or an element the owner owns (for jobs: the company). Ownership comes from the project's `config/analytics-element-types.php` (listed tables with a `company` column); a session matching both ways counts once
   - `event_type`: `''` is any event; otherwise `list`, `detail`, `click`, `download`, ...
   - Holds distinct sessions (`unique_sessions`) and distinct logged-in users (`unique_users`) of that day
   - Reports sum it over the days of a period, never across owners or event types (see [Visits](#visits))

## Setup

### 1. Run Migration

```bash
cd /path/to/your/app
php yii migrate --migrationPath=@vendor/giantbits/yii2-crelish/migrations
```

Look for: `m250102_120000_create_analytics_aggregation_tables`

Since crelish 0.25.0 there is also `m261008_120000_create_analytics_visits_daily`. Run the crelish migrations with:

```bash
php yii crelish-migrate/up
```

`dep deploy` runs only `yii migrate` (the application's migrations), so crelish migrations have to be run by hand after a deploy that updates crelish. Until the table exists, `daily` skips the visits part with a warning and the reports show "Besuche noch nicht erfasst".

### 2. Configure Component (Optional)

Add to your application config for easier access:

```php
'components' => [
    'partnerAnalytics' => [
        'class' => 'giantbits\crelish\components\PartnerAnalyticsService',
    ],
],
```

### 3. Set Up Cron Jobs

Add these to your crontab:

```bash
# Daily aggregation at 1 AM (aggregate yesterday's data: pages, elements, visits)
0 1 * * * /path/to/yii crelish/analytics-aggregation/daily

# Monthly aggregation on 1st at 2 AM (aggregate last month)
0 2 1 * * /path/to/yii crelish/analytics-aggregation/monthly

# Partner stats cache refresh at 3 AM (for fast dashboard queries)
0 3 * * * /path/to/yii crelish/analytics-aggregation/partner-stats

# Cleanup old raw data weekly on Sunday at 4 AM (keeps 30 whole days, verifies each day first)
0 4 * * 0 /path/to/yii crelish/analytics-aggregation/cleanup --retentionDays=30

# Bot detection daily at 2 AM (existing)
0 2 * * * /path/to/yii crelish/bot-detection/index
```

### 4. Initial Backfill

After setting up, backfill the raw data that is still there (never more days than the retention period, see [Backfill](#backfill)):

```bash
# Backfill the last 30 days
php yii crelish/analytics-aggregation/backfill 30

# Rollout of 0.25.0: fill only the new visit rows, for the 29 whole days the
# 0.24 cleanup left (it cut the 30th day in two)
php yii crelish/analytics-aggregation/backfill 29 --only=visits

# Then create monthly aggregates
php yii crelish/analytics-aggregation/monthly 2024-12
php yii crelish/analytics-aggregation/monthly 2024-11
# ... etc for each past month
```

## Command Line Usage

### Daily Aggregation

```bash
# Aggregate yesterday's data (default)
php yii crelish/analytics-aggregation/daily

# Aggregate specific date
php yii crelish/analytics-aggregation/daily 2025-01-15

# Dry run (see what would happen)
php yii crelish/analytics-aggregation/daily --dryRun
```

### Monthly Aggregation

```bash
# Aggregate last month (default)
php yii crelish/analytics-aggregation/monthly

# Aggregate specific month
php yii crelish/analytics-aggregation/monthly 2024-12

# Dry run
php yii crelish/analytics-aggregation/monthly --dryRun
```

### Partner Statistics Cache

```bash
# Build cache for all partners
php yii crelish/analytics-aggregation/partner-stats

# Build cache for specific partner
php yii crelish/analytics-aggregation/partner-stats 123

# Verbose output
php yii crelish/analytics-aggregation/partner-stats --verbose
```

### Cleanup Old Data

```bash
# Delete data older than 30 days (default)
php yii crelish/analytics-aggregation/cleanup

# Custom retention period
php yii crelish/analytics-aggregation/cleanup --retentionDays=60

# Show per day whether it passes or would be repaired, without changing anything
php yii crelish/analytics-aggregation/cleanup --dryRun

# Delete without checking the aggregates (explicit override)
php yii crelish/analytics-aggregation/cleanup --skipAggregationCheck=1
```

The cleanup works on whole days only: raw rows of a day are deleted only when the whole day is older than `retentionDays` (cutoff at midnight).

Before deleting, each day is checked against its raw data:

- page views: `analytics_page_daily` total views vs. raw page views
- element views: `analytics_element_daily` total views vs. raw element views
- site visits: the site rows of `analytics_visits_daily` (`pages`, `elements`) vs. distinct raw sessions

Bots are excluded on both sides. A day passes when every stored value is at least the raw value (bots detected late make the raw value smaller, which is accepted). A day with no reportable traffic passes.

In a dry run nothing is repaired: a short day is reported as "would repair" and counted as kept.

A day that is short is repaired: it is recomputed with `GREATEST(stored, recomputed)` for every count, so repair only raises numbers, and checked again. Days that pass, initially or after repair, are deleted. Days that are still short are kept with their raw data, listed on stderr, and the command exits with code 1. Each day is decided independently.

Orphaned element views and sessions (no aggregate counts them) are deleted as before. If a day is kept, orphaned sessions are only deleted if they were created before the start of the oldest kept day, so the QR-scan sessions of a kept day survive.

### Backfill

```bash
# Backfill last 30 days
php yii crelish/analytics-aggregation/backfill 30

# Show the days and parts without writing anything
php yii crelish/analytics-aggregation/backfill 30 --dryRun=1

# Only some parts: pages, elements, visits (comma list, default: all)
php yii crelish/analytics-aggregation/backfill 30 --only=visits
php yii crelish/analytics-aggregation/backfill 30 --only=pages,visits

# Alias for --only=pages (since 0.24.2)
php yii crelish/analytics-aggregation/backfill 30 --pagesOnly=1
```

`--only` works for `daily`, `monthly` and `backfill`. `monthly` ignores `visits` (monthly visits are the sum of the daily rows).

Do not backfill beyond the retention period. For a day whose raw data the cleanup has deleted, a normal run of `pages` or `elements` overwrites the stored aggregates with what is left, i.e. with lower numbers or nothing. Visits are protected: for every day before the retention period the visits part only raises numbers and never deletes a row, and a day without raw data is left untouched, so the visits of older days are kept as they are.

Do not recompute `elements` for days older than a few days either, and that includes runs without `--only`, whose default includes `elements` (and `pages`, which overwrite as well). The cleanup thins out orphaned element views, so recomputing them from raw data gives lower numbers than the correct ones stored. Use `--only=visits` for older days within the retention period.

`daily` takes an existing day written as `Y-m-d`; anything else (`2026-02-30`, `yesterday`) is a usage error (exit code 64).

### Statistics

```bash
# View aggregation statistics
php yii crelish/analytics-aggregation/stats
```

## PHP API Usage

### Basic Element Statistics

```php
// Get all stats for an element
$stats = Yii::$app->partnerAnalytics->getElementStats(
    $elementUuid,
    null,      // All event types
    '30days'   // Period
);

// Result:
// [
//     'list' => ['views' => 150, 'sessions' => 120, 'users' => 45],
//     'detail' => ['views' => 89, 'sessions' => 78, 'users' => 34],
//     'click' => ['views' => 23, 'sessions' => 21, 'users' => 15],
//     'download' => ['views' => 5, 'sessions' => 5, 'users' => 3],
// ]

// Get only click stats
$clickStats = Yii::$app->partnerAnalytics->getElementStats(
    $elementUuid,
    'click',
    '7days'
);
```

### Daily Breakdown

```php
// Get daily breakdown for charting
$dailyStats = Yii::$app->partnerAnalytics->getElementStatsByDay(
    $elementUuid,
    'detail',  // Event type
    '30days'
);

// Result:
// [
//     ['date' => '2025-01-01', 'views' => 15, 'sessions' => 12, 'users' => 5],
//     ['date' => '2025-01-02', 'views' => 23, 'sessions' => 18, 'users' => 8],
//     ...
// ]
```

### Partner Dashboard

```php
// Get overview for partner dashboard
$overview = Yii::$app->partnerAnalytics->getPartnerOverview(
    $partnerId,
    '30days'
);

// Result:
// [
//     'summary' => [
//         'total_elements' => 45,
//         'element_types' => 3,
//         'list_views' => 1250,
//         'detail_views' => 892,
//         'clicks' => 234,
//         'downloads' => 56,
//         'unique_sessions' => 456,
//     ],
//     'by_type' => [
//         ['ctype' => 'news', 'element_count' => 23, 'detail_views' => 567, 'clicks' => 123],
//         ['ctype' => 'job', 'element_count' => 15, 'detail_views' => 234, 'clicks' => 89],
//         ['ctype' => 'product', 'element_count' => 7, 'detail_views' => 91, 'clicks' => 22],
//     ]
// ]
```

### Partner Elements List

```php
// Get all elements for a partner with stats
$elements = Yii::$app->partnerAnalytics->getPartnerElements(
    $partnerId,
    'news',    // Filter by type (or null for all)
    '30days'
);

// Result:
// [
//     [
//         'uuid' => 'uuid-1',
//         'type' => 'news',
//         'title' => 'News Article Title',
//         'total_views' => 234,
//         'stats' => [
//             'list' => ['views' => 123, 'sessions' => 98, 'users' => 45],
//             'detail' => ['views' => 89, 'sessions' => 76, 'users' => 34],
//             'click' => ['views' => 22, 'sessions' => 20, 'users' => 12],
//         ],
//     ],
//     ...
// ]
```

### Paginated Results

```php
// Get paginated partner elements
$result = Yii::$app->partnerAnalytics->getPartnerElementsPaginated(
    $partnerId,
    'news',
    '30days',
    $page = 1,
    $pageSize = 20
);

// Result:
// [
//     'items' => [...],      // Array of elements
//     'total' => 145,        // Total count
//     'pages' => 8,          // Total pages
//     'page' => 1,           // Current page
//     'pageSize' => 20,      // Items per page
// ]
```

### Trending Elements

```php
// Get top 10 trending elements
$trending = Yii::$app->partnerAnalytics->getTrendingElements(
    $partnerId,
    10,        // Limit
    '7days'    // Period
);

// Result:
// [
//     [
//         'uuid' => 'uuid-1',
//         'ctype' => 'news',
//         'title' => 'Most Popular Article',
//         'list_views' => 567,
//         'detail_views' => 234,
//         'clicks' => 45,
//         'downloads' => 12,
//         'total_views' => 858,
//     ],
//     ...
// ]
```

### Performance Comparison

```php
// Compare current period vs previous
$comparison = Yii::$app->partnerAnalytics->comparePerformance(
    $elementUuid,
    'detail',
    '7days',
    '7days'
);

// Result:
// [
//     'current' => [
//         'views' => 234,
//         'sessions' => 189,
//         'start' => '2025-01-25',
//         'end' => '2025-02-01',
//     ],
//     'previous' => [
//         'views' => 189,
//         'sessions' => 156,
//         'start' => '2025-01-18',
//         'end' => '2025-01-24',
//     ],
//     'change' => [
//         'views' => 23.81,      // +23.81%
//         'sessions' => 21.15,   // +21.15%
//     ],
// ]
```

### Monthly Trends

```php
// Get 12-month trend for charting
$trend = Yii::$app->partnerAnalytics->getMonthlyTrend(
    $elementUuid,
    'detail',
    12  // months
);

// Result:
// [
//     ['year' => 2024, 'month' => 3, 'views' => 234, 'sessions' => 189],
//     ['year' => 2024, 'month' => 4, 'views' => 267, 'sessions' => 212],
//     ...
// ]
```

## Period Options

Available period strings:

- `'today'` - Today only
- `'yesterday'` - Yesterday only
- `'7days'` - Last 7 days
- `'30days'` - Last 30 days
- `'month'` - Current month to date
- `'last_month'` - Previous complete month
- `'year'` - Current year to date
- `'all'` - All time (uses aggregated data)

## Visits

A visit is a visitor counted once per day, however many pages or elements they saw that day. The figure for a period is the sum of the daily visits. Distinct people across days are not measurable: most visitors carry no cookie and raw data is kept for 30 days only.

`unique_sessions` and `unique_users` of the page, element and monthly aggregates are distinct per row (one page, or one element with event type and page, on one day). Summing them across pages, elements or event types counts a visitor who saw ten of a company's jobs ten times. Reports therefore read `analytics_visits_daily` instead: the site row (`pages`, owner `''`) for the admin overview, the owner's row (`elements`, event type `''`) for company reports, and the `detail` rows for one element. Rows are only summed over days, never across owners or event types.

Visit rows exist from the first day they were computed (at rollout: the 29-day backfill). That first recorded day may be partial: the 0.24 cleanup deleted by timestamp, so its last run may have cut that day's early hours. If a period starts before the first recorded day, the figure covers only the days with data and the UI says so ("Besuche erfasst ab ..."); a period that ends before it has no figure ("–"). The old summed value is not shown as a fallback.

Company ownership (which company owns an element) is resolved when a day is aggregated and frozen in its visit rows; a later change of an element's company does not move past visits. The view-based figures (page and element aggregates) resolve ownership when they are read.

Known limitations: partner statistics (`PartnerAnalyticsService`) and short-link statistics still use the summed figures. Cookieless visitors get a new session per request, so for them a visit is closer to a page request than to a browsing session. For a day the cleanup kept, a QR-scan session created before that day's midnight can still be deleted.

## Controller/Action Usage

### Display Partner Dashboard

```php
public function actionDashboard()
{
    $partnerId = Yii::$app->user->identity->partner_id;

    $overview = Yii::$app->partnerAnalytics->getPartnerOverview($partnerId, '30days');
    $trending = Yii::$app->partnerAnalytics->getTrendingElements($partnerId, 5, '7days');

    return $this->render('dashboard', [
        'overview' => $overview,
        'trending' => $trending,
    ]);
}
```

### Display Element Statistics

```php
public function actionElementStats($uuid)
{
    // Verify user owns this element
    $element = CrelishElements::findOne(['uuid' => $uuid]);
    if ($element->owner_id !== Yii::$app->user->identity->partner_id) {
        throw new ForbiddenHttpException;
    }

    $stats = Yii::$app->partnerAnalytics->getElementStats($uuid, null, '30days');
    $daily = Yii::$app->partnerAnalytics->getElementStatsByDay($uuid, 'detail', '30days');
    $comparison = Yii::$app->partnerAnalytics->comparePerformance($uuid, 'detail', '7days');

    return $this->render('element-stats', [
        'element' => $element,
        'stats' => $stats,
        'dailyStats' => $daily,
        'comparison' => $comparison,
    ]);
}
```

### Export Statistics (CSV)

```php
public function actionExportStats($period = '30days')
{
    $partnerId = Yii::$app->user->identity->partner_id;
    $elements = Yii::$app->partnerAnalytics->getPartnerElements($partnerId, null, $period);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stats-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['UUID', 'Type', 'Title', 'List Views', 'Detail Views', 'Clicks', 'Downloads']);

    foreach ($elements as $element) {
        fputcsv($output, [
            $element['uuid'],
            $element['type'],
            $element['title'],
            $element['stats']['list']['views'] ?? 0,
            $element['stats']['detail']['views'] ?? 0,
            $element['stats']['click']['views'] ?? 0,
            $element['stats']['download']['views'] ?? 0,
        ]);
    }

    fclose($output);
    Yii::$app->end();
}
```

## Storage Savings

### Before Aggregation
- 1 year of raw data: ~100 GB
- 2 years: ~200 GB
- 3 years: ~300 GB

### After Aggregation
- Raw data (30 days): ~10 GB
- Daily aggregates (365 days): ~500 MB
- Monthly aggregates (all time): ~50 MB
- **Total: ~10.5 GB** (95% reduction)

### Growth Over Time
- Year 1: 10.5 GB
- Year 2: 11.0 GB
- Year 3: 11.5 GB
- Storage grows ~500 MB/year instead of ~100 GB/year

## Best Practices

1. **Run aggregation daily** - Don't let raw data accumulate
2. **Monitor aggregation status** - Use `php yii crelish/analytics-aggregation/stats`
3. **Test before cleanup** - Always use `--dryRun` first
4. **Adjust retention** - 30 days is recommended, but adjust based on needs
5. **Archive before major cleanup** - Export data you might need later
6. **Monitor storage** - Check database size regularly
7. **Index optimization** - Run `OPTIMIZE TABLE` monthly

## Troubleshooting

### Aggregation Not Running

```bash
# Check if data exists
php yii crelish/analytics-aggregation/stats

# Try manual aggregation
php yii crelish/analytics-aggregation/daily --verbose

# Check for errors in logs
tail -f /path/to/app/runtime/logs/app.log
```

### Missing Statistics

```bash
# Verify aggregates exist
mysql> SELECT COUNT(*), MIN(date), MAX(date) FROM analytics_element_daily;

# Backfill if needed (only days within the retention period, see Backfill)
php yii crelish/analytics-aggregation/backfill 7
```

### Slow Queries

```sql
-- Check indexes
SHOW INDEXES FROM analytics_element_daily;

-- Analyze tables
ANALYZE TABLE analytics_element_daily;
ANALYZE TABLE analytics_element_monthly;
```

### Database Still Growing

```bash
# Check raw data size
mysql> SELECT
    COUNT(*) as records,
    MIN(created_at) as oldest,
    MAX(created_at) as newest
FROM analytics_element_views WHERE is_bot = 0;

# Run cleanup if aggregation is done
php yii crelish/analytics-aggregation/cleanup
```

## Migration from Raw Data Queries

If you have existing code querying raw data:

### Before (Raw Data)
```php
$stats = (new Query())
    ->select(['COUNT(*) as views'])
    ->from('analytics_element_views')
    ->where(['element_uuid' => $uuid])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-30 days'))])
    ->scalar();
```

### After (Aggregated)
```php
$stats = Yii::$app->partnerAnalytics->getElementStats($uuid, 'detail', '30days');
$views = $stats['detail']['views'] ?? 0;
```

## Future Enhancements

Potential improvements:

1. **Real-time aggregation** - Use queue for instant updates
2. **Partitioning** - Partition tables by date for faster queries
3. **Data warehouse** - Export to separate analytics database
4. **Compression** - Archive older data with higher compression
5. **API endpoints** - REST API for partner statistics
6. **Dashboards** - Pre-built partner dashboard widgets

## Questions?

For issues or questions:
- Check logs: `/path/to/app/runtime/logs/app.log`
- Run diagnostics: `php yii crelish/analytics-aggregation/stats`
- Test queries: Use `--verbose` flag for detailed output