-- Analytics Data Diagnostic Queries
-- Run these to understand what's happening with your data

-- 1. Check for orphaned element views (no matching session)
SELECT
    'Orphaned element views' as issue,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM analytics_element_views), 2) as percentage
FROM analytics_element_views ev
LEFT JOIN analytics_sessions s ON ev.session_id = s.session_id
WHERE s.session_id IS NULL;

-- 2. Check element views with bot sessions (should be filtered but might not be)
SELECT
    'Element views from bot sessions' as issue,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM analytics_element_views), 2) as percentage
FROM analytics_element_views ev
INNER JOIN analytics_sessions s ON ev.session_id = s.session_id
WHERE s.is_bot = 1;

-- 3. Check element views that would be counted with current LEFT JOIN logic
SELECT
    'Element views counted as human (LEFT JOIN)' as method,
    COUNT(*) as count
FROM analytics_element_views ev
LEFT JOIN analytics_sessions s ON ev.session_id = s.session_id
WHERE (s.is_bot = 0 OR s.is_bot IS NULL);

-- 4. Check element views that SHOULD be counted with INNER JOIN
SELECT
    'Element views that should count (INNER JOIN)' as method,
    COUNT(*) as count
FROM analytics_element_views ev
INNER JOIN analytics_sessions s ON ev.session_id = s.session_id
WHERE s.is_bot = 0;

-- 5. Compare the difference
SELECT
    'Difference (overcounted element views)' as issue,
    (
        SELECT COUNT(*)
        FROM analytics_element_views ev
        LEFT JOIN analytics_sessions s ON ev.session_id = s.session_id
        WHERE (s.is_bot = 0 OR s.is_bot IS NULL)
    ) - (
        SELECT COUNT(*)
        FROM analytics_element_views ev
        INNER JOIN analytics_sessions s ON ev.session_id = s.session_id
        WHERE s.is_bot = 0
    ) as overcounted_records;

-- 6. Session analysis
SELECT
    'Total sessions' as metric,
    COUNT(*) as count
FROM analytics_sessions
UNION ALL
SELECT
    'Bot sessions',
    COUNT(*)
FROM analytics_sessions
WHERE is_bot = 1
UNION ALL
SELECT
    'Human sessions',
    COUNT(*)
FROM analytics_sessions
WHERE is_bot = 0;

-- 7. Page views analysis
SELECT
    'Total page views' as metric,
    COUNT(*) as count
FROM analytics_page_views
UNION ALL
SELECT
    'Bot page views',
    COUNT(*)
FROM analytics_page_views
WHERE is_bot = 1
UNION ALL
SELECT
    'Human page views',
    COUNT(*)
FROM analytics_page_views
WHERE is_bot = 0;

-- 8. Element views per session (top 20 suspicious ones)
SELECT
    ev.session_id,
    s.is_bot,
    COUNT(*) as element_view_count,
    COUNT(DISTINCT ev.element_uuid) as unique_elements,
    MIN(ev.created_at) as first_view,
    MAX(ev.created_at) as last_view,
    TIMESTAMPDIFF(SECOND, MIN(ev.created_at), MAX(ev.created_at)) as session_duration_seconds
FROM analytics_element_views ev
LEFT JOIN analytics_sessions s ON ev.session_id = s.session_id
GROUP BY ev.session_id, s.is_bot
ORDER BY element_view_count DESC
LIMIT 20;

-- 9. Check if element views exist for today without corresponding page views
SELECT
    DATE(ev.created_at) as date,
    COUNT(DISTINCT ev.session_id) as element_view_sessions,
    COUNT(DISTINCT pv.session_id) as page_view_sessions,
    COUNT(DISTINCT ev.session_id) - COUNT(DISTINCT pv.session_id) as sessions_with_only_elements
FROM analytics_element_views ev
LEFT JOIN analytics_page_views pv ON ev.session_id = pv.session_id
    AND DATE(ev.created_at) = DATE(pv.created_at)
WHERE DATE(ev.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAYS)
GROUP BY DATE(ev.created_at)
ORDER BY date DESC;

-- 10. Element view to page view ratio by day
SELECT
    DATE(created_at) as date,
    'element_views' as type,
    COUNT(*) as count
FROM analytics_element_views
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAYS)
GROUP BY DATE(created_at)
UNION ALL
SELECT
    DATE(created_at) as date,
    'page_views' as type,
    COUNT(*) as count
FROM analytics_page_views
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAYS)
GROUP BY DATE(created_at)
ORDER BY date DESC, type;