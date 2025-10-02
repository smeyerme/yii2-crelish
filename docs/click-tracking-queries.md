# Click Tracking Queries

Quick reference for querying click tracking data from the `analytics_element_views` table.

## Basic Queries

### Get All Clicks (Last 30 Days)

```php
use yii\db\Query;

$clicks = (new Query())
    ->select(['*'])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-30 days'))])
    ->orderBy(['created_at' => SORT_DESC])
    ->all();
```

### Get Top 10 Most Clicked Elements

```php
$topClicks = (new Query())
    ->select([
        'element_uuid',
        'element_type',
        'clicks' => 'COUNT(*)'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-30 days'))])
    ->groupBy(['element_uuid'])
    ->orderBy(['clicks' => SORT_DESC])
    ->limit(10)
    ->all();
```

### Using the Built-in Analytics Component

```php
// Get top clicked elements (last month)
$topClicks = Yii::$app->crelishAnalytics->getTopElements('month', 10, 'click');

// Get top clicked elements (last week)
$topClicks = Yii::$app->crelishAnalytics->getTopElements('week', 20, 'click');

// Get top clicked elements (last day)
$topClicks = Yii::$app->crelishAnalytics->getTopElements('day', 15, 'click');

// Get all element views (all time)
$topClicks = Yii::$app->crelishAnalytics->getTopElements('all', 50, 'click');
```

## Element Type Queries

### Get Clicks by Element Type

```php
$clicksByType = (new Query())
    ->select([
        'element_type',
        'clicks' => 'COUNT(*)'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-30 days'))])
    ->groupBy(['element_type'])
    ->orderBy(['clicks' => SORT_DESC])
    ->all();
```

### Get All Ad Clicks

```php
$adClicks = (new Query())
    ->select(['*'])
    ->from('analytics_element_views')
    ->where([
        'type' => 'click',
        'element_type' => 'ad'
    ])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-7 days'))])
    ->orderBy(['created_at' => SORT_DESC])
    ->all();
```

### Get CTA Button Clicks

```php
$ctaClicks = (new Query())
    ->select(['*'])
    ->from('analytics_element_views')
    ->where([
        'type' => 'click',
        'element_type' => 'cta'
    ])
    ->orderBy(['created_at' => SORT_DESC])
    ->limit(100)
    ->all();
```

## Time-Based Queries

### Get Clicks per Day (Last 30 Days)

```php
$clicksPerDay = (new Query())
    ->select([
        'date' => 'DATE(created_at)',
        'clicks' => 'COUNT(*)'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-30 days'))])
    ->groupBy(['DATE(created_at)'])
    ->orderBy(['date' => SORT_ASC])
    ->all();
```

### Get Clicks per Hour (Today)

```php
$clicksPerHour = (new Query())
    ->select([
        'hour' => 'HOUR(created_at)',
        'clicks' => 'COUNT(*)'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['>=', 'created_at', date('Y-m-d 00:00:00')])
    ->groupBy(['HOUR(created_at)'])
    ->orderBy(['hour' => SORT_ASC])
    ->all();
```

## User-Based Queries

### Get Clicks by User

```php
$clicksByUser = (new Query())
    ->select([
        'user_id',
        'clicks' => 'COUNT(*)'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['IS NOT', 'user_id', null])
    ->groupBy(['user_id'])
    ->orderBy(['clicks' => SORT_DESC])
    ->all();
```

### Get Anonymous vs Authenticated Clicks

```php
$clickBreakdown = (new Query())
    ->select([
        'user_type' => 'CASE WHEN user_id IS NULL THEN "anonymous" ELSE "authenticated" END',
        'clicks' => 'COUNT(*)'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->groupBy(['user_type'])
    ->all();
```

## Page-Based Queries

### Get Clicks by Page

```php
$clicksByPage = (new Query())
    ->select([
        'page_uuid',
        'clicks' => 'COUNT(*)'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->groupBy(['page_uuid'])
    ->orderBy(['clicks' => SORT_DESC])
    ->limit(20)
    ->all();
```

### Get Clicks for Specific Page

```php
$pageClicks = (new Query())
    ->select(['*'])
    ->from('analytics_element_views')
    ->where([
        'type' => 'click',
        'page_uuid' => 'your-page-uuid'
    ])
    ->orderBy(['created_at' => SORT_DESC])
    ->all();
```

## Specific Element Queries

### Get Click History for Single Element

```php
$elementClicks = (new Query())
    ->select(['*'])
    ->from('analytics_element_views')
    ->where([
        'type' => 'click',
        'element_uuid' => 'your-element-uuid'
    ])
    ->orderBy(['created_at' => SORT_DESC])
    ->all();
```

### Get Total Clicks for Element

```php
$totalClicks = (new Query())
    ->from('analytics_element_views')
    ->where([
        'type' => 'click',
        'element_uuid' => 'your-element-uuid'
    ])
    ->count();
```

### Get Unique Users Who Clicked Element

```php
$uniqueUsers = (new Query())
    ->select(['user_id'])
    ->from('analytics_element_views')
    ->where([
        'type' => 'click',
        'element_uuid' => 'your-element-uuid'
    ])
    ->andWhere(['IS NOT', 'user_id', null])
    ->distinct()
    ->count();
```

## Comparison Queries

### Compare Clicks vs Views for Same Element

```php
$comparison = (new Query())
    ->select([
        'element_uuid',
        'views' => 'SUM(CASE WHEN type IN ("list", "detail") THEN 1 ELSE 0 END)',
        'clicks' => 'SUM(CASE WHEN type = "click" THEN 1 ELSE 0 END)',
        'ctr' => 'ROUND(SUM(CASE WHEN type = "click" THEN 1 ELSE 0 END) / SUM(CASE WHEN type IN ("list", "detail") THEN 1 ELSE 0 END) * 100, 2)'
    ])
    ->from('analytics_element_views')
    ->where(['element_uuid' => 'your-element-uuid'])
    ->groupBy(['element_uuid'])
    ->one();
```

### Get Click-Through Rate (CTR) for All Elements

```php
$ctrData = (new Query())
    ->select([
        'element_uuid',
        'element_type',
        'impressions' => 'SUM(CASE WHEN type IN ("list", "detail") THEN 1 ELSE 0 END)',
        'clicks' => 'SUM(CASE WHEN type = "click" THEN 1 ELSE 0 END)',
        'ctr' => 'ROUND(SUM(CASE WHEN type = "click" THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN type IN ("list", "detail") THEN 1 ELSE 0 END), 0) * 100, 2)'
    ])
    ->from('analytics_element_views')
    ->groupBy(['element_uuid'])
    ->having(['>', 'impressions', 0])
    ->orderBy(['ctr' => SORT_DESC])
    ->all();
```

## Advanced Queries

### Get Click Funnel (Sequential Clicks by Session)

```php
$clickFunnel = (new Query())
    ->select([
        'session_id',
        'clicks' => 'GROUP_CONCAT(element_uuid ORDER BY created_at SEPARATOR " -> ")',
        'click_count' => 'COUNT(*)',
        'first_click' => 'MIN(created_at)',
        'last_click' => 'MAX(created_at)'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-7 days'))])
    ->groupBy(['session_id'])
    ->having(['>', 'click_count', 1])
    ->orderBy(['click_count' => SORT_DESC])
    ->all();
```

### Get Peak Click Hours

```php
$peakHours = (new Query())
    ->select([
        'hour' => 'HOUR(created_at)',
        'day_of_week' => 'DAYOFWEEK(created_at)',
        'clicks' => 'COUNT(*)'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-30 days'))])
    ->groupBy(['HOUR(created_at)', 'DAYOFWEEK(created_at)'])
    ->orderBy(['clicks' => SORT_DESC])
    ->limit(10)
    ->all();
```

### Get Elements with Declining Click Rate

```php
$decliningElements = (new Query())
    ->select([
        'element_uuid',
        'last_week' => 'SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END)',
        'previous_week' => 'SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END)',
        'change_pct' => 'ROUND((SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) - SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END)) / NULLIF(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) * 100, 2)'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-14 days'))])
    ->groupBy(['element_uuid'])
    ->having(['<', 'change_pct', 0])
    ->orderBy(['change_pct' => SORT_ASC])
    ->all();
```

## Export Queries

### Export Click Data to CSV

```php
use yii\db\Query;
use yii\helpers\ArrayHelper;

$clicks = (new Query())
    ->select([
        'element_uuid',
        'element_type',
        'page_uuid',
        'session_id',
        'user_id',
        'created_at'
    ])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-30 days'))])
    ->orderBy(['created_at' => SORT_DESC])
    ->all();

// Convert to CSV
$fp = fopen('clicks_export.csv', 'w');
if (!empty($clicks)) {
    fputcsv($fp, array_keys($clicks[0]));
    foreach ($clicks as $row) {
        fputcsv($fp, $row);
    }
}
fclose($fp);
```

## Dashboard Widgets

### Summary Stats for Dashboard

```php
$stats = [
    'total_clicks_today' => (new Query())
        ->from('analytics_element_views')
        ->where(['type' => 'click'])
        ->andWhere(['>=', 'created_at', date('Y-m-d 00:00:00')])
        ->count(),

    'total_clicks_week' => (new Query())
        ->from('analytics_element_views')
        ->where(['type' => 'click'])
        ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-7 days'))])
        ->count(),

    'total_clicks_month' => (new Query())
        ->from('analytics_element_views')
        ->where(['type' => 'click'])
        ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-30 days'))])
        ->count(),

    'unique_elements_clicked' => (new Query())
        ->select(['element_uuid'])
        ->from('analytics_element_views')
        ->where(['type' => 'click'])
        ->distinct()
        ->count(),

    'top_element_today' => (new Query())
        ->select(['element_uuid', 'clicks' => 'COUNT(*)'])
        ->from('analytics_element_views')
        ->where(['type' => 'click'])
        ->andWhere(['>=', 'created_at', date('Y-m-d 00:00:00')])
        ->groupBy(['element_uuid'])
        ->orderBy(['clicks' => SORT_DESC])
        ->limit(1)
        ->one(),
];
```

## Performance Optimization Tips

1. **Add Indexes** for frequently queried columns:
```sql
CREATE INDEX idx_type_created ON analytics_element_views(type, created_at);
CREATE INDEX idx_element_type ON analytics_element_views(element_uuid, type);
CREATE INDEX idx_page_type ON analytics_element_views(page_uuid, type);
```

2. **Use Aggregate Tables** for historical data (monthly summaries)

3. **Archive Old Data** to keep main table fast (move data older than 1 year)

4. **Use Query Caching** for dashboard widgets:
```php
$clicks = Yii::$app->db->cache(function ($db) {
    return (new Query())
        ->from('analytics_element_views')
        ->where(['type' => 'click'])
        ->count();
}, 300); // Cache for 5 minutes
```