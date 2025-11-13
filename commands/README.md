Okay# Crelish Analytics Console Commands

This directory contains console controllers for managing analytics data, including bot detection and data aggregation.

## Table of Contents

- [Bot Detection Controller](#bot-detection-controller)
- [Analytics Aggregation Controller](#analytics-aggregation-controller)
- [Recommended Workflow](#recommended-workflow)
- [Cron Job Setup](#cron-job-setup)

---

## Bot Detection Controller

**File:** `BotDetectionController.php`
**Namespace:** `giantbits\crelish\commands`

### Overview

The Bot Detection Controller provides a comprehensive multi-layered system for identifying and removing bot traffic from analytics data. It uses multiple detection methods including user agent analysis, volume anomalies, timing patterns, and crawling behavior.

### Commands

#### Main Detection Command

```bash
php yii crelish/bot-detection/index
```

Runs all bot detection methods in sequence:

1. **User Agent Detection** - Analyzes user agents using the CrawlerDetect library
2. **Volume Anomaly Detection** - Identifies unusually high request volumes
3. **Timing Pattern Detection** - Detects robotic timing patterns
4. **Crawling Pattern Detection** - Identifies systematic crawling behavior
5. **Status Synchronization** - Syncs bot status between tables
6. **Bot Traffic Deletion** - Removes all detected bot traffic from the database

#### Statistics Command

```bash
php yii crelish/bot-detection/stats
```

Displays current bot traffic statistics including:
- Total page views (bots vs humans)
- Total sessions (bots vs humans)
- Bot detection reasons breakdown (if `bot_reason` column exists)

### Command Options

| Option | Alias | Type | Default | Description |
|--------|-------|------|---------|-------------|
| `--batchSize` | `-b` | int | 1000 | Number of records to process in each batch |
| `--dryRun` | `-d` | bool | false | Run without making changes (preview mode) |

### Examples

```bash
# Run with default settings
php yii crelish/bot-detection/index

# Dry run to preview what would be detected
php yii crelish/bot-detection/index --dryRun

# Process in larger batches for better performance
php yii crelish/bot-detection/index --batchSize=5000

# View current statistics
php yii crelish/bot-detection/stats
```

### Detection Methods

#### 1. User Agent Based Detection

**What it does:**
- Uses the `Jaybizzle\CrawlerDetect\CrawlerDetect` library
- Checks for outdated browser versions
- Detects custom bot patterns (emails in user agent, incomplete Chrome UAs, CCleaner, old IE versions)

**Thresholds:**
- `min_chrome_version`: 100
- `min_firefox_version`: 100
- `min_safari_version`: 600

**Tables affected:**
- `analytics_page_views`
- `analytics_sessions`

#### 2. Volume Anomaly Detection

**What it does:**
- Identifies sessions with abnormally high request counts
- Detects systematic crawlers with high URL diversity

**Thresholds:**
- `session_requests_per_hour`: 500 requests
- `session_requests_per_day`: 2,000 requests
- `ip_requests_per_hour`: 1,000 requests
- `ip_requests_per_day`: 5,000 requests
- `url_diversity_threshold`: 0.95 (95% unique URLs)
- `min_requests_for_pattern_detection`: 50

**Detection reasons:**
- `high_volume_hourly`
- `high_volume_daily`
- `systematic_crawler`

#### 3. Timing Pattern Detection

**What it does:**
- Analyzes request intervals for robotic consistency
- Detects bots that make requests at unnaturally regular intervals

**Thresholds:**
- `min_human_interval_seconds`: 1 second
- `max_requests_per_minute`: 30
- `consistent_interval_threshold`: 2 (standard deviation)

**Detection criteria:**
- Average interval < 10 seconds
- Standard deviation < 2 (very consistent timing)
- Minimum 20 requests for analysis

**Detection reason:**
- `robotic_timing`

#### 4. Crawling Pattern Detection

**What it does:**
- Identifies sequential pagination crawling
- Detects systematic page-by-page navigation

**Thresholds:**
- `sequential_page_threshold`: 5 sequential pages

**URL patterns detected:**
- `page=N`
- `/page/N`
- `&p=N`

**Detection reason:**
- `sequential_crawler`

### Configuration

You can customize detection thresholds by creating a configuration file:

**Location:** `@app/config/bot-detection-thresholds.php`

**Example:**

```php
<?php
return [
    // Volume-based thresholds
    'session_requests_per_hour' => 300,
    'session_requests_per_day' => 1500,

    // Timing-based thresholds
    'consistent_interval_threshold' => 1.5,

    // Pattern-based thresholds
    'url_diversity_threshold' => 0.90,
    'sequential_page_threshold' => 10,

    // Browser version thresholds
    'min_chrome_version' => 90,
    'min_firefox_version': 90,
    'min_safari_version': 500,
];
```

### Database Schema Requirements

**Required tables:**
- `analytics_page_views` (must have `is_bot` column)
- `analytics_sessions` (must have `is_bot` column)

**Optional columns:**
- `analytics_sessions.bot_reason` (for tracking detection method)

### Process Flow

```
1. Load custom thresholds (if config file exists)
   ↓
2. Process page views with user agent detection
   ↓
3. Process sessions with user agent detection
   ↓
4. Detect volume anomalies
   ↓
5. Detect timing patterns
   ↓
6. Detect crawling patterns
   ↓
7. Sync bot status: page_views → sessions
   ↓
8. Delete all bot traffic from database
   ↓
9. Show summary statistics
```

### Output Example

```
Starting enhanced bot detection process...

[1/5] Processing user agent-based detection...
Processed 5000 page view records (found 234 bots)
Processed 2500 session records (found 178 bots)
Page views: 5000 records processed, 234 bots found (4.68%)
Sessions: 2500 records processed, 178 bots found (7.12%)

[2/5] Detecting high-volume anomalies...
Checking hourly request volumes...
Checking daily request volumes...
Checking URL diversity patterns...
Volume anomaly detection: found 45 bots

[3/5] Analyzing timing patterns...
  Bot pattern: session abc12345, avg interval 2.34s (stddev: 0.45)
Timing pattern detection: found 23 bots

[4/5] Detecting crawling patterns...
Checking for sequential pagination patterns...
Crawling pattern detection: found 12 bots

[5/5] Syncing bot status...
Updated 156 sessions based on page view bot status

[6/6] Deleting bot traffic from database...
Deleted 3456 bot page views
Deleted 412 bot sessions

==================================================
Bot Detection Summary
==================================================

Remaining (human traffic only):
Sessions:   2,088
Page Views: 1,544

Bot detection and cleanup completed successfully!
```

---

## Analytics Aggregation Controller

**File:** `AnalyticsAggregationController.php`
**Namespace:** `giantbits\crelish\commands`

### Overview

The Analytics Aggregation Controller manages the aggregation of raw analytics data into daily and monthly summaries. This reduces storage requirements while maintaining detailed element-level statistics for partners. It works with both element views and page views.

### Commands

#### 1. Daily Aggregation

```bash
php yii crelish/analytics-aggregation/daily [date]
```

Aggregates data for a specific date (defaults to yesterday).

**What it does:**
- Aggregates element views by date, element UUID, element type, page UUID, and event type
- Aggregates page views by date, page UUID, and URL
- Stores results in `analytics_element_daily` and `analytics_page_daily` tables
- Only processes non-bot traffic (`is_bot = 0`)

**Parameters:**
- `date` (optional): Date in `Y-m-d` format (default: yesterday)

**Examples:**
```bash
# Aggregate yesterday's data
php yii crelish/analytics-aggregation/daily

# Aggregate specific date
php yii crelish/analytics-aggregation/daily 2025-01-15

# Dry run to preview
php yii crelish/analytics-aggregation/daily --dryRun
```

#### 2. Monthly Aggregation

```bash
php yii crelish/analytics-aggregation/monthly [year-month]
```

Aggregates daily data into monthly summaries.

**What it does:**
- Aggregates from daily tables into monthly tables
- Sums up total views, unique sessions, and unique users
- Stores results in `analytics_element_monthly` and `analytics_page_monthly` tables

**Parameters:**
- `yearMonth` (optional): Period in `Y-m` format (default: last month)

**Examples:**
```bash
# Aggregate last month
php yii crelish/analytics-aggregation/monthly

# Aggregate specific month
php yii crelish/analytics-aggregation/monthly 2025-01

# Dry run
php yii crelish/analytics-aggregation/monthly --dryRun
```

#### 3. Partner Statistics Cache

```bash
php yii crelish/analytics-aggregation/partner-stats [partnerId]
```

Builds a statistics cache for partner dashboards.

**What it does:**
- Generates pre-calculated statistics for each partner's elements
- Creates statistics for multiple time periods (day, week, month, year)
- Tracks different event types (list, detail, click, download)
- Stores results in `analytics_partner_stats` table

**Parameters:**
- `partnerId` (optional): Specific partner ID (default: all partners)

**Examples:**
```bash
# Build cache for all partners
php yii crelish/analytics-aggregation/partner-stats

# Build cache for specific partner
php yii crelish/analytics-aggregation/partner-stats 123

# Verbose output
php yii crelish/analytics-aggregation/partner-stats --verbose
```

#### 4. Cleanup Old Data

```bash
php yii crelish/analytics-aggregation/cleanup
```

Deletes old raw analytics data after aggregation.

**What it does:**
- Deletes raw data older than retention period
- Verifies aggregated data exists before deletion
- Optimizes tables after cleanup
- Only deletes human traffic (bot traffic should already be removed)

**Safety features:**
- Checks for aggregated data before deletion
- Requires confirmation before proceeding
- Can be tested with `--dryRun`

**Examples:**
```bash
# Cleanup with default retention (30 days)
php yii crelish/analytics-aggregation/cleanup

# Cleanup with custom retention period
php yii crelish/analytics-aggregation/cleanup --retentionDays=60

# Preview what would be deleted
php yii crelish/analytics-aggregation/cleanup --dryRun
```

#### 5. Backfill Aggregation

```bash
php yii crelish/analytics-aggregation/backfill [days]
```

Backfills aggregation for past dates.

**What it does:**
- Runs daily aggregation for multiple past days
- Useful for initial setup or fixing gaps

**Parameters:**
- `days` (optional): Number of days to backfill (default: 30)

**Examples:**
```bash
# Backfill last 30 days
php yii crelish/analytics-aggregation/backfill

# Backfill last 90 days
php yii crelish/analytics-aggregation/backfill 90
```

#### 6. Statistics

```bash
php yii crelish/analytics-aggregation/stats
```

Shows aggregation statistics and date ranges.

**Displays:**
- Raw data counts (element views, page views)
- Aggregated data counts (daily, monthly, partner cache)
- Date ranges for raw and aggregated data

### Command Options

| Option | Alias | Type | Default | Description |
|--------|-------|------|---------|-------------|
| `--dryRun` | `-d` | bool | false | Preview changes without executing |
| `--retentionDays` | `-r` | int | 30 | Days to keep raw data before cleanup |
| `--verbose` | `-v` | bool | false | Show detailed output |

### Database Schema Requirements

**Raw data tables:**
- `analytics_element_views` (session_id, element_uuid, element_type, page_uuid, type, user_id, created_at)
- `analytics_page_views` (session_id, page_uuid, url, user_id, is_bot, created_at)
- `analytics_sessions` (session_id, is_bot)

**Aggregated tables:**
- `analytics_element_daily` (date, element_uuid, element_type, page_uuid, event_type, total_views, unique_sessions, unique_users)
- `analytics_element_monthly` (year, month, element_uuid, element_type, event_type, total_views, unique_sessions, unique_users)
- `analytics_page_daily` (date, page_uuid, page_url, total_views, unique_sessions, unique_users)
- `analytics_page_monthly` (year, month, page_uuid, page_url, total_views, unique_sessions, unique_users)
- `analytics_partner_stats` (partner_id, element_uuid, element_type, event_type, period_type, period_start, total_views, unique_sessions, unique_users)

**Element reference table:**
- `crelish_elements` (uuid, owner_id, ctype)

### Aggregation Flow

```
Raw Analytics Data
        ↓
    [Daily Aggregation]
        ↓
analytics_element_daily (element-level)
analytics_page_daily (page-level)
        ↓
    [Monthly Aggregation]
        ↓
analytics_element_monthly
analytics_page_monthly
        ↓
    [Partner Stats Cache]
        ↓
analytics_partner_stats
(Pre-calculated for quick dashboard queries)
```

### Data Retention Strategy

The system implements a three-tier data retention strategy:

1. **Raw Data** (30 days default)
   - Full granular event data
   - Kept for detailed analysis and troubleshooting
   - Deleted after retention period via cleanup command

2. **Daily Aggregates** (Permanent)
   - Day-level summaries
   - Maintained indefinitely for historical analysis
   - Used to generate monthly aggregates

3. **Monthly Aggregates** (Permanent)
   - Month-level summaries
   - Maintained indefinitely for long-term trends
   - Generated from daily aggregates

### Output Examples

#### Daily Aggregation Output

```
============================================================
Daily Analytics Aggregation
============================================================
Target date: 2025-01-15

Found 45678 element view records and 12345 page view records
✓ Aggregated 1234 element view records
✓ Aggregated 567 page view records

Daily aggregation completed successfully
```

#### Cleanup Output

```
============================================================
Analytics Data Cleanup
============================================================
Retention period: 30 days

Cutoff date: 2024-12-15 00:00:00

Records to delete:
  Element views: 1,234,567
  Page views: 456,789

Delete 1,691,356 records? (yes|no) [no]:yes
✓ Deleted 1,234,567 element view records
✓ Deleted 456,789 page view records

Optimizing tables...
✓ Tables optimized

Cleanup completed successfully
```

---

## Recommended Workflow

### Initial Setup

1. **Configure bot detection thresholds** (optional)
   ```bash
   # Create config file
   cp config/bot-detection-thresholds.example.php config/bot-detection-thresholds.php
   # Edit thresholds as needed
   ```

2. **Run bot detection on existing data**
   ```bash
   # Test first
   php yii crelish/bot-detection/index --dryRun

   # Run actual detection
   php yii crelish/bot-detection/index
   ```

3. **Backfill aggregation for historical data**
   ```bash
   # Aggregate last 30 days
   php yii crelish/analytics-aggregation/backfill 30
   ```

4. **Build partner statistics cache**
   ```bash
   php yii crelish/analytics-aggregation/partner-stats
   ```

### Daily Operations

Run these commands via cron (see Cron Job Setup below):

1. **Bot detection** (recommended: run before aggregation)
   ```bash
   php yii crelish/bot-detection/index
   ```

2. **Daily aggregation** (run after bot detection)
   ```bash
   php yii crelish/analytics-aggregation/daily
   ```

3. **Partner stats update** (run after daily aggregation)
   ```bash
   php yii crelish/analytics-aggregation/partner-stats
   ```

### Monthly Operations

1. **Monthly aggregation** (run on 1st of month)
   ```bash
   php yii crelish/analytics-aggregation/monthly
   ```

2. **Cleanup old data** (run after monthly aggregation)
   ```bash
   php yii crelish/analytics-aggregation/cleanup
   ```

### Monitoring

Check statistics regularly:

```bash
# Bot detection stats
php yii crelish/bot-detection/stats

# Aggregation stats
php yii crelish/analytics-aggregation/stats
```

---

## Cron Job Setup

### Recommended Cron Schedule

```cron
# Bot detection - run daily at 2 AM (before aggregation)
0 2 * * * cd /path/to/project && php yii crelish/bot-detection/index >> /var/log/crelish/bot-detection.log 2>&1

# Daily aggregation - run daily at 3 AM (after bot detection)
0 3 * * * cd /path/to/project && php yii crelish/analytics-aggregation/daily >> /var/log/crelish/aggregation-daily.log 2>&1

# Partner stats - run daily at 4 AM (after daily aggregation)
0 4 * * * cd /path/to/project && php yii crelish/analytics-aggregation/partner-stats >> /var/log/crelish/partner-stats.log 2>&1

# Monthly aggregation - run on 1st of month at 5 AM
0 5 1 * * cd /path/to/project && php yii crelish/analytics-aggregation/monthly >> /var/log/crelish/aggregation-monthly.log 2>&1

# Cleanup old data - run on 2nd of month at 6 AM (after monthly aggregation)
0 6 2 * * cd /path/to/project && php yii crelish/analytics-aggregation/cleanup --retentionDays=30 >> /var/log/crelish/cleanup.log 2>&1
```

### Alternative: Single Nightly Job

If you prefer a single comprehensive job:

```cron
# Comprehensive nightly analytics job at 2 AM
0 2 * * * cd /path/to/project && bash /path/to/scripts/analytics-nightly.sh >> /var/log/crelish/analytics.log 2>&1
```

Create `scripts/analytics-nightly.sh`:

```bash
#!/bin/bash
set -e

PROJECT_DIR="/path/to/project"
cd "$PROJECT_DIR"

echo "=== Starting nightly analytics job at $(date) ==="

# 1. Bot detection
echo "Running bot detection..."
php yii crelish/bot-detection/index

# 2. Daily aggregation
echo "Running daily aggregation..."
php yii crelish/analytics-aggregation/daily

# 3. Partner stats
echo "Updating partner statistics..."
php yii crelish/analytics-aggregation/partner-stats

# 4. Monthly aggregation (on 1st of month)
if [ $(date +%d) -eq 1 ]; then
    echo "Running monthly aggregation..."
    php yii crelish/analytics-aggregation/monthly
fi

# 5. Cleanup (on 2nd of month)
if [ $(date +%d) -eq 2 ]; then
    echo "Running cleanup..."
    php yii crelish/analytics-aggregation/cleanup --retentionDays=30
fi

echo "=== Nightly analytics job completed at $(date) ==="
```

Make it executable:
```bash
chmod +x scripts/analytics-nightly.sh
```

---

## Troubleshooting

### Bot Detection Issues

**Problem:** Too many false positives

**Solution:** Adjust thresholds in `config/bot-detection-thresholds.php`:
```php
return [
    'session_requests_per_hour' => 800,  // Increase thresholds
    'url_diversity_threshold' => 0.98,   // Require higher diversity
];
```

**Problem:** Bot traffic not deleted

**Solution:** Check that tables have `is_bot` column and run detection without `--dryRun`

### Aggregation Issues

**Problem:** "No daily aggregates found" when running monthly aggregation

**Solution:** Run daily aggregation first or backfill missing days:
```bash
php yii crelish/analytics-aggregation/backfill 30
```

**Problem:** Cleanup refuses to delete data

**Solution:** Ensure aggregated data exists:
```bash
php yii crelish/analytics-aggregation/stats
php yii crelish/analytics-aggregation/daily
```

**Problem:** Partner stats not generating

**Solution:** Check if `crelish_elements` table exists with `owner_id` field

### Performance Issues

**Problem:** Bot detection is slow

**Solution:** Increase batch size:
```bash
php yii crelish/bot-detection/index --batchSize=5000
```

**Problem:** Aggregation takes too long

**Solution:** Run daily aggregation more frequently or add database indexes:
```sql
CREATE INDEX idx_created_at ON analytics_page_views(created_at);
CREATE INDEX idx_created_at ON analytics_element_views(created_at);
CREATE INDEX idx_session_bot ON analytics_sessions(session_id, is_bot);
```

---

## Best Practices

1. **Always run bot detection before aggregation**
   - Bot detection marks and deletes bot traffic
   - Aggregation should only process clean human traffic

2. **Test with dry run first**
   ```bash
   php yii crelish/bot-detection/index --dryRun
   php yii crelish/analytics-aggregation/cleanup --dryRun
   ```

3. **Monitor statistics regularly**
   - Check bot detection percentages
   - Verify aggregation is working
   - Watch for anomalies

4. **Keep raw data for at least 30 days**
   - Allows for reprocessing if needed
   - Helps troubleshoot issues

5. **Run aggregation consistently**
   - Daily aggregation should run every day
   - Monthly aggregation on the 1st
   - Don't skip days

6. **Backup before cleanup**
   - Raw data is deleted permanently
   - Consider database backups before running cleanup

7. **Use verbose mode for debugging**
   ```bash
   php yii crelish/analytics-aggregation/partner-stats --verbose
   ```

---

## Version History

- **v1.0.0** - Initial implementation of bot detection and analytics aggregation
  - Multi-layered bot detection system
  - Daily and monthly aggregation
  - Partner statistics cache
  - Automated cleanup

---

## Support

For issues or questions:
- Check the troubleshooting section above
- Review log files in `/var/log/crelish/`
- Run stats commands to diagnose issues
- Use `--dryRun` to test before making changes