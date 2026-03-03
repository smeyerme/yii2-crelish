<?php

/**
 * Example: Element type configuration for analytics title resolution.
 *
 * Copy this file to @app/config/analytics-element-types.php and adjust
 * the mappings for your project.
 *
 * When this config file exists, ElementTitleResolver will use direct DB
 * queries to resolve element titles — bypassing CrelishModelResolver
 * auto-discovery. This is useful when model classes are not available
 * for all element types tracked in analytics_element_daily.
 *
 * If the config file does not exist, ElementTitleResolver falls through
 * entirely to CrelishModelResolver (identical to the previous behavior).
 *
 * Format:
 *   'type' => [
 *       'table'       => 'db_table_name',           // required: the DB table for this type
 *       'titleFields' => ['systitle', 'title'],      // tried in order; first non-empty value wins
 *       'extraFields' => ['mime', 'size'],            // optional: additional fields returned by resolveWithExtras()
 *   ]
 */
return [
    'news'    => ['table' => 'news',    'titleFields' => ['systitle', 'title']],
    'company' => ['table' => 'company', 'titleFields' => ['systitle']],
    'asset'   => ['table' => 'asset',   'titleFields' => ['title', 'fileName'], 'extraFields' => ['mime', 'size']],
    // Add your project-specific element types here...
];
