# Analytics Dashboard Enhancement TODO

This document outlines future analytics enhancements that require modifications to the aggregation process and database schema. All enhancements maintain GDPR compliance by aggregating data before deleting personal information.

## Status

✅ **Completed** (Already in Dashboard):
1. List → Detail conversion rates
2. Day-of-week traffic patterns
3. Content freshness analysis (age vs performance)
4. Content type performance comparison

---

## Priority 1: Quick Wins (1-2 hours each)

### 1. Traffic Source Analysis (Anonymous)

**Value**: Understand where visitors come from to optimize marketing channels

**Implementation**:

1. **Add new aggregate table**:
```sql
CREATE TABLE `analytics_traffic_sources_daily` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `date` DATE NOT NULL,
  `source_type` VARCHAR(50) NOT NULL,
  `total_sessions` INT NOT NULL DEFAULT 0,
  `total_views` INT NOT NULL DEFAULT 0,
  INDEX `idx_date` (`date`),
  INDEX `idx_source` (`source_type`),
  UNIQUE KEY `unique_date_source` (`date`, `source_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

2. **Add to `AnalyticsAggregationController.php`** in `actionAggregateDaily()`:
```php
// Aggregate traffic sources (GDPR-safe: no personal data)
$this->aggregateTrafficSources($date);
```

3. **New method in controller**:
```php
private function aggregateTrafficSources($date)
{
  $db = Yii::$app->db;

  $sql = "
    INSERT INTO {{%analytics_traffic_sources_daily}}
    (date, source_type, total_sessions, total_views)
    SELECT
      :date as date,
      CASE
        WHEN referer IS NULL THEN 'direct'
        WHEN referer LIKE '%google.%' OR referer LIKE '%bing.%' OR referer LIKE '%yahoo.%' THEN 'search'
        WHEN referer LIKE '%facebook.%' OR referer LIKE '%twitter.%' OR referer LIKE '%linkedin.%' OR referer LIKE '%instagram.%' THEN 'social'
        WHEN referer LIKE :site_domain THEN 'internal'
        ELSE 'referral'
      END as source_type,
      COUNT(DISTINCT session_id) as total_sessions,
      COUNT(*) as total_views
    FROM {{%analytics_page_views}}
    WHERE DATE(created_at) = :date AND is_bot = 0
    GROUP BY source_type
    ON DUPLICATE KEY UPDATE
      total_sessions = VALUES(total_sessions),
      total_views = VALUES(total_views)
  ";

  $db->createCommand($sql, [
    ':date' => $date,
    ':site_domain' => '%' . Yii::$app->request->hostInfo . '%'
  ])->execute();
}
```

4. **Add dashboard endpoint** in `AnalyticsAggregatedController.php`:
```php
public function actionTrafficSources()
{
  Yii::$app->response->format = Response::FORMAT_JSON;

  $period = Yii::$app->request->get('period', 'month');
  list($startDate, $endDate) = $this->getPeriodDates($period);

  $sources = (new Query())
    ->select([
      'source_type',
      'total_sessions' => 'SUM(total_sessions)',
      'total_views' => 'SUM(total_views)',
    ])
    ->from('{{%analytics_traffic_sources_daily}}')
    ->where(['>=', 'date', $startDate])
    ->andWhere(['<=', 'date', $endDate])
    ->groupBy(['source_type'])
    ->orderBy(['total_sessions' => SORT_DESC])
    ->all();

  return $sources;
}
```

5. **Add to dashboard view** - pie chart showing traffic source distribution

---

### 2. Device Type Breakdown (Anonymous)

**Value**: Optimize design for mobile vs desktop based on actual usage

**Implementation**:

1. **Add aggregate table**:
```sql
CREATE TABLE `analytics_device_daily` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `date` DATE NOT NULL,
  `device_type` VARCHAR(20) NOT NULL,
  `total_sessions` INT NOT NULL DEFAULT 0,
  `total_views` INT NOT NULL DEFAULT 0,
  INDEX `idx_date` (`date`),
  UNIQUE KEY `unique_date_device` (`date`, `device_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

2. **Aggregate method**:
```php
private function aggregateDeviceTypes($date)
{
  $db = Yii::$app->db;

  $sql = "
    INSERT INTO {{%analytics_device_daily}}
    (date, device_type, total_sessions, total_views)
    SELECT
      :date as date,
      CASE
        WHEN user_agent LIKE '%Mobile%' AND user_agent NOT LIKE '%Tablet%' THEN 'mobile'
        WHEN user_agent LIKE '%Tablet%' OR user_agent LIKE '%iPad%' THEN 'tablet'
        ELSE 'desktop'
      END as device_type,
      COUNT(DISTINCT session_id) as total_sessions,
      COUNT(*) as total_views
    FROM {{%analytics_page_views}}
    WHERE DATE(created_at) = :date AND is_bot = 0
    GROUP BY device_type
    ON DUPLICATE KEY UPDATE
      total_sessions = VALUES(total_sessions),
      total_views = VALUES(total_views)
  ";

  $db->createCommand($sql, [':date' => $date])->execute();
}
```

3. **Dashboard integration** - bar chart comparing mobile/tablet/desktop usage

---

### 3. Engagement Metrics (Already Available in Raw Data)

**Value**: Measure content quality through user behavior

**Metrics to Add**:
- Bounce rate (single-page sessions %)
- Average pages per session
- Average session duration

**Implementation**:

1. **Add to existing aggregation** in `actionAggregateDaily()`:
```sql
INSERT INTO analytics_engagement_daily
(date, total_sessions, bounce_sessions, total_pages, avg_pages_per_session, bounce_rate)
SELECT
  DATE(created_at) as date,
  COUNT(*) as total_sessions,
  SUM(CASE WHEN total_pages = 1 THEN 1 ELSE 0 END) as bounce_sessions,
  SUM(total_pages) as total_pages,
  AVG(total_pages) as avg_pages_per_session,
  ROUND(SUM(CASE WHEN total_pages = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as bounce_rate
FROM analytics_sessions
WHERE DATE(created_at) = :date AND is_bot = 0
GROUP BY DATE(created_at)
```

2. **Add KPI cards** to dashboard showing these metrics

---

## Priority 2: Medium Effort (Half day each)

### 4. Hour-of-Day Heatmap

**Value**: See peak traffic times for scheduling content/maintenance

**Implementation**:

1. **Aggregate table**:
```sql
CREATE TABLE `analytics_hourly_pattern` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `hour` TINYINT NOT NULL,
  `day_of_week` TINYINT NOT NULL,
  `avg_sessions` DECIMAL(10,2) NOT NULL,
  `avg_views` DECIMAL(10,2) NOT NULL,
  `last_updated` DATETIME NOT NULL,
  UNIQUE KEY `unique_hour_day` (`hour`, `day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

2. **Rolling 30-day aggregate** - update weekly:
```php
public function actionAggregateHourlyPatterns()
{
  $db = Yii::$app->db;

  // Calculate averages from last 30 days
  $sql = "
    INSERT INTO {{%analytics_hourly_pattern}}
    (hour, day_of_week, avg_sessions, avg_views, last_updated)
    SELECT
      HOUR(created_at) as hour,
      DAYOFWEEK(created_at) as day_of_week,
      COUNT(DISTINCT session_id) / COUNT(DISTINCT DATE(created_at)) as avg_sessions,
      COUNT(*) / COUNT(DISTINCT DATE(created_at)) as avg_views,
      NOW() as last_updated
    FROM {{%analytics_page_views}}
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND is_bot = 0
    GROUP BY HOUR(created_at), DAYOFWEEK(created_at)
    ON DUPLICATE KEY UPDATE
      avg_sessions = VALUES(avg_sessions),
      avg_views = VALUES(avg_views),
      last_updated = VALUES(last_updated)
  ";

  $db->createCommand($sql)->execute();
}
```

3. **Dashboard** - heatmap visualization (7 days × 24 hours grid)

---

### 5. Entry & Exit Page Analysis

**Value**: Optimize landing pages, fix pages where users leave

**Implementation**:

1. **Already have data** in `analytics_sessions.first_page_uuid`

2. **Add aggregate for exit pages**:
```php
// In daily aggregation, track last page in session
INSERT INTO analytics_page_flow_daily
SELECT
  DATE(pv.created_at) as date,
  s.first_page_uuid as entry_page,
  pv.page_uuid as exit_page,
  COUNT(DISTINCT s.session_id) as sessions
FROM analytics_sessions s
INNER JOIN analytics_page_views pv ON pv.session_id = s.session_id
WHERE pv.id = (
  SELECT MAX(id) FROM analytics_page_views
  WHERE session_id = s.session_id AND is_bot = 0
)
GROUP BY DATE(pv.created_at), s.first_page_uuid, pv.page_uuid
```

3. **Dashboard sections**:
   - Top entry pages (most common first pages)
   - Top exit pages (most common last pages)
   - Bounce pages (entry = exit)

---

### 6. Content Performance Score

**Value**: Single metric to rank all content

**Implementation**:

Already possible with current data! Just add to dashboard:

```php
public function actionContentScores()
{
  $period = Yii::$app->request->get('period', 'month');
  list($startDate, $endDate) = $this->getPeriodDates($period);

  $scores = (new Query())
    ->select([
      'ed.element_uuid',
      'ed.element_type',
      'total_views' => 'SUM(ed.total_views)',
      'unique_sessions' => 'SUM(ed.unique_sessions)',
      'detail_views' => 'SUM(CASE WHEN ed.event_type = "detail" THEN ed.total_views ELSE 0 END)',
      'list_views' => 'SUM(CASE WHEN ed.event_type = "list" THEN ed.total_views ELSE 0 END)',
    ])
    ->from('{{%analytics_element_daily}} ed')
    ->where(['>=', 'ed.date', $startDate])
    ->andWhere(['<=', 'ed.date', $endDate])
    ->groupBy(['ed.element_uuid', 'ed.element_type'])
    ->all();

  // Calculate composite score
  foreach ($scores as &$score) {
    $score['performance_score'] =
      ($score['unique_sessions'] * 3) +
      ($score['detail_views'] * 5) +
      (($score['detail_views'] / max($score['list_views'], 1)) * 10);
  }

  // Sort by score
  usort($scores, fn($a, $b) => $b['performance_score'] <=> $a['performance_score']);

  return array_slice($scores, 0, 20);
}
```

---

## Priority 3: Bigger Projects (1-2 days each)

### 7. Conversion Funnels

**Value**: Track multi-step user journeys (list → detail → download)

Requires tracking funnel definitions and comparing step completion rates.

### 8. Real-Time Dashboard Widget

**Value**: See live traffic, catch viral content early

Query last 5 minutes of data from raw tables (before aggregation).

### 9. Alert System

**Value**: Get notified of traffic spikes, drops, or anomalies

Requires cron job to check thresholds and send notifications.

---

## Implementation Checklist

For each enhancement:

- [ ] Create database migration for new aggregate table(s)
- [ ] Add aggregation method to `AnalyticsAggregationController.php`
- [ ] Add API endpoint to `AnalyticsAggregatedController.php`
- [ ] Update dashboard view with new section
- [ ] Add JavaScript to fetch and render data
- [ ] Test with existing aggregated data
- [ ] Run backfill for historical data
- [ ] Update documentation

---

## Notes

**GDPR Compliance**: All aggregations only store counts and averages. No personal data (IP addresses, user agents, individual sessions) persists after aggregation.

**Performance**: Aggregated tables are indexed by date for fast queries. Monthly aggregates are used for year/all-time periods.

**Testing**: Test each enhancement with sample data before running on production. Use `actionBackfillDaily()` to regenerate aggregates after schema changes.