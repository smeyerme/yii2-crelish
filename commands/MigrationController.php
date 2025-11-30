<?php

namespace giantbits\crelish\commands;

use giantbits\crelish\components\CrelishDynamicModel;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use yii\helpers\Inflector;
use yii\helpers\Json;
use yii\db\Schema;

/**
 * Generates database migrations from JSON element definitions.
 *
 * This command can create migrations for:
 * - Creating new tables from element definitions
 * - Updating existing tables when fields are added/removed
 * - Generating diff migrations comparing JSON to current database schema
 *
 * Usage:
 *   php yii crelish/migration/create event           # Create migration for new table
 *   php yii crelish/migration/update event           # Create migration for schema changes
 *   php yii crelish/migration/create-all             # Create migrations for all missing tables
 *   php yii crelish/migration/diff event             # Show diff between JSON and database
 */
class MigrationController extends Controller
{
    /**
     * @var string The directory where element definitions are stored
     */
    public $definitionsDir = '@app/workspace/elements';

    /**
     * @var string The directory where migrations should be created
     */
    public $migrationsDir = '@app/migrations';

    /**
     * @var string The namespace for migrations (empty for no namespace)
     */
    public $migrationsNamespace = '';

    /**
     * @var bool Whether to use table prefix from Yii db config
     */
    public $useTablePrefix = true;

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'migrationsDir',
            'migrationsNamespace',
            'useTablePrefix',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'm' => 'migrationsDir',
            'n' => 'migrationsNamespace',
        ]);
    }

    /**
     * Creates a migration for a new content type table
     *
     * @param string $ctype The content type name
     * @return int Exit code
     */
    public function actionCreate($ctype)
    {
        $this->stdout("Creating migration for: $ctype\n\n", Console::FG_GREEN);

        // Load element definition
        $definition = $this->loadDefinition($ctype);
        if ($definition === null) {
            return ExitCode::DATAERR;
        }

        // Check if table already exists
        $tableName = $ctype;
        $tableSchema = Yii::$app->db->getTableSchema($tableName, true);

        if ($tableSchema !== null) {
            $this->stdout("Table '$tableName' already exists.\n", Console::FG_YELLOW);
            $this->stdout("Use 'update' action to create a migration for schema changes.\n");
            return ExitCode::OK;
        }

        // Generate migration content
        $columns = $this->getColumnsFromDefinition($definition);
        $migrationContent = $this->generateCreateTableMigration($ctype, $columns);

        // Write migration file
        return $this->writeMigrationFile("create_{$ctype}_table", $migrationContent);
    }

    /**
     * Creates a migration to update an existing table based on JSON changes
     *
     * @param string $ctype The content type name
     * @return int Exit code
     */
    public function actionUpdate($ctype)
    {
        $this->stdout("Creating update migration for: $ctype\n\n", Console::FG_GREEN);

        // Load element definition
        $definition = $this->loadDefinition($ctype);
        if ($definition === null) {
            return ExitCode::DATAERR;
        }

        // Get current table schema
        $tableName = $ctype;
        $tableSchema = Yii::$app->db->getTableSchema($tableName, true);

        if ($tableSchema === null) {
            $this->stdout("Table '$tableName' does not exist.\n", Console::FG_YELLOW);
            $this->stdout("Use 'create' action to create a new table.\n");
            return ExitCode::OK;
        }

        // Calculate diff
        $diff = $this->calculateSchemaDiff($ctype, $definition, $tableSchema);

        if (empty($diff['add']) && empty($diff['remove']) && empty($diff['modify'])) {
            $this->stdout("No schema changes detected for '$ctype'.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        // Show diff summary
        $this->showDiffSummary($diff);

        // Confirm before creating migration
        if (!$this->confirm("Create migration for these changes?")) {
            return ExitCode::OK;
        }

        // Generate migration content
        $migrationContent = $this->generateUpdateTableMigration($ctype, $diff);

        // Write migration file
        return $this->writeMigrationFile("update_{$ctype}_table", $migrationContent);
    }

    /**
     * Creates migrations for all content types that don't have tables yet
     *
     * @return int Exit code
     */
    public function actionCreateAll()
    {
        $this->stdout("Creating migrations for all missing tables...\n\n", Console::FG_GREEN);

        $definitionsDir = Yii::getAlias($this->definitionsDir);
        $files = FileHelper::findFiles($definitionsDir, ['only' => ['*.json']]);

        $created = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $ctype = pathinfo($file, PATHINFO_FILENAME);

            // Load definition to check storage type
            $definition = $this->loadDefinition($ctype);
            if ($definition === null) {
                continue;
            }

            // Skip JSON storage types
            $storage = $definition['storage'] ?? 'json';
            if ($storage !== 'db') {
                $this->stdout("Skipping '$ctype' (storage: $storage)\n", Console::FG_YELLOW);
                $skipped++;
                continue;
            }

            // Check if table exists
            $tableSchema = Yii::$app->db->getTableSchema($ctype, true);
            if ($tableSchema !== null) {
                $this->stdout("Skipping '$ctype' (table exists)\n", Console::FG_YELLOW);
                $skipped++;
                continue;
            }

            // Create migration
            $columns = $this->getColumnsFromDefinition($definition);
            $migrationContent = $this->generateCreateTableMigration($ctype, $columns);

            if ($this->writeMigrationFile("create_{$ctype}_table", $migrationContent, false) === ExitCode::OK) {
                $created++;
            }

            // Small delay to ensure unique timestamps
            usleep(100000);
        }

        $this->stdout("\nCreated $created migrations, skipped $skipped.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Shows the schema diff between JSON definition and database
     *
     * @param string $ctype The content type name
     * @return int Exit code
     */
    public function actionDiff($ctype)
    {
        $this->stdout("Schema diff for: $ctype\n\n", Console::FG_GREEN);

        // Load element definition
        $definition = $this->loadDefinition($ctype);
        if ($definition === null) {
            return ExitCode::DATAERR;
        }

        // Get current table schema
        $tableName = $ctype;
        $tableSchema = Yii::$app->db->getTableSchema($tableName, true);

        if ($tableSchema === null) {
            $this->stdout("Table '$tableName' does not exist.\n", Console::FG_YELLOW);
            $this->stdout("JSON defines the following columns:\n\n");

            $columns = $this->getColumnsFromDefinition($definition);
            foreach ($columns as $name => $type) {
                $this->stdout("  + $name: $type\n", Console::FG_GREEN);
            }
            return ExitCode::OK;
        }

        // Calculate diff
        $diff = $this->calculateSchemaDiff($ctype, $definition, $tableSchema);

        if (empty($diff['add']) && empty($diff['remove']) && empty($diff['modify'])) {
            $this->stdout("Schema is in sync - no differences found.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->showDiffSummary($diff);
        return ExitCode::OK;
    }

    /**
     * Lists all content types and their table status
     *
     * @return int Exit code
     */
    public function actionStatus()
    {
        $this->stdout("Content Type Table Status\n", Console::FG_GREEN);
        $this->stdout(str_repeat("=", 60) . "\n\n");

        $definitionsDir = Yii::getAlias($this->definitionsDir);
        $files = FileHelper::findFiles($definitionsDir, ['only' => ['*.json']]);

        foreach ($files as $file) {
            $ctype = pathinfo($file, PATHINFO_FILENAME);

            $definition = $this->loadDefinition($ctype);
            if ($definition === null) {
                continue;
            }

            $storage = $definition['storage'] ?? 'json';

            if ($storage !== 'db') {
                $this->stdout("$ctype", Console::FG_CYAN);
                $this->stdout(" [JSON storage]\n");
                continue;
            }

            $tableSchema = Yii::$app->db->getTableSchema($ctype, true);

            if ($tableSchema === null) {
                $this->stdout("$ctype", Console::FG_RED);
                $this->stdout(" [TABLE MISSING]\n");
            } else {
                // Check for diff
                $diff = $this->calculateSchemaDiff($ctype, $definition, $tableSchema);
                $hasDiff = !empty($diff['add']) || !empty($diff['remove']) || !empty($diff['modify']);

                if ($hasDiff) {
                    $this->stdout("$ctype", Console::FG_YELLOW);
                    $changes = count($diff['add']) + count($diff['remove']) + count($diff['modify']);
                    $this->stdout(" [NEEDS UPDATE - $changes changes]\n");
                } else {
                    $this->stdout("$ctype", Console::FG_GREEN);
                    $this->stdout(" [OK]\n");
                }
            }
        }

        return ExitCode::OK;
    }

    /**
     * Loads an element definition
     *
     * @param string $ctype The content type
     * @return array|null The definition or null on error
     */
    protected function loadDefinition($ctype)
    {
        $definitionFile = Yii::getAlias($this->definitionsDir . "/$ctype.json");

        if (!file_exists($definitionFile)) {
            $this->stderr("Element definition not found: $ctype.json\n", Console::FG_RED);
            return null;
        }

        try {
            $definition = Json::decode(file_get_contents($definitionFile), true);
            return $definition;
        } catch (\Exception $e) {
            $this->stderr("Invalid JSON in $ctype.json: " . $e->getMessage() . "\n", Console::FG_RED);
            return null;
        }
    }

    /**
     * Gets column definitions from an element definition
     *
     * @param array $definition The element definition
     * @return array Column definitions [name => type]
     */
    protected function getColumnsFromDefinition($definition)
    {
        $columns = [
            // Standard columns for all Crelish tables
            'uuid' => Schema::TYPE_STRING . '(36) NOT NULL PRIMARY KEY',
            'created' => Schema::TYPE_INTEGER . ' NULL',
            'updated' => Schema::TYPE_INTEGER . ' NULL',
            'created_by' => Schema::TYPE_STRING . '(36) NULL',
            'updated_by' => Schema::TYPE_STRING . '(36) NULL',
            'state' => Schema::TYPE_SMALLINT . ' NOT NULL DEFAULT 1',
        ];

        // Add columns from fields
        if (!empty($definition['fields'])) {
            foreach ($definition['fields'] as $field) {
                $key = $field['key'] ?? null;
                if ($key === null || isset($columns[$key])) {
                    continue;
                }

                $columns[$key] = $this->mapFieldToColumnType($field);
            }
        }

        return $columns;
    }

    /**
     * Maps a field definition to a database column type
     *
     * @param array $field The field definition
     * @return string The column type specification
     */
    protected function mapFieldToColumnType($field)
    {
        $type = $field['type'] ?? 'textInput';
        $transform = $field['transform'] ?? null;

        // Check for JSON transform
        if ($transform === 'json') {
            return 'JSON NULL';
        }

        // Check for date/datetime transform
        if ($transform === 'date' || $transform === 'datetime') {
            return Schema::TYPE_INTEGER . ' NULL';
        }

        // Map by field type
        switch ($type) {
            case 'textInput':
            case 'passwordInput':
                // Check for max length in rules
                $maxLength = $this->getMaxLengthFromRules($field);
                if ($maxLength > 255) {
                    return Schema::TYPE_TEXT . ' NULL';
                }
                return Schema::TYPE_STRING . "($maxLength) NULL";

            case 'textarea':
            case 'widget_\\brussens\\yii2\\extensions\\trumbowyg\\TrumbowygWidget':
                return Schema::TYPE_TEXT . ' NULL';

            case 'numberInput':
                return Schema::TYPE_INTEGER . ' NULL';

            case 'dropDownList':
                return Schema::TYPE_STRING . '(255) NULL';

            case 'checkboxList':
            case 'matrixConnector':
            case 'widgetConnector':
            case 'jsonEditor':
                return 'LONGTEXT NULL';

            case 'relationSelect':
                // Check if multiple
                $isMultiple = !empty($field['config']['multiple']);
                if ($isMultiple) {
                    return 'LONGTEXT NULL'; // Stores JSON array of UUIDs
                }
                return Schema::TYPE_STRING . '(36) NULL'; // Single UUID

            case 'assetConnector':
                // Check if multiple
                $isMultiple = !empty($field['config']['multiple']);
                if ($isMultiple) {
                    return 'LONGTEXT NULL'; // Stores JSON array of UUIDs
                }
                return Schema::TYPE_STRING . '(36) NULL'; // Single UUID

            default:
                // Check for widget types
                if (strpos($type, 'widget_') === 0) {
                    if (strpos($type, 'DatePicker') !== false || strpos($type, 'DateTimePicker') !== false) {
                        return Schema::TYPE_STRING . '(50) NULL';
                    }
                    if (strpos($type, 'SwitchInput') !== false) {
                        return Schema::TYPE_SMALLINT . ' NULL DEFAULT 0';
                    }
                    if (strpos($type, 'ColorInput') !== false) {
                        return Schema::TYPE_STRING . '(20) NULL';
                    }
                }
                return Schema::TYPE_STRING . '(255) NULL';
        }
    }

    /**
     * Gets max length from field validation rules
     *
     * @param array $field The field definition
     * @return int The max length (default 255)
     */
    protected function getMaxLengthFromRules($field)
    {
        if (empty($field['rules'])) {
            return 255;
        }

        foreach ($field['rules'] as $rule) {
            if (isset($rule[0]) && $rule[0] === 'string' && isset($rule[1]['max'])) {
                return (int)$rule[1]['max'];
            }
        }

        return 255;
    }

    /**
     * Calculates the diff between JSON definition and database schema
     *
     * @param string $ctype The content type
     * @param array $definition The element definition
     * @param \yii\db\TableSchema $tableSchema The current table schema
     * @return array Diff with 'add', 'remove', 'modify' keys
     */
    protected function calculateSchemaDiff($ctype, $definition, $tableSchema)
    {
        $jsonColumns = $this->getColumnsFromDefinition($definition);
        $dbColumns = [];

        foreach ($tableSchema->columns as $column) {
            $dbColumns[$column->name] = $column;
        }

        $diff = [
            'add' => [],
            'remove' => [],
            'modify' => [],
        ];

        // Find columns to add (in JSON but not in DB)
        foreach ($jsonColumns as $name => $type) {
            if (!isset($dbColumns[$name])) {
                $diff['add'][$name] = $type;
            }
        }

        // Find columns to remove (in DB but not in JSON)
        // Skip standard columns that might not be in JSON
        $standardColumns = ['uuid', 'created', 'updated', 'created_by', 'updated_by', 'state'];
        foreach ($dbColumns as $name => $column) {
            if (!isset($jsonColumns[$name]) && !in_array($name, $standardColumns)) {
                $diff['remove'][$name] = $this->columnSchemaToString($column);
            }
        }

        // Note: Type modification detection is complex and often not needed
        // as Yii2 migrations handle type changes carefully

        return $diff;
    }

    /**
     * Converts a ColumnSchema to a readable string
     *
     * @param \yii\db\ColumnSchema $column The column schema
     * @return string Column type description
     */
    protected function columnSchemaToString($column)
    {
        $type = $column->dbType;
        if ($column->allowNull) {
            $type .= ' NULL';
        } else {
            $type .= ' NOT NULL';
        }
        if ($column->defaultValue !== null) {
            $type .= ' DEFAULT ' . var_export($column->defaultValue, true);
        }
        return $type;
    }

    /**
     * Shows a summary of schema differences
     *
     * @param array $diff The schema diff
     */
    protected function showDiffSummary($diff)
    {
        if (!empty($diff['add'])) {
            $this->stdout("Columns to ADD:\n", Console::FG_GREEN);
            foreach ($diff['add'] as $name => $type) {
                $this->stdout("  + $name: $type\n");
            }
            $this->stdout("\n");
        }

        if (!empty($diff['remove'])) {
            $this->stdout("Columns to REMOVE:\n", Console::FG_RED);
            foreach ($diff['remove'] as $name => $type) {
                $this->stdout("  - $name: $type\n");
            }
            $this->stdout("\n");
        }

        if (!empty($diff['modify'])) {
            $this->stdout("Columns to MODIFY:\n", Console::FG_YELLOW);
            foreach ($diff['modify'] as $name => $info) {
                $this->stdout("  ~ $name: {$info['from']} -> {$info['to']}\n");
            }
            $this->stdout("\n");
        }
    }

    /**
     * Generates migration content for creating a table
     *
     * @param string $ctype The content type
     * @param array $columns The column definitions
     * @return string Migration class content
     */
    protected function generateCreateTableMigration($ctype, $columns)
    {
        $tableName = $this->useTablePrefix ? '{{%' . $ctype . '}}' : $ctype;
        $className = $this->getMigrationClassName("create_{$ctype}_table");

        // Build column definitions
        $columnDefs = [];
        foreach ($columns as $name => $type) {
            // Convert Schema types to migration method calls
            $columnDefs[] = $this->formatColumnDefinition($name, $type);
        }
        $columnsStr = implode(",\n            ", $columnDefs);

        // Build indexes for foreign key columns (relations)
        $indexes = $this->generateIndexStatements($ctype, $columns);
        $indexesUp = $indexes ? "\n\n        // Indexes for relation columns\n" . implode("\n", $indexes['up']) : '';
        $indexesDown = $indexes ? implode("\n", $indexes['down']) . "\n\n        " : '';

        $namespace = $this->migrationsNamespace ? "namespace {$this->migrationsNamespace};\n\n" : '';

        return <<<PHP
<?php
{$namespace}use yii\\db\\Migration;

/**
 * Creates table for $ctype content type.
 *
 * Generated from JSON element definition.
 */
class $className extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        \$this->createTable('$tableName', [
            $columnsStr
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');$indexesUp
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        {$indexesDown}\$this->dropTable('$tableName');
    }
}
PHP;
    }

    /**
     * Generates migration content for updating a table
     *
     * @param string $ctype The content type
     * @param array $diff The schema diff
     * @return string Migration class content
     */
    protected function generateUpdateTableMigration($ctype, $diff)
    {
        $tableName = $this->useTablePrefix ? '{{%' . $ctype . '}}' : $ctype;
        $className = $this->getMigrationClassName("update_{$ctype}_table");

        $upStatements = [];
        $downStatements = [];

        // Add columns
        foreach ($diff['add'] as $name => $type) {
            $columnDef = $this->formatColumnDefinitionForAlter($type);
            $upStatements[] = "\$this->addColumn('$tableName', '$name', $columnDef);";
            $downStatements[] = "\$this->dropColumn('$tableName', '$name');";
        }

        // Remove columns
        foreach ($diff['remove'] as $name => $type) {
            $upStatements[] = "\$this->dropColumn('$tableName', '$name');";
            $columnDef = $this->formatColumnDefinitionForAlter($type);
            $downStatements[] = "\$this->addColumn('$tableName', '$name', $columnDef);";
        }

        $upStr = implode("\n        ", $upStatements);
        $downStr = implode("\n        ", array_reverse($downStatements));

        $namespace = $this->migrationsNamespace ? "namespace {$this->migrationsNamespace};\n\n" : '';

        return <<<PHP
<?php
{$namespace}use yii\\db\\Migration;

/**
 * Updates table for $ctype content type.
 *
 * Generated from JSON element definition diff.
 */
class $className extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $upStr
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $downStr
    }
}
PHP;
    }

    /**
     * Formats a column definition for createTable
     *
     * @param string $name Column name
     * @param string $type Column type
     * @return string Formatted definition
     */
    protected function formatColumnDefinition($name, $type)
    {
        // Handle special column types
        if (strpos($type, 'PRIMARY KEY') !== false) {
            return "'$name' => \$this->string(36)->notNull(),\n            'PRIMARY KEY ([[uuid]])'";
        }

        // Convert Schema constants to method calls where appropriate
        if (strpos($type, 'string(') !== false) {
            preg_match('/string\((\d+)\)(.*)/', $type, $matches);
            $length = $matches[1] ?? 255;
            $suffix = trim($matches[2] ?? '');
            $method = "\$this->string($length)";
            $method .= $this->parseSuffix($suffix);
            return "'$name' => $method";
        }

        if (strpos($type, 'integer') !== false || strpos($type, 'INT') !== false) {
            $suffix = str_replace(['integer', 'INT'], '', $type);
            $method = "\$this->integer()";
            $method .= $this->parseSuffix($suffix);
            return "'$name' => $method";
        }

        if (strpos($type, 'smallint') !== false || strpos($type, 'SMALLINT') !== false) {
            $suffix = str_replace(['smallint', 'SMALLINT'], '', $type);
            $method = "\$this->smallInteger()";
            $method .= $this->parseSuffix($suffix);
            return "'$name' => $method";
        }

        if (strpos($type, 'text') !== false || strpos($type, 'TEXT') !== false) {
            $method = "\$this->text()";
            if (strpos($type, 'NULL') !== false) {
                $method .= '->null()';
            }
            return "'$name' => $method";
        }

        if (strpos($type, 'LONGTEXT') !== false) {
            return "'$name' => 'LONGTEXT NULL'";
        }

        if (strpos($type, 'JSON') !== false) {
            return "'$name' => \$this->json()";
        }

        // Default: use raw type
        return "'$name' => '$type'";
    }

    /**
     * Parses column type suffix (NULL, NOT NULL, DEFAULT)
     *
     * @param string $suffix The suffix string
     * @return string Method chain
     */
    protected function parseSuffix($suffix)
    {
        $result = '';
        $suffix = trim($suffix);

        if (strpos($suffix, 'NOT NULL') !== false) {
            $result .= '->notNull()';
        } elseif (strpos($suffix, 'NULL') !== false) {
            $result .= '->null()';
        }

        if (preg_match('/DEFAULT\s+(\d+|\'[^\']*\')/', $suffix, $matches)) {
            $default = $matches[1];
            if (is_numeric($default)) {
                $result .= "->defaultValue($default)";
            } else {
                $result .= "->defaultValue($default)";
            }
        }

        return $result;
    }

    /**
     * Formats a column definition for addColumn
     *
     * @param string $type Column type
     * @return string Formatted definition
     */
    protected function formatColumnDefinitionForAlter($type)
    {
        // Similar to formatColumnDefinition but returns just the type expression
        if (strpos($type, 'string(') !== false) {
            preg_match('/string\((\d+)\)(.*)/', $type, $matches);
            $length = $matches[1] ?? 255;
            $suffix = trim($matches[2] ?? '');
            $method = "\$this->string($length)";
            $method .= $this->parseSuffix($suffix);
            return $method;
        }

        if (strpos($type, 'integer') !== false || strpos($type, 'INT') !== false) {
            $suffix = str_replace(['integer', 'INT'], '', $type);
            $method = "\$this->integer()";
            $method .= $this->parseSuffix($suffix);
            return $method;
        }

        if (strpos($type, 'text') !== false) {
            return "\$this->text()->null()";
        }

        if (strpos($type, 'LONGTEXT') !== false) {
            return "'LONGTEXT NULL'";
        }

        return "'$type'";
    }

    /**
     * Generates index statements for relation columns
     *
     * @param string $ctype The content type
     * @param array $columns The column definitions
     * @return array|null Array with 'up' and 'down' statements or null
     */
    protected function generateIndexStatements($ctype, $columns)
    {
        $tableName = $this->useTablePrefix ? '{{%' . $ctype . '}}' : $ctype;
        $up = [];
        $down = [];

        // Add index for state column (commonly used in queries)
        $up[] = "        \$this->createIndex('idx-{$ctype}-state', '$tableName', 'state');";
        $down[] = "        \$this->dropIndex('idx-{$ctype}-state', '$tableName');";

        // Add index for created column (commonly used for sorting)
        $up[] = "        \$this->createIndex('idx-{$ctype}-created', '$tableName', 'created');";
        $down[] = "        \$this->dropIndex('idx-{$ctype}-created', '$tableName');";

        return ['up' => $up, 'down' => $down];
    }

    /**
     * Gets the migration class name
     *
     * @param string $name Base name
     * @return string Full class name
     */
    protected function getMigrationClassName($name)
    {
        $timestamp = date('ymdHis');
        return 'm' . $timestamp . '_' . $name;
    }

    /**
     * Writes a migration file
     *
     * @param string $name Migration name
     * @param string $content Migration content
     * @param bool $confirm Whether to confirm before writing
     * @return int Exit code
     */
    protected function writeMigrationFile($name, $content, $confirm = true)
    {
        $migrationsDir = Yii::getAlias($this->migrationsDir);
        FileHelper::createDirectory($migrationsDir);

        $className = $this->getMigrationClassName($name);
        $filePath = $migrationsDir . '/' . $className . '.php';

        if ($confirm) {
            $this->stdout("Migration file: $filePath\n\n");
            $this->stdout("Preview:\n", Console::FG_CYAN);
            $this->stdout(str_repeat("-", 60) . "\n");
            $this->stdout($content . "\n");
            $this->stdout(str_repeat("-", 60) . "\n\n");

            if (!$this->confirm("Create this migration?")) {
                return ExitCode::OK;
            }
        }

        file_put_contents($filePath, $content);
        $this->stdout("Created: $filePath\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}