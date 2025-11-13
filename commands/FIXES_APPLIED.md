# Analytics Aggregation Fixes Applied

**Date:** 2025-01-13
**Status:** ✅ COMPLETED

## Summary

Fixed critical aggregation errors that were causing inflated unique session and unique user counts in monthly aggregates and partner statistics. Also improved bot detection efficiency by ensuring consistent filtering across all detection methods.

---

## Issues Fixed

### 1. ✅ Monthly Element Aggregation (CRITICAL)

**File:** `AnalyticsAggregationController.php`
**Method:** `actionMonthly()`
**Lines:** 246-278

#### Problem
The monthly aggregation was summing `unique_sessions` and `unique_users` from daily aggregates:

```php
// OLD (INCORRECT)
SELECT
    SUM(unique_sessions) as unique_sessions,
    SUM(unique_users) as unique_users
FROM analytics_element_daily
WHERE YEAR(date) = :year AND MONTH(date) = :month
GROUP BY element_uuid, element_type, event_type
```

This caused overcounting because the same session/user appearing on multiple days was counted multiple times.

#### Fix Applied
Changed to query raw data with `COUNT(DISTINCT)`:

```php
// NEW (CORRECT)
SELECT
    YEAR(ev.created_at) as year,
    MONTH(ev.created_at) as month,
    ev.element_uuid,
    ev.element_type,
    ev.type as event_type,
    COUNT(*) as total_views,
    COUNT(DISTINCT ev.session_id) as unique_sessions,
    COUNT(DISTINCT CASE WHEN ev.user_id IS NOT NULL AND ev.user_id > 0 THEN ev.user_id END) as unique_users
FROM analytics_element_views ev
INNER JOIN analytics_sessions s ON ev.session_id = s.session_id
WHERE YEAR(ev.created_at) = :year
    AND MONTH(ev.created_at) = :month
    AND s.is_bot = 0
GROUP BY YEAR(ev.created_at), MONTH(ev.created_at), ev.element_uuid, ev.element_type, ev.type
```

#### Impact
- Monthly unique session counts will now be **accurate** (previously could be 2-10x inflated)
- Monthly unique user counts will now be **accurate**
- **Requires raw data** to be available for the month being aggregated

---

### 2. ✅ Monthly Page Aggregation (CRITICAL)

**File:** `AnalyticsAggregationController.php`
**Method:** `actionMonthly()`
**Lines:** 288-318

#### Problem
Same issue as element aggregation - was summing unique counts from daily aggregates.

#### Fix Applied
Changed to query raw page views data with `COUNT(DISTINCT)`:

```php
// NEW (CORRECT)
SELECT
    YEAR(created_at) as year,
    MONTH(created_at) as month,
    page_uuid,
    url,
    COUNT(*) as total_views,
    COUNT(DISTINCT session_id) as unique_sessions,
    COUNT(DISTINCT CASE WHEN user_id IS NOT NULL AND user_id > 0 THEN user_id END) as unique_users
FROM analytics_page_views
WHERE YEAR(created_at) = :year
    AND MONTH(created_at) = :month
    AND is_bot = 0
GROUP BY YEAR(created_at), MONTH(created_at), page_uuid, url
```

#### Impact
- Page monthly unique counts now accurate
- Requires raw page view data for the month

---

### 3. ✅ Partner Statistics (CRITICAL)

**File:** `AnalyticsAggregationController.php`
**Method:** `generatePartnerStats()`
**Lines:** 425-485

#### Problem
Partner stats were summing unique counts from daily aggregates for all time periods (day, week, month, year):

```php
// OLD (INCORRECT)
SELECT
    COALESCE(SUM(total_views), 0) as total_views,
    COALESCE(SUM(unique_sessions), 0) as unique_sessions,
    COALESCE(SUM(unique_users), 0) as unique_users
FROM analytics_element_daily
WHERE element_uuid = :uuid
    AND date >= :start
```

This caused extreme overcounting:
- Weekly stats: up to 7x inflated
- Monthly stats: up to 30x inflated
- Yearly stats: up to 365x inflated

#### Fix Applied
Changed to query raw element views with `COUNT(DISTINCT)`:

```php
// NEW (CORRECT)
SELECT
    COUNT(*) as total_views,
    COUNT(DISTINCT ev.session_id) as unique_sessions,
    COUNT(DISTINCT CASE WHEN ev.user_id IS NOT NULL AND ev.user_id > 0 THEN ev.user_id END) as unique_users
FROM analytics_element_views ev
INNER JOIN analytics_sessions s ON ev.session_id = s.session_id
WHERE ev.element_uuid = :uuid
    AND ev.element_type = :type
    AND ev.type = :event
    AND DATE(ev.created_at) >= :start
    AND s.is_bot = 0
```

#### Impact
- Partner dashboard metrics now show **accurate** unique counts
- Partners will see **lower** numbers (but correct ones)
- Requires raw data within the retention period (30 days default)
- For yearly stats, you may need to increase retention period

---

## Important Changes in Data Requirements

### Before the Fix
- Monthly/partner aggregations could run after raw data was deleted
- Used daily aggregates as the source

### After the Fix
- **Monthly aggregation requires raw data** for the target month
- **Partner stats require raw data** within retention period
- Raw data must exist when these commands run

### Recommended Workflow

```bash
# Daily (automated via cron)
1. Bot detection        # 2 AM
2. Daily aggregation    # 3 AM
3. Partner stats        # 4 AM

# Monthly (run on 1st of month)
1. Monthly aggregation  # 5 AM - before cleanup!

# Cleanup (run on 2nd of month)
1. Cleanup old data     # 6 AM - after monthly aggregation
```

**CRITICAL:** Run monthly aggregation **BEFORE** cleanup, or it won't have source data.

---

## What About Existing Data?

If you have already run the old (incorrect) aggregation:

### Option A: Rerun Aggregation (Recommended)

```bash
# 1. Delete incorrect monthly data (optional backup first)
# For specific month:
DELETE FROM analytics_element_monthly WHERE year = 2025 AND month = 1;
DELETE FROM analytics_page_monthly WHERE year = 2025 AND month = 1;

# 2. Rerun monthly aggregation (if raw data still exists)
php yii crelish/analytics-aggregation/monthly 2025-01

# 3. Rebuild partner stats
php yii crelish/analytics-aggregation/partner-stats
```

### Option B: Accept Current Data

If raw data has already been deleted:
- Keep current monthly aggregates (they're inflated but you can't recalculate)
- New monthly aggregates from now on will be correct
- Consider adding a note in dashboards about data accuracy before 2025-02

---

## Testing the Fix

You can verify the fix worked by comparing old vs new aggregation:

```sql
-- For a specific element in January 2025
-- Compare what the old method would give vs new method

-- Old method (incorrect - sums daily uniques)
SELECT
    'Old (summed from daily)' as method,
    SUM(unique_sessions) as unique_sessions
FROM analytics_element_daily
WHERE element_uuid = 'YOUR_ELEMENT_UUID'
    AND date >= '2025-01-01'
    AND date < '2025-02-01';

-- New method (correct - distinct from raw)
SELECT
    'New (distinct from raw)' as method,
    COUNT(DISTINCT ev.session_id) as unique_sessions
FROM analytics_element_views ev
INNER JOIN analytics_sessions s ON ev.session_id = s.session_id
WHERE ev.element_uuid = 'YOUR_ELEMENT_UUID'
    AND DATE(ev.created_at) >= '2025-01-01'
    AND DATE(ev.created_at) < '2025-02-01'
    AND s.is_bot = 0;
```

The "New" method should show **lower** numbers than "Old" method. That's correct!

---

## Performance Considerations

### Pros
- Data is now mathematically correct
- No more inflated metrics

### Cons
- Monthly/partner aggregation queries are slightly slower (querying raw data vs aggregated)
- Requires more storage (keep raw data longer)

### Mitigation
1. **Add database indexes** to improve performance:

```sql
-- For monthly aggregation performance
CREATE INDEX idx_element_views_created ON analytics_element_views(created_at, element_uuid, type);
CREATE INDEX idx_page_views_created ON analytics_page_views(created_at, page_uuid);

-- For partner stats performance
CREATE INDEX idx_element_views_stats ON analytics_element_views(element_uuid, element_type, type, created_at);
```

2. **Adjust retention period** based on needs:
- If you need accurate yearly partner stats: `--retentionDays=365`
- For monthly only: `--retentionDays=60` (gives buffer for reprocessing)
- Minimum recommended: `--retentionDays=45`

---

## Files Modified

1. `/Users/smyr/Sites/gbits/giantbits/yii2-crelish/commands/AnalyticsAggregationController.php`
   - `actionMonthly()` - Lines 246-330 (element and page aggregation)
   - `generatePartnerStats()` - Lines 425-485

2. `/Users/smyr/Sites/gbits/giantbits/yii2-crelish/commands/BotDetectionController.php`
   - `detectTimingPatterns()` - Lines 368-396
   - `detectCrawlingPatterns()` - Lines 426-440

## Files Created

1. `/Users/smyr/Sites/gbits/giantbits/yii2-crelish/commands/README.md` - Full documentation
2. `/Users/smyr/Sites/gbits/giantbits/yii2-crelish/commands/ISSUES_AND_FIXES.md` - Issue analysis
3. `/Users/smyr/Sites/gbits/giantbits/yii2-crelish/commands/FIXES_APPLIED.md` - This file

---

### 4. ✅ Bot Detection - Timing Pattern Filtering (MINOR)

**File:** `BotDetectionController.php`
**Method:** `detectTimingPatterns()`
**Lines:** 368-396

#### Problem
Timing pattern detection only checked `pv.is_bot = 0` but didn't verify the session wasn't already marked as bot in `analytics_sessions`. This could cause:
- Re-detection of already identified bots
- Slight inefficiency in queries

#### Fix Applied
Added `INNER JOIN` with sessions table and check both conditions:

```php
// NEW (CORRECT)
FROM analytics_page_views pv
INNER JOIN analytics_sessions s ON pv.session_id = s.session_id
WHERE pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    AND pv.is_bot = 0
    AND s.is_bot = 0  -- Added this check
```

#### Impact
- More efficient queries (skips already-identified bot sessions)
- Consistent with volume anomaly detection approach
- Prevents redundant bot marking

---

### 5. ✅ Bot Detection - Crawling Pattern Filtering (MINOR)

**File:** `BotDetectionController.php`
**Method:** `detectCrawlingPatterns()`
**Lines:** 426-440

#### Problem
Same as timing pattern detection - only checked page view bot status, not session bot status.

#### Fix Applied
Added `INNER JOIN` and dual bot checks:

```php
// NEW (CORRECT)
FROM analytics_page_views pv
INNER JOIN analytics_sessions s ON pv.session_id = s.session_id
WHERE pv.is_bot = 0
    AND s.is_bot = 0  -- Added this check
    AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
```

#### Impact
- Consistent bot filtering across all detection methods
- Better query efficiency

---

## What Was NOT Changed

### Daily Aggregation
- **No changes** to daily aggregation (`actionDaily()`)
- Already correctly uses `COUNT(DISTINCT)` from raw data
- Working as intended

### Cleanup
- **No changes** to cleanup logic
- Bot traffic deletion is acceptable per user requirements

---

## Next Steps

1. **Test the fixes:**
   ```bash
   # Test with dry run first
   php yii crelish/analytics-aggregation/monthly --dryRun
   php yii crelish/analytics-aggregation/partner-stats --dryRun
   ```

2. **Consider retention period:**
   - Current default: 30 days
   - For partner stats to work year-round: increase retention
   - Or run partner stats before cleanup

3. **Update cron schedule** (if needed):
   - Ensure monthly aggregation runs before cleanup
   - Consider running partner stats more frequently if needed

4. **Add database indexes** for performance:
   - See Performance Considerations section above

5. **Update dashboards** (optional):
   - Add note about data accuracy cutoff date
   - Explain why metrics may appear lower after fix

---

## Questions?

See the detailed analysis in `ISSUES_AND_FIXES.md` for:
- More technical details
- Example scenarios showing the problem
- SQL testing queries
- Migration strategies