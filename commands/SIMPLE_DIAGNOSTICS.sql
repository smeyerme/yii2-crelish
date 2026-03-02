-- SIMPLE DIAGNOSTICS FOR PAGE VIEWS
-- Run these one by one to understand your data

-- 1. What dates do you actually have data for?
SELECT
    DATE(created_at) as date,
    COUNT(*) as page_views,
    COUNT(DISTINCT session_id) as unique_sessions
FROM analytics_page_views
WHERE is_bot = 0
GROUP BY DATE(created_at)
ORDER BY date DESC
LIMIT 30;

-- 2. Check if page_views table has any records at all
SELECT
    COUNT(*) as total_records,
    COUNT(CASE WHEN is_bot = 1 THEN 1 END) as bot_records,
    COUNT(CASE WHEN is_bot = 0 THEN 1 END) as human_records
FROM analytics_page_views;

-- 3. Check if sessions table has records
SELECT
    COUNT(*) as total_sessions,
    COUNT(CASE WHEN is_bot = 1 THEN 1 END) as bot_sessions,
    COUNT(CASE WHEN is_bot = 0 THEN 1 END) as human_sessions
FROM analytics_sessions;

-- 4. Simple count for recent data (last 7 days)
SELECT
    DATE(created_at) as date,
    COUNT(*) as page_views
FROM analytics_page_views
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAYS)
    AND is_bot = 0
GROUP BY DATE(created_at)
ORDER BY date DESC;

-- 5. Check for orphaned page views (no matching session)
SELECT
    'Orphaned page views' as description,
    COUNT(*) as count
FROM analytics_page_views pv
LEFT JOIN analytics_sessions s ON pv.session_id = s.session_id
WHERE s.session_id IS NULL;

-- 6. Sample of recent page views with session info
SELECT
    pv.id,
    pv.session_id,
    pv.url,
    pv.created_at,
    pv.is_bot as pv_is_bot,
    s.is_bot as session_is_bot,
    s.user_agent
FROM analytics_page_views pv
LEFT JOIN analytics_sessions s ON pv.session_id = s.session_id
ORDER BY pv.created_at DESC
LIMIT 20;

-- 7. Pages per session distribution (for all recent data)
SELECT
    pages_per_session,
    COUNT(*) as session_count
FROM (
    SELECT
        session_id,
        COUNT(*) as pages_per_session
    FROM analytics_page_views
    WHERE is_bot = 0
        AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAYS)
    GROUP BY session_id
) as session_stats
GROUP BY pages_per_session
ORDER BY pages_per_session;

-- 8. Check what date range you have in the database
SELECT
    'Page views date range' as table_name,
    MIN(created_at) as oldest,
    MAX(created_at) as newest,
    DATEDIFF(MAX(created_at), MIN(created_at)) as days_of_data
FROM analytics_page_views;