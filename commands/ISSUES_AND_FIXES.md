# Analytics Controllers - Issues and Recommended Fixes

This document outlines potential errors and issues found in the analytics aggregation logic, along with recommended fixes.

---

## Critical Issues (Must Fix)

### 1. Monthly Aggregation Incorrectly Sums Unique Counts ⚠️ CRITICAL

**Severity:** CRITICAL - Results in mathematically incorrect data
**Files:** `AnalyticsAggregationController.php`
**Lines:** 258-260, 296-297

#### Problem

The monthly aggregation sums `unique_sessions` and `unique_users` from daily aggregates:

```php
SELECT
    SUM(total_views) as total_views,
    SUM(unique_sessions) as unique_sessions,      // ❌ WRONG
    SUM(unique_users) as unique_users              // ❌ WRONG
FROM {{%analytics_element_daily}}
WHERE YEAR(date) = :year AND MONTH(date) = :month
GROUP BY element_uuid, element_type, event_type
```

#### Why This Is Wrong

**Example scenario:**
- User #123 visits Element A on Jan 1, 2, and 3
- Daily aggregates:
  - Jan 1: element A → 1 unique user
  - Jan 2: element A → 1 unique user (same user!)
  - Jan 3: element A → 1 unique user (same user!)
- Monthly sum: **3 unique users** ❌
- Actual unique users: **1 unique user** ✓

The same user/session is counted multiple times if they appear on multiple days.

#### Impact

- Monthly unique sessions are **inflated** (could be 2-5x higher than reality)
- Monthly unique users are **inflated**
- Partner statistics are based on this incorrect data
- Business decisions based on these metrics will be wrong

#### Recommended Fix

**Option A: Re-aggregate from raw data** (Accurate but slower)

```php
// For element monthly
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
")->bindValue(':year', $year)
  ->bindValue(':month', $month)
  ->execute();

// For page monthly
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
")->bindValue(':year', $year)
  ->bindValue(':month', $month)
  ->execute();
```

**Option B: Keep sum but add disclaimer** (Fast but inaccurate)

If performance is critical and you run cleanup quickly (so raw data isn't available), you could:
1. Rename columns to `approximate_unique_sessions` and `approximate_unique_users`
2. Document that monthly/partner stats overcount unique metrics
3. Use them only for trend analysis, not absolute numbers

**Recommendation:** Use Option A. The data should be accurate.

---

### 2. Partner Stats Has Same Summing Issue ⚠️ CRITICAL

**Severity:** CRITICAL - Results in inflated partner metrics
**File:** `AnalyticsAggregationController.php`
**Lines:** 440-456

#### Problem

Same issue as monthly aggregation - sums unique counts across days:

```php
$stats = $db->createCommand("
    SELECT
        COALESCE(SUM(total_views), 0) as total_views,
        COALESCE(SUM(unique_sessions), 0) as unique_sessions,    // ❌ WRONG
        COALESCE(SUM(unique_users), 0) as unique_users            // ❌ WRONG
    FROM {{%analytics_element_daily}}
    WHERE element_uuid = :uuid
        AND element_type = :type
        AND event_type = :event
        AND date >= :start
")->queryOne();
```

#### Impact

Partner dashboards show inflated metrics:
- Weekly stats overcount by ~7x in worst case
- Monthly stats overcount by ~30x in worst case
- Partners think their content is performing better than it actually is

#### Recommended Fix

Query raw data for accurate counts:

```php
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
```

**Trade-off:** This queries raw data which might be deleted after cleanup. Consider:
1. Run partner stats before cleanup
2. Keep raw data longer (60-90 days)
3. Accept that partner stats might become unavailable for old periods after cleanup

---

## Important Issues (Should Fix)

### 3. Element Views Cleanup Deletes Unaggregated Bot Data

**Severity:** HIGH - Potential data loss
**File:** `AnalyticsAggregationController.php`
**Lines:** 517-520, 548-550

#### Problem

The cleanup deletes ALL element views older than cutoff:

```php
// Counts all element views (both bot and human)
$elementViewsCount = $db->createCommand("
    SELECT COUNT(*) FROM {{%analytics_element_views}}
    WHERE created_at < :cutoff
")->queryScalar();

// Deletes all element views (both bot and human)
$deleted = $db->createCommand()
    ->delete('{{%analytics_element_views}}', ['<', 'created_at', $cutoffDate])
    ->execute();
```

But daily aggregation only aggregates human traffic:

```php
// Line 129-131: Only aggregates where s.is_bot = 0
WHERE DATE(ev.created_at) = :date
    AND (s.is_bot = 0 OR s.is_bot IS NULL)
```

**Result:** Bot element views are deleted without being aggregated anywhere.

#### Impact

- If you later want to analyze bot behavior on elements, data is gone
- Can't verify bot detection worked correctly
- Data loss violates "aggregate then delete" principle

#### Recommended Fix

**Option A: Only delete aggregated data** (Safest)

```php
// Only delete element views that were aggregated (human traffic)
$deleted = $db->createCommand("
    DELETE ev FROM {{%analytics_element_views}} ev
    INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
    WHERE ev.created_at < :cutoff
        AND s.is_bot = 0
")->bindValue(':cutoff', $cutoffDate)->execute();
```

**Option B: Delete all but update count description**

If you don't care about bot element view history, keep current behavior but fix the messaging:

```php
// Be explicit about what we're counting
$humanElementViewsCount = $db->createCommand("
    SELECT COUNT(*)
    FROM {{%analytics_element_views}} ev
    INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
    WHERE ev.created_at < :cutoff
        AND s.is_bot = 0
")->bindValue(':cutoff', $cutoffDate)->queryScalar();

$botElementViewsCount = $db->createCommand("
    SELECT COUNT(*)
    FROM {{%analytics_element_views}} ev
    INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
    WHERE ev.created_at < :cutoff
        AND s.is_bot = 1
")->bindValue(':cutoff', $cutoffDate)->queryScalar();

$this->stdout("Records to delete:\n");
$this->stdout("  Element views (human): " . number_format($humanElementViewsCount) . "\n");
$this->stdout("  Element views (bot, unaggregated): " . number_format($botElementViewsCount) . "\n");
$this->stdout("  Page views: " . number_format($pageViewsCount) . "\n\n");
```

**Recommendation:** Use Option A - only delete what was aggregated.

---

### 4. NULL Session Handling in Daily Aggregation

**Severity:** MEDIUM - May include orphaned data
**File:** `AnalyticsAggregationController.php`
**Lines:** 90-91, 129-131

#### Problem

```sql
LEFT JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
WHERE DATE(ev.created_at) = :date
    AND (s.is_bot = 0 OR s.is_bot IS NULL)
```

The condition `(s.is_bot = 0 OR s.is_bot IS NULL)` means:
- Element views with no matching session are counted as human traffic
- This could include orphaned data from deleted sessions
- Or corrupt data with invalid session IDs

#### Impact

- Small amount of invalid data could be aggregated
- Unique session count might be slightly inflated (NULL sessions counted)
- Data integrity issue

#### Recommended Fix

Use INNER JOIN and strict filtering:

```php
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
    INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id  -- Changed to INNER JOIN
    WHERE DATE(ev.created_at) = :date
        AND s.is_bot = 0  -- Simplified condition
    GROUP BY DATE(ev.created_at), ev.element_uuid, ev.element_type, ev.page_uuid, ev.type
    ON DUPLICATE KEY UPDATE
        total_views = VALUES(total_views),
        unique_sessions = VALUES(unique_sessions),
        unique_users = VALUES(unique_users),
        updated_at = NOW()
")->bindValue(':date', $targetDate)->execute();
```

Also update the count query at line 90-92:

```php
$elementViewCount = $db->createCommand("
    SELECT COUNT(*)
    FROM {{%analytics_element_views}} ev
    INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
    WHERE DATE(ev.created_at) = :date
        AND s.is_bot = 0
")->bindValue(':date', $targetDate)->queryScalar();
```

---

## Minor Issues (Nice to Fix)

### 5. Inconsistent Bot Filtering in Detection Methods

**Severity:** LOW - Inefficiency issue
**File:** `BotDetectionController.php`
**Lines:** 382-383, 429

#### Problem

Volume anomaly detection (line 288-290) checks both tables:
```sql
WHERE s.is_bot = 0 AND pv.is_bot = 0
```

But timing pattern detection (line 382) and crawling pattern detection (line 429) only check page views:
```sql
WHERE is_bot = 0  -- Only checks page_views.is_bot
```

#### Impact

- If a session is marked as bot but its page views aren't updated yet, these queries will find it
- Inefficiency: re-detecting already marked bots
- Minor: `markAsBot()` updates both tables, so this eventually syncs

#### Recommended Fix

Add session join and check to timing/crawling queries:

```php
// In detectTimingPatterns() - Line 368-394
$timingAnomalies = $db->createCommand("
    SELECT
        pv.session_id,
        AVG(time_diff) as avg_interval,
        STDDEV(time_diff) as stddev_interval,
        COUNT(*) as interval_count
    FROM (
        SELECT
            pv.session_id,
            TIMESTAMPDIFF(SECOND,
                LAG(pv.created_at) OVER (PARTITION BY pv.session_id ORDER BY pv.created_at),
                pv.created_at
            ) as time_diff
        FROM analytics_page_views pv
        INNER JOIN analytics_sessions s ON pv.session_id = s.session_id
        WHERE pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            AND pv.is_bot = 0
            AND s.is_bot = 0  -- Add this check
    ) as intervals
    WHERE time_diff IS NOT NULL
        AND time_diff < 300
    GROUP BY session_id
    HAVING interval_count > 20
        AND avg_interval < :max_interval
        AND stddev_interval < :consistency_threshold
")
```

Same for `detectCrawlingPatterns()` at line 424:

```php
$paginationCrawlers = $db->createCommand("
    SELECT
        pv.session_id,
        GROUP_CONCAT(DISTINCT pv.url ORDER BY pv.created_at) as url_sequence
    FROM analytics_page_views pv
    INNER JOIN analytics_sessions s ON pv.session_id = s.session_id
    WHERE pv.is_bot = 0
        AND s.is_bot = 0  -- Add this check
        AND pv.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        AND (pv.url LIKE '%page=%' OR pv.url LIKE '%/page/%' OR pv.url LIKE '%&p=%')
    GROUP BY pv.session_id
    HAVING COUNT(*) > :threshold
")
```

---

## Recommendations Summary

### Immediate Actions (Critical)

1. **Fix monthly aggregation** - Use raw data queries instead of summing daily aggregates
2. **Fix partner stats** - Use raw data queries for accurate unique counts
3. **Fix cleanup logic** - Only delete element views that were aggregated

### Short-term Actions (Important)

4. **Fix NULL session handling** - Use INNER JOIN in daily aggregation
5. **Test current data accuracy** - Run queries to check how inflated your current monthly/partner stats are

### Long-term Improvements (Nice to have)

6. **Add consistency checks** - Command to verify aggregated data matches raw data
7. **Add data quality alerts** - Warn if monthly unique sessions > sum of daily unique sessions (impossible)
8. **Consider adding is_bot to element_views** - Would simplify queries and improve consistency

---

## Testing Recommendations

After implementing fixes, test with:

```sql
-- Verify monthly aggregation is correct
-- Compare new logic vs old logic
SELECT
    'Old (incorrect)' as method,
    SUM(unique_sessions) as unique_sessions
FROM analytics_element_daily
WHERE element_uuid = 'test-uuid'
    AND date >= '2025-01-01'
    AND date < '2025-02-01'

UNION ALL

SELECT
    'New (correct)' as method,
    COUNT(DISTINCT session_id) as unique_sessions
FROM analytics_element_views ev
INNER JOIN analytics_sessions s ON ev.session_id = s.session_id
WHERE ev.element_uuid = 'test-uuid'
    AND DATE(ev.created_at) >= '2025-01-01'
    AND DATE(ev.created_at) < '2025-02-01'
    AND s.is_bot = 0;
```

You should see the "New (correct)" number is significantly lower than "Old (incorrect)".

---

## Migration Strategy

If you've already collected data with the current (incorrect) logic:

1. **Don't delete existing monthly/partner tables** - Keep as backup
2. **Create new tables** - `analytics_element_monthly_v2`, etc.
3. **Implement fixes** - Update code to use correct aggregation
4. **Backfill corrected data** - Re-run monthly aggregation with new logic
5. **Compare results** - Verify new data makes sense
6. **Switch over** - Rename tables once confident
7. **Update dashboards** - Partners will see lower (but accurate) numbers

---

## Questions to Consider

1. **How much historical data do you need?**
   - If partner stats need 1 year of data, keep raw data for 1 year
   - Otherwise, monthly aggregation won't have source data

2. **Can you accept approximate unique counts?**
   - If yes, add disclaimer to dashboards
   - If no, implement the raw data query fixes

3. **Do you need bot traffic history?**
   - If yes, don't delete bot element views
   - If no, current cleanup is acceptable (but should be documented)

4. **What's your data volume?**
   - If querying raw data for partner stats is too slow, consider keeping longer retention or using materialized views