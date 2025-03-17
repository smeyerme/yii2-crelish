<?php

namespace giantbits\crelish\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use yii\helpers\Inflector;
use yii\helpers\Json;
use yii\db\Schema;

/**
 * Manages content type generation from JSON definitions.
 */
class ContentTypeController extends Controller
{
    /**
     * @var string The directory where element definitions are stored
     */
    public $definitionsDir = '@app/workspace/elements';
    
    /**
     * @var string The directory where model classes are stored
     */
    public $modelsDir = '@app/workspace/models';
    
    /**
     * @var string The namespace for model classes
     */
    public $modelsNamespace = 'app\\workspace\\models';
    
    /**
     * Generates a model class and database table from a JSON element definition
     * 
     * @param string $elementType The name of the element type (e.g., 'page', 'boardgame')
     * @return int Exit code
     */
    public function actionGenerate($elementType)
    {
        $this->stdout("Generating content type: $elementType\n", Console::FG_GREEN);
        
        // Load the element definition
        $definitionFile = Yii::getAlias($this->definitionsDir . "/$elementType.json");
        if (!file_exists($definitionFile)) {
            $this->stderr("Element definition file not found: $definitionFile\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        
        $definition = Json::decode(file_get_contents($definitionFile), true);
        if (!is_array($definition)) {
            $this->stderr("Invalid JSON in element definition file\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        
        // Create the database table
        if (!$this->createTable($elementType, $definition)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        
        // Generate the model class
        if (!$this->generateModel($elementType, $definition)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        
        $this->stdout("Content type '$elementType' generated successfully!\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
    
    /**
     * Creates a database table for the element type
     * 
     * @param string $elementType The name of the element type
     * @param array $definition The element definition
     * @return bool Whether the operation was successful
     */
    protected function createTable($elementType, $definition)
    {
        $this->stdout("Creating database table for '$elementType'...\n");
        
        $tableName = $elementType;
        $columns = [
            'uuid' => Schema::TYPE_STRING . '(36) NOT NULL PRIMARY KEY',
            'created' => Schema::TYPE_TIMESTAMP . ' NULL DEFAULT CURRENT_TIMESTAMP',
            'updated' => Schema::TYPE_TIMESTAMP . ' NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'created_by' => Schema::TYPE_STRING . '(36) DEFAULT NULL',
            'updated_by' => Schema::TYPE_STRING . '(36) DEFAULT NULL',
            'state' => Schema::TYPE_SMALLINT . ' NOT NULL DEFAULT 1',
        ];
        
        // Add columns for each field in the definition
        foreach ($definition['fields'] as $field) {
            if (isset($field['key']) && $field['key'] !== 'uuid' && $field['key'] !== 'created' && 
                $field['key'] !== 'updated' && $field['key'] !== 'state' && 
                $field['key'] !== 'created_by' && $field['key'] !== 'updated_by') {
                
                $columnType = $this->mapFieldTypeToColumnType($field);
                $columns[$field['key']] = $columnType;
            }
        }
        
        try {
            $db = Yii::$app->db;
            
            // Check if table already exists
            $tableExists = $db->getTableSchema($tableName, true) !== null;
            if ($tableExists) {
                $this->stdout("Table '$tableName' already exists. Skipping table creation.\n", Console::FG_YELLOW);
                return true;
            }
            
            // Create the table
            $db->createCommand()->createTable($tableName, $columns)->execute();
            $this->stdout("Table '$tableName' created successfully.\n", Console::FG_GREEN);
            return true;
        } catch (\Exception $e) {
            $this->stderr("Error creating table: " . $e->getMessage() . "\n", Console::FG_RED);
            return false;
        }
    }
    
    /**
     * Maps a field type from the JSON definition to a database column type
     * 
     * @param array $field The field definition
     * @return string The database column type
     */
    protected function mapFieldTypeToColumnType($field)
    {
        $type = $field['type'] ?? 'textInput';
        $transform = $field['transform'] ?? null;
        
        // Check for JSON fields
        if ($transform === 'json') {
            return Schema::TYPE_JSON;
        }
        
        // Map field types to column types
        switch ($type) {
            case 'textInput':
                // Check for max length in rules
                $maxLength = 255;
                if (isset($field['rules'])) {
                    foreach ($field['rules'] as $rule) {
                        if (isset($rule[0]) && $rule[0] === 'string' && isset($rule[1]['max'])) {
                            $maxLength = $rule[1]['max'];
                            break;
                        }
                    }
                }
                
                if ($maxLength > 255) {
                    return Schema::TYPE_TEXT;
                }
                return Schema::TYPE_STRING . "($maxLength)";
                
            case 'widget_\\brussens\\yii2\\extensions\\trumbowyg\\TrumbowygWidget':
            case 'textArea':
                return Schema::TYPE_TEXT;
                
            case 'numberInput':
                return Schema::TYPE_INTEGER;
                
            case 'dropDownList':
                return Schema::TYPE_STRING . '(255)';
                
            case 'checkboxList':
            case 'matrixConnector':
            case 'widgetConnector':
            case 'jsonEditor':
                return 'longtext';
                
            case 'relationSelect':
            case 'assetConnector':
                // For relations, store the UUID of the related record
                return Schema::TYPE_STRING . '(36)';
                
            default:
                return Schema::TYPE_STRING . '(255)';
        }
    }
    
    /**
     * Generates a model class for the element type
     * 
     * @param string $elementType The name of the element type
     * @param array $definition The element definition
     * @return bool Whether the operation was successful
     */
    protected function generateModel($elementType, $definition)
    {
        $this->stdout("Generating model class for '$elementType'...\n");
        
        $className = Inflector::id2camel($elementType, '_');
        $modelFile = Yii::getAlias($this->modelsDir . "/$className.php");
        
        // Check if model already exists
        if (file_exists($modelFile)) {
            $this->stdout("Model class '$className' already exists. Skipping model generation.\n", Console::FG_YELLOW);
            return true;
        }
        
        // Ensure the models directory exists
        FileHelper::createDirectory(dirname($modelFile));
        
        // Collect relation fields
        $relationFields = [];
        
        foreach ($definition['fields'] as $field) {
            if (isset($field['type'])) {
                // Handle relationSelect fields
                if ($field['type'] === 'relationSelect' && isset($field['config']['ctype'])) {
                    $relationFields[$field['key']] = $field['config']['ctype'];
                }
                
                // Handle assetConnector fields - always relate to the asset content type
                if ($field['type'] === 'assetConnector') {
                    $relationFields[$field['key']] = 'asset';
                }
            }
        }
        
        // Generate the model class content
        $content = $this->generateModelContent($className, $elementType, $relationFields);
        
        try {
            file_put_contents($modelFile, $content);
            $this->stdout("Model class '$className' generated successfully.\n", Console::FG_GREEN);
            return true;
        } catch (\Exception $e) {
            $this->stderr("Error generating model class: " . $e->getMessage() . "\n", Console::FG_RED);
            return false;
        }
    }
    
    /**
     * Generates the content of the model class
     * 
     * @param string $className The name of the model class
     * @param string $elementType The name of the element type
     * @param array $relationFields Array of relation fields with their target content types
     * @return string The content of the model class
     */
    protected function generateModelContent($className, $elementType, $relationFields)
    {
        $namespace = $this->modelsNamespace;
        $relationsCode = '';
        
        // Generate relation methods
        foreach ($relationFields as $field => $targetType) {
            $relationName = Inflector::id2camel($field, '_');
            $targetClass = Inflector::id2camel($targetType, '_');
            
            $relationsCode .= <<<EOT
    
    /**
     * Get related $targetType
     * @return \yii\db\ActiveQuery
     */
    public function get$relationName()
    {
        return \$this->hasOne($targetClass::class, ['uuid' => '$field']);
    }
EOT;
        }
        
        // Generate the complete model class
        return <<<EOT
<?php

namespace $namespace;

class $className extends \yii\db\ActiveRecord
{
    /**
     * @var string Content type
     */
    public \$ctype = '$elementType';
    
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '$elementType';
    }
    
    /**
     * {@inheritdoc}
     */
    public function behaviors(): array
    {
        return [
            \giantbits\crelish\components\CrelishTranslationBehavior::class
        ];
    }$relationsCode
}
EOT;
    }
    
    /**
     * Lists all available element definitions
     * 
     * @return int Exit code
     */
    public function actionList()
    {
        $this->stdout("Available element definitions:\n", Console::FG_GREEN);
        
        $definitionsDir = Yii::getAlias($this->definitionsDir);
        $files = FileHelper::findFiles($definitionsDir, ['only' => ['*.json']]);
        
        if (empty($files)) {
            $this->stdout("No element definitions found in $definitionsDir\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }
        
        foreach ($files as $file) {
            $elementType = pathinfo($file, PATHINFO_FILENAME);
            $this->stdout("- $elementType\n");
        }
        
        return ExitCode::OK;
    }
} 