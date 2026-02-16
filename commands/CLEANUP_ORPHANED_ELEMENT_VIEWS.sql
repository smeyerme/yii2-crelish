-- Cleanup script for orphaned element views
-- These are element views where the session no longer exists (was deleted as bot traffic)

-- WARNING: This will delete 94.52% of your element views (3,831,697 records)
-- Make sure you have a database backup before running this!

-- Step 1: Count orphaned records (for verification)
SELECT
    'Orphaned element views to delete' as description,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM analytics_element_views), 2) as percentage
FROM analytics_element_views ev
LEFT JOIN analytics_sessions s ON ev.session_id = s.session_id
WHERE s.session_id IS NULL;

-- Step 2: Preview which sessions are affected (top 100)
SELECT
    ev.session_id,
    COUNT(*) as element_view_count,
    MIN(ev.created_at) as first_view,
    MAX(ev.created_at) as last_view
FROM analytics_element_views ev
LEFT JOIN analytics_sessions s ON ev.session_id = s.session_id
WHERE s.session_id IS NULL
GROUP BY ev.session_id
ORDER BY element_view_count DESC
LIMIT 100;

-- Step 3: DELETE ORPHANED ELEMENT VIEWS
-- UNCOMMENT THE FOLLOWING LINE TO EXECUTE THE DELETE
-- DELETE ev FROM analytics_element_views ev
-- LEFT JOIN analytics_sessions s ON ev.session_id = s.session_id
-- WHERE s.session_id IS NULL;

-- Step 4: Optimize the table after deletion
-- UNCOMMENT THE FOLLOWING LINE TO OPTIMIZE
-- OPTIMIZE TABLE analytics_element_views;

-- Step 5: Verify cleanup
-- Run this after deletion to confirm
SELECT
    'Element views after cleanup' as description,
    COUNT(*) as total_count,
    COUNT(CASE WHEN s.session_id IS NULL THEN 1 END) as orphaned_count
FROM analytics_element_views ev
LEFT JOIN analytics_sessions s ON ev.session_id = s.session_id;