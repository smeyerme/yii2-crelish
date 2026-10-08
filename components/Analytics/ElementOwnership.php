<?php

namespace giantbits\crelish\components\Analytics;

use Yii;
use yii\db\Connection;

/**
 * Which element tables say who owns a row: the element types listed in the
 * project's config/analytics-element-types.php whose table has a `company`
 * column. The company statistics use the same tables to find a company's content.
 */
final class ElementOwnership
{
    /**
     * @param array|null $config The element types config; null reads @app/config/analytics-element-types.php
     * @return array<string, string> element type => table name
     */
    public static function companyOwnedTables(Connection $db, ?array $config = null): array
    {
        if ($config === null) {
            $file = Yii::getAlias('@app/config/analytics-element-types.php', false);
            $config = ($file && is_file($file)) ? (array)(include $file) : [];
        }

        $tables = [];
        foreach ($config as $type => $definition) {
            $table = is_array($definition) ? ($definition['table'] ?? null) : null;
            if (!is_string($table) || $table === '') {
                continue;
            }

            $schema = $db->getTableSchema($table, true);
            if ($schema !== null && isset($schema->columns['company'])) {
                $tables[(string)$type] = $table;
            }
        }

        return $tables;
    }
}
