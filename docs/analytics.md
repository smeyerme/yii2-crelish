# Analytics System

Crelish CMS includes a comprehensive first-party analytics system for tracking page views, element interactions, and user sessions without relying on third-party services.

## Overview

The analytics system provides:

- **Page view tracking** with bot detection
- **Element view tracking** (list views, detail views, downloads)
- **Session management** with unique visitor identification
- **Pre-aggregated data** for fast dashboard queries
- **Conversion rate analysis**
- **Traffic pattern analysis**

## Architecture

```
Request → CrelishAnalyticsComponent → Raw Tables → Aggregation Jobs → Dashboard
                                           ↓
                                    analytics_page_views
                                    analytics_element_views
                                    analytics_sessions
                                           ↓
                                    analytics_page_daily
                                    analytics_page_monthly
                                    analytics_element_daily
                                    analytics_element_monthly
```

## Configuration

Enable analytics in your application configuration:

```php
'components' => [
    'analytics' => [
        'class' => 'giantbits\crelish\components\CrelishAnalyticsComponent',
        'enabled' => true,
        'excludeIps' => [
            '127.0.0.1',
            '::1',
            // Add your office IPs here
        ],
    ],
],
```

## Tracking Page Views

Page views are automatically tracked when content is rendered. You can also track manually:

```php
Yii::$app->analytics->trackPageView([
    'uuid' => $page->uuid,
    'ctype' => 'page',
]);
```

### What's Captured

- Page UUID and content type
- Full URL and referrer
- Session ID (generated per visitor)
- User ID (if logged in)
- User agent and IP address
- Bot detection flag
- Timestamp

## Tracking Element Views

Track when specific content elements are viewed:

```php
use giantbits\crelish\components\CrelishBaseHelper;

// Track element view with type
CrelishBaseHelper::trackElementView($elementUuid, 'article', 'list');
CrelishBaseHelper::trackElementView($elementUuid, 'article', 'detail');
CrelishBaseHelper::trackElementView($documentUuid, 'document', 'download');
```

### View Types

- `list`: Element appeared in a list/grid view
- `detail`: Element was viewed in detail/full page
- `download`: Element was downloaded
- Custom types as needed

## Bot Detection

The analytics system includes sophisticated bot detection:

### Detection Methods

1. **CrawlerDetect library**: Industry-standard bot detection
2. **User agent analysis**:
   - Empty or very short user agents (< 30 chars)
   - Email addresses in user agent
   - Old browser versions (Chrome < 100, Firefox < 100, IE 6-9)
   - Incomplete Chrome user agents (missing Safari/537.36)
   - Spoofed Chrome 100.0.x.x patterns
   - Malformed Mozilla strings

### Checking Bot Status

```php
// Check current request
$isBot = Yii::$app->analytics->isBot();

// Check specific user agent
$isBot = Yii::$app->analytics->checkIsBot($userAgent);

// Get the bot detector instance
$detector = Yii::$app->analytics->getBotDetector();
```

### Exclusions

The following are automatically excluded from tracking:

- Static file requests (.css, .js, images, fonts, etc.)
- Security scan patterns (/.env, /.git, /robots.txt, etc.)
- 404 pages
- Configured IP addresses

## Aggregated Data

For performance, raw data is aggregated into daily and monthly tables:

### Daily Aggregates

```sql
-- analytics_page_daily
date, page_uuid, page_url, total_views, unique_sessions, unique_users

-- analytics_element_daily
date, element_uuid, element_type, event_type, page_uuid,
total_views, unique_sessions, unique_users
```

### Monthly Aggregates

```sql
-- analytics_page_monthly
year, month, page_uuid, page_url, total_views, unique_sessions, unique_users

-- analytics_element_monthly
year, month, element_uuid, element_type, event_type,
total_views, unique_sessions, unique_users
```

### Running Aggregation

Set up a cron job to aggregate data:

```bash
# Daily aggregation (run after midnight)
0 1 * * * /path/to/yii crelish/analytics/aggregate-daily

# Monthly aggregation (run on 1st of month)
0 2 1 * * /path/to/yii crelish/analytics/aggregate-monthly
```

## Dashboard API

The `AnalyticsAggregatedController` provides JSON endpoints for building dashboards:

### Overview Statistics

```
GET /crelish/analytics-aggregated/overview-stats?period=month
```

Returns:
```json
{
  "pageStats": {
    "total_views": 15420,
    "total_sessions": 3250,
    "total_users": 2890,
    "unique_pages": 45
  },
  "elementStats": {
    "total_views": 28500,
    "total_sessions": 3100,
    "unique_elements": 120
  },
  "eventTypeStats": [
    {"event_type": "list", "total_views": 18000},
    {"event_type": "detail", "total_views": 10500}
  ]
}
```

### Page Views Trend

```
GET /crelish/analytics-aggregated/page-views-trend?period=month&granularity=daily
```

Returns daily/monthly time series data.

### Top Pages

```
GET /crelish/analytics-aggregated/top-pages?period=month&limit=10
```

### Top Elements

```
GET /crelish/analytics-aggregated/top-elements?period=month&limit=10&event_type=detail
```

### Element Type Distribution

```
GET /crelish/analytics-aggregated/element-type-distribution?period=month
```

### Conversion Rates

Analyzes list view to detail view conversion:

```
GET /crelish/analytics-aggregated/conversion-rates?period=month&limit=20
```

Returns:
```json
[
  {
    "element_uuid": "...",
    "element_type": "article",
    "title": "Article Title",
    "list_views": 500,
    "detail_views": 75,
    "conversion_rate": 15.0
  }
]
```

### Day of Week Patterns

```
GET /crelish/analytics-aggregated/day-of-week-patterns?period=month
```

### Period Comparison

```
GET /crelish/analytics-aggregated/compare-periods?period1=month&period2=previous_month
```

### Available Periods

- `today`: Current day
- `yesterday`: Previous day
- `week`: Last 7 days
- `month`: Last 30 days
- `previous_month`: 31-60 days ago
- `quarter`: Last 90 days
- `year`: Last 365 days
- `all`: All time

## Export

Export aggregated data to CSV:

```
GET /crelish/analytics-aggregated/export?period=month&type=pages
GET /crelish/analytics-aggregated/export?period=month&type=elements
```

## Using Analytics Data

### Get Statistics Programmatically

```php
$analytics = Yii::$app->analytics;

// Page view stats
$stats = $analytics->getPageViewStats('month', true, false);
// Parameters: period, excludeBots, uniqueVisitors

// Top pages
$topPages = $analytics->getTopPages('month', 10, true, true);
// Parameters: period, limit, excludeBots, uniqueVisitors

// Top elements
$topElements = $analytics->getTopElements('month', 10, 'detail');
// Parameters: period, limit, type
```

### In Twig Templates

```twig
{# Track element view #}
{{ chelper.trackElementView(article.uuid, 'article', 'detail') }}

{# Click tracking URL for links #}
<a href="{{ article.url }}"
   data-track-click="{{ chelper.getClickTrackingUrl(article.uuid, 'article') }}">
   Read More
</a>
```

## Database Tables

### Raw Tables

```sql
-- analytics_sessions
session_id, user_id, ip_address, user_agent, is_bot,
first_page_uuid, first_url, total_pages, created_at

-- analytics_page_views
id, page_uuid, page_type, url, referer, session_id, user_id,
user_agent, ip_address, is_bot, created_at

-- analytics_element_views
id, element_uuid, element_type, page_uuid, session_id, user_id,
type, created_at
```

### Aggregated Tables

See "Aggregated Data" section above.

## Privacy Considerations

1. **No third-party tracking**: All data stays on your server
2. **IP anonymization**: Consider hashing IPs before storage
3. **Session-based**: No persistent cookies required
4. **Bot filtering**: Excludes automated traffic
5. **Data retention**: Implement cleanup jobs for old raw data

### GDPR Compliance

```php
// Disable tracking for users who opt out
if (Yii::$app->session->get('analytics_opt_out')) {
    Yii::$app->analytics->enabled = false;
}
```

## Best Practices

1. **Use aggregated data** for dashboards - much faster than raw queries
2. **Filter bots** for accurate user metrics
3. **Track meaningful events** - don't over-track
4. **Set up aggregation cron jobs** early
5. **Monitor table sizes** and implement data retention policies

## Related Documentation

- [Click Tracking](./click-tracking.md) - Detailed click tracking setup
- [API Documentation](./API.md) - REST API reference
- [Twig Reference](./twig-reference.md) - Template helpers
