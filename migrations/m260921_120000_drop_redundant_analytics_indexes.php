<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m260921_120000_drop_redundant_analytics_indexes
 *
 * Removes analytics indexes that can never serve as a useful access path but
 * are carried on every row of the largest tables in the schema.
 *
 * Two categories:
 *
 * 1. Leftmost-prefix duplicates. An index on (a) is redundant when an index on
 *    (a, b, c) already exists, and `idx_session_id` on analytics_sessions is an
 *    exact duplicate of that table's PRIMARY KEY.
 *
 * 2. Very low cardinality columns. `element_type` has 24 distinct values across
 *    8.5M rows and `type` has 6; `page_type` and `is_bot` have 2 each. The
 *    optimiser will not choose them over a table scan, and element_type is a
 *    varchar(50) utf8mb4 column, so its index entries are up to 200 bytes wide.
 *
 * On the reference dataset (8.5M element views) the indexes on
 * analytics_element_views were larger than the table data itself.
 */
class m260921_120000_drop_redundant_analytics_indexes extends Migration
{
    /**
     * Index name => table, in drop order.
     */
    private const REDUNDANT_INDEXES = [
        // Leftmost prefix of idx_element_views_aggregation (created_at, ...)
        ['analytics_element_views', 'created_at'],
        // Low cardinality, also covered by idx_element_views_aggregation
        ['analytics_element_views', 'element_type'],
        ['analytics_element_views', 'type'],
        // Leftmost prefix of idx_session_bot (session_id, is_bot)
        ['analytics_page_views', 'idx_session_id'],
        // Low cardinality; is_bot is covered by idx_page_views_aggregation
        ['analytics_page_views', 'page_type'],
        ['analytics_page_views', 'is_bot'],
        // Exact duplicate of the PRIMARY KEY on analytics_sessions
        ['analytics_sessions', 'idx_session_id'],
        // Low cardinality
        ['analytics_sessions', 'is_bot'],
    ];

    public function safeUp()
    {
        foreach (self::REDUNDANT_INDEXES as [$table, $index]) {
            if ($this->indexExists($table, $index)) {
                $this->dropIndex($index, '{{%' . $table . '}}');
                echo "    > dropped index {$index} on {$table}\n";
            } else {
                echo "    > index {$index} on {$table} not present, skipping\n";
            }
        }
    }

    public function safeDown()
    {
        foreach (array_reverse(self::REDUNDANT_INDEXES) as [$table, $index]) {
            if (!$this->indexExists($table, $index)) {
                // Every entry above is a single-column index named after its column,
                // except the two session_id aliases.
                $column = str_starts_with($index, 'idx_') ? substr($index, 4) : $index;
                $this->createIndex($index, '{{%' . $table . '}}', $column);
            }
        }
    }

    /**
     * @param string $table Unprefixed table name
     * @param string $index Index name
     * @return bool
     */
    private function indexExists(string $table, string $index): bool
    {
        $schema = $this->db->schema;
        $tableName = $schema->getRawTableName('{{%' . $table . '}}');

        if ($schema->getTableSchema($tableName) === null) {
            return false;
        }

        $count = $this->db->createCommand("
            SELECT COUNT(*)
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND index_name = :index
        ")
            ->bindValue(':table', $tableName)
            ->bindValue(':index', $index)
            ->queryScalar();

        return $count > 0;
    }
}
