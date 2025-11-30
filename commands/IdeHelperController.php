<?php

namespace giantbits\crelish\commands;

use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishModelResolver;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use yii\helpers\Inflector;

/**
 * Generates IDE helper annotations for Crelish models.
 *
 * This command scans element definitions and generates PHPDoc annotations
 * for better IDE autocompletion support.
 *
 * Usage:
 *   php yii crelish/ide-helper/generate          # Generate helpers for all models
 *   php yii crelish/ide-helper/generate event    # Generate helper for specific model
 *   php yii crelish/ide-helper/models            # List all models with their properties
 */
class IdeHelperController extends Controller
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
     * @var bool Whether to update model files in-place (add PHPDoc to existing files)
     */
    public $updateInPlace = false;

    /**
     * @var string Output file for IDE helper stubs (when not updating in-place)
     */
    public $outputFile = '@app/workspace/_ide_helper_models.php';

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'updateInPlace',
            'outputFile',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'u' => 'updateInPlace',
            'o' => 'outputFile',
        ]);
    }

    /**
     * Generates IDE helper annotations for Crelish models
     *
     * @param string|null $ctype Optional specific content type to generate helpers for
     * @return int Exit code
     */
    public function actionGenerate($ctype = null)
    {
        $this->stdout("Generating IDE helpers for Crelish models...\n\n", Console::FG_GREEN);

        $definitionsDir = Yii::getAlias($this->definitionsDir);

        if ($ctype !== null) {
            // Generate for specific content type
            $definitionFile = $definitionsDir . "/$ctype.json";
            if (!file_exists($definitionFile)) {
                $this->stderr("Element definition not found: $ctype.json\n", Console::FG_RED);
                return ExitCode::DATAERR;
            }
            $ctypes = [$ctype];
        } else {
            // Find all element definitions
            $files = FileHelper::findFiles($definitionsDir, ['only' => ['*.json']]);
            $ctypes = array_map(function($file) {
                return pathinfo($file, PATHINFO_FILENAME);
            }, $files);
        }

        if (empty($ctypes)) {
            $this->stdout("No element definitions found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $helpers = [];

        foreach ($ctypes as $contentType) {
            $helper = $this->generateHelperForType($contentType);
            if ($helper !== null) {
                $helpers[$contentType] = $helper;
            }
        }

        if ($this->updateInPlace) {
            // Update model files directly
            foreach ($helpers as $contentType => $helper) {
                $this->updateModelFile($contentType, $helper);
            }
        } else {
            // Write to a single IDE helper file
            $this->writeHelperFile($helpers);
        }

        $this->stdout("\nIDE helpers generated successfully!\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Lists all models with their properties and relations
     *
     * @return int Exit code
     */
    public function actionModels()
    {
        $this->stdout("Crelish Models Overview\n", Console::FG_GREEN);
        $this->stdout(str_repeat("=", 60) . "\n\n");

        $definitionsDir = Yii::getAlias($this->definitionsDir);
        $files = FileHelper::findFiles($definitionsDir, ['only' => ['*.json']]);

        foreach ($files as $file) {
            $ctype = pathinfo($file, PATHINFO_FILENAME);
            $definition = CrelishDynamicModel::loadElementDefinition($ctype);

            if (empty($definition)) {
                continue;
            }

            // Get model class
            $modelClass = 'Not found';
            try {
                $modelClass = CrelishModelResolver::getModelClass($ctype);
            } catch (\Exception $e) {
                // Model doesn't exist
            }

            $this->stdout("$ctype\n", Console::FG_CYAN, Console::BOLD);
            $this->stdout("  Class: $modelClass\n");
            $this->stdout("  Storage: " . ($definition->storage ?? 'json') . "\n");

            // List properties
            $this->stdout("  Properties:\n", Console::FG_YELLOW);
            if (!empty($definition->fields)) {
                foreach ($definition->fields as $field) {
                    $type = $this->getPhpTypeForField($field);
                    $this->stdout("    - {$field->key}: $type\n");
                }
            }

            // List relations
            $relations = $this->getRelationsForDefinition($definition);
            if (!empty($relations)) {
                $this->stdout("  Relations:\n", Console::FG_YELLOW);
                foreach ($relations as $name => $info) {
                    $relationType = $info['multiple'] ? 'hasMany' : 'hasOne';
                    $this->stdout("    - $name ($relationType -> {$info['targetClass']})\n");
                }
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Generates IDE helper content for a specific content type
     *
     * @param string $ctype The content type
     * @return array|null Helper data or null if failed
     */
    protected function generateHelperForType($ctype)
    {
        $definition = CrelishDynamicModel::loadElementDefinition($ctype);

        if (empty($definition)) {
            $this->stderr("Could not load definition for: $ctype\n", Console::FG_YELLOW);
            return null;
        }

        // Try to get the model class
        $modelClass = null;
        try {
            $modelClass = CrelishModelResolver::getModelClass($ctype);
        } catch (\Exception $e) {
            $this->stderr("No model class found for: $ctype\n", Console::FG_YELLOW);
            return null;
        }

        $this->stdout("Processing: $ctype -> $modelClass\n");

        // Generate property annotations
        $properties = $this->generatePropertyAnnotations($definition);

        // Generate relation annotations
        $relations = $this->generateRelationAnnotations($definition, $modelClass);

        // Generate method annotations (for query methods)
        $methods = $this->generateMethodAnnotations($ctype, $modelClass);

        return [
            'ctype' => $ctype,
            'modelClass' => $modelClass,
            'properties' => $properties,
            'relations' => $relations,
            'methods' => $methods,
            'definition' => $definition,
        ];
    }

    /**
     * Generates @property annotations for fields
     *
     * @param object $definition The element definition
     * @return array Property annotations
     */
    protected function generatePropertyAnnotations($definition)
    {
        $properties = [];

        // Standard fields that all models have
        $properties['uuid'] = ['type' => 'string', 'description' => 'Unique identifier'];
        $properties['state'] = ['type' => 'int', 'description' => 'Publishing state (0=Offline, 1=Draft, 2=Online, 3=Archived)'];
        $properties['created'] = ['type' => 'int|null', 'description' => 'Creation timestamp'];
        $properties['updated'] = ['type' => 'int|null', 'description' => 'Last update timestamp'];
        $properties['created_by'] = ['type' => 'string|null', 'description' => 'Creator UUID'];
        $properties['updated_by'] = ['type' => 'string|null', 'description' => 'Last editor UUID'];

        if (!empty($definition->fields)) {
            foreach ($definition->fields as $field) {
                // Skip standard fields already added
                if (in_array($field->key, ['uuid', 'state', 'created', 'updated', 'created_by', 'updated_by'])) {
                    continue;
                }

                $phpType = $this->getPhpTypeForField($field);
                $description = $field->label ?? ucfirst(str_replace('_', ' ', $field->key));

                // Add translatable note
                if (!empty($field->translatable)) {
                    $description .= ' (translatable)';
                }

                $properties[$field->key] = [
                    'type' => $phpType,
                    'description' => $description,
                ];
            }
        }

        return $properties;
    }

    /**
     * Generates @property-read annotations for relations
     *
     * @param object $definition The element definition
     * @param string $modelClass The model class name
     * @return array Relation annotations
     */
    protected function generateRelationAnnotations($definition, $modelClass)
    {
        $relations = [];

        // Get relations from JSON definition
        $definedRelations = $this->getRelationsForDefinition($definition);

        foreach ($definedRelations as $name => $info) {
            $targetClass = $info['targetClass'];
            $isMultiple = $info['multiple'];

            if ($isMultiple) {
                $type = $targetClass . '[]';
                $description = "Related {$info['targetType']} records";
            } else {
                $type = $targetClass . '|null';
                $description = "Related {$info['targetType']}";
            }

            $relations[$name] = [
                'type' => $type,
                'description' => $description,
                'isMultiple' => $isMultiple,
            ];
        }

        // Check for explicit relations in the model class (if it exists and we can reflect it)
        if (class_exists($modelClass)) {
            try {
                $reflection = new \ReflectionClass($modelClass);
                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (strpos($method->getName(), 'get') === 0 && $method->getNumberOfParameters() === 0) {
                        $relationName = lcfirst(substr($method->getName(), 3));

                        // Skip if already defined from JSON
                        if (isset($relations[$relationName])) {
                            continue;
                        }

                        // Check if return type hints at a relation
                        $returnType = $method->getReturnType();
                        if ($returnType && $returnType->getName() === 'yii\db\ActiveQuery') {
                            // Try to get info from docblock
                            $docComment = $method->getDocComment();
                            $description = "Related record";

                            if ($docComment && preg_match('/@return\s+\\\\yii\\\\db\\\\ActiveQuery\s*(.*)/', $docComment, $matches)) {
                                $description = trim($matches[1]) ?: $description;
                            }

                            $relations[$relationName] = [
                                'type' => 'mixed', // Can't determine without executing
                                'description' => $description . ' (explicit relation)',
                                'isMultiple' => false,
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Reflection failed, continue without explicit relations
            }
        }

        return $relations;
    }

    /**
     * Generates @method annotations for common query methods
     *
     * @param string $ctype The content type
     * @param string $modelClass The model class name
     * @return array Method annotations
     */
    protected function generateMethodAnnotations($ctype, $modelClass)
    {
        $shortClass = substr($modelClass, strrpos($modelClass, '\\') + 1);

        return [
            'find' => [
                'static' => true,
                'return' => '\yii\db\ActiveQuery',
                'params' => [],
                'description' => 'Creates an ActiveQuery instance for querying ' . $shortClass,
            ],
            'findOne' => [
                'static' => true,
                'return' => 'static|null',
                'params' => ['$condition' => 'mixed'],
                'description' => 'Finds a single ' . $shortClass . ' by condition',
            ],
            'findAll' => [
                'static' => true,
                'return' => 'static[]',
                'params' => ['$condition' => 'mixed'],
                'description' => 'Finds all ' . $shortClass . ' records by condition',
            ],
        ];
    }

    /**
     * Gets relations defined in an element definition
     *
     * @param object $definition The element definition
     * @return array Relations with their info
     */
    protected function getRelationsForDefinition($definition)
    {
        $relations = [];

        if (empty($definition->fields)) {
            return $relations;
        }

        foreach ($definition->fields as $field) {
            if (!isset($field->type) || !isset($field->key)) {
                continue;
            }

            // Handle relationSelect fields
            if ($field->type === 'relationSelect' && isset($field->config->ctype)) {
                $targetCtype = $field->config->ctype;
                $isMultiple = !empty($field->config->multiple);

                try {
                    $targetClass = CrelishModelResolver::getModelClass($targetCtype);
                } catch (\Exception $e) {
                    $targetClass = $this->modelsNamespace . '\\' . Inflector::id2camel($targetCtype, '_');
                }

                // Relation by field key
                $relations[$field->key] = [
                    'targetType' => $targetCtype,
                    'targetClass' => $targetClass,
                    'multiple' => $isMultiple,
                    'fieldKey' => $field->key,
                ];

                // Also add relation by ctype name if different
                if ($targetCtype !== $field->key) {
                    $relations[$targetCtype] = [
                        'targetType' => $targetCtype,
                        'targetClass' => $targetClass,
                        'multiple' => $isMultiple,
                        'fieldKey' => $field->key,
                    ];
                }
            }

            // Handle assetConnector fields
            if ($field->type === 'assetConnector') {
                $isMultiple = !empty($field->config->multiple);

                try {
                    $assetClass = CrelishModelResolver::getModelClass('asset');
                } catch (\Exception $e) {
                    $assetClass = $this->modelsNamespace . '\\Asset';
                }

                // Add relation as fieldKey + 'Asset'
                $relationName = $field->key . 'Asset';
                $relations[$relationName] = [
                    'targetType' => 'asset',
                    'targetClass' => $assetClass,
                    'multiple' => $isMultiple,
                    'fieldKey' => $field->key,
                ];
            }
        }

        return $relations;
    }

    /**
     * Maps a field definition to a PHP type
     *
     * @param object $field The field definition
     * @return string The PHP type
     */
    protected function getPhpTypeForField($field)
    {
        $type = $field->type ?? 'textInput';
        $transform = $field->transform ?? null;

        // Check transform first
        if ($transform === 'json') {
            return 'array|null';
        }
        if ($transform === 'date' || $transform === 'datetime') {
            return 'int|null';
        }

        // Map field types
        switch ($type) {
            case 'textInput':
            case 'passwordInput':
            case 'dropDownList':
            case 'relationSelect':
            case 'assetConnector':
                return 'string|null';

            case 'textarea':
            case 'widget_\\brussens\\yii2\\extensions\\trumbowyg\\TrumbowygWidget':
                return 'string|null';

            case 'numberInput':
                return 'int|null';

            case 'checkboxList':
            case 'matrixConnector':
            case 'widgetConnector':
            case 'jsonEditor':
                return 'array|null';

            default:
                // Check for widget types
                if (strpos($type, 'widget_') === 0) {
                    if (strpos($type, 'DatePicker') !== false || strpos($type, 'DateTimePicker') !== false) {
                        return 'string|null';
                    }
                    if (strpos($type, 'SwitchInput') !== false) {
                        return 'int|bool|null';
                    }
                }
                return 'mixed';
        }
    }

    /**
     * Writes the IDE helper file
     *
     * @param array $helpers The helper data for all models
     */
    protected function writeHelperFile($helpers)
    {
        $outputFile = Yii::getAlias($this->outputFile);

        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * IDE Helper file for Crelish CMS models\n";
        $content .= " * \n";
        $content .= " * This file provides IDE autocompletion support for Crelish models.\n";
        $content .= " * It is auto-generated by: php yii crelish/ide-helper/generate\n";
        $content .= " * \n";
        $content .= " * @generated " . date('Y-m-d H:i:s') . "\n";
        $content .= " */\n\n";
        $content .= "namespace {$this->modelsNamespace};\n\n";

        foreach ($helpers as $ctype => $helper) {
            $content .= $this->generateClassStub($helper);
            $content .= "\n";
        }

        // Ensure directory exists
        FileHelper::createDirectory(dirname($outputFile));

        file_put_contents($outputFile, $content);
        $this->stdout("Written to: $outputFile\n", Console::FG_CYAN);
    }

    /**
     * Generates a class stub for IDE helpers
     *
     * @param array $helper The helper data
     * @return string The class stub
     */
    protected function generateClassStub($helper)
    {
        $modelClass = $helper['modelClass'];
        $shortClass = substr($modelClass, strrpos($modelClass, '\\') + 1);

        $docBlock = "/**\n";
        $docBlock .= " * {$shortClass} model - {$helper['ctype']} content type\n";
        $docBlock .= " *\n";

        // Add property annotations
        foreach ($helper['properties'] as $name => $info) {
            $docBlock .= " * @property {$info['type']} \${$name} {$info['description']}\n";
        }

        // Add relation annotations (property-read)
        foreach ($helper['relations'] as $name => $info) {
            $docBlock .= " * @property-read {$info['type']} \${$name} {$info['description']}\n";
        }

        // Add method annotations
        foreach ($helper['methods'] as $name => $info) {
            $params = [];
            foreach ($info['params'] as $paramName => $paramType) {
                $params[] = "$paramType $paramName";
            }
            $paramsStr = implode(', ', $params);
            $static = !empty($info['static']) ? 'static ' : '';
            $docBlock .= " * @method {$static}{$info['return']} {$name}({$paramsStr}) {$info['description']}\n";
        }

        $docBlock .= " */\n";
        $docBlock .= "class {$shortClass} extends \\yii\\db\\ActiveRecord {}\n";

        return $docBlock;
    }

    /**
     * Updates a model file with PHPDoc annotations
     *
     * @param string $ctype The content type
     * @param array $helper The helper data
     */
    protected function updateModelFile($ctype, $helper)
    {
        $modelClass = $helper['modelClass'];

        // Find the model file
        try {
            $reflection = new \ReflectionClass($modelClass);
            $filePath = $reflection->getFileName();
        } catch (\Exception $e) {
            $this->stderr("Could not find file for: $modelClass\n", Console::FG_YELLOW);
            return;
        }

        if (!$filePath || !file_exists($filePath)) {
            $this->stderr("File not found for: $modelClass\n", Console::FG_YELLOW);
            return;
        }

        $content = file_get_contents($filePath);
        $shortClass = substr($modelClass, strrpos($modelClass, '\\') + 1);

        // Generate the new PHPDoc block
        $newDocBlock = $this->generateDocBlock($helper);

        // Check if class already has a docblock
        $pattern = '/\/\*\*[\s\S]*?\*\/\s*\nclass\s+' . preg_quote($shortClass, '/') . '/';

        if (preg_match($pattern, $content)) {
            // Replace existing docblock
            $content = preg_replace($pattern, $newDocBlock . "\nclass " . $shortClass, $content);
        } else {
            // Add new docblock before class declaration
            $content = preg_replace(
                '/class\s+' . preg_quote($shortClass, '/') . '/',
                $newDocBlock . "\nclass " . $shortClass,
                $content
            );
        }

        file_put_contents($filePath, $content);
        $this->stdout("Updated: $filePath\n", Console::FG_CYAN);
    }

    /**
     * Generates a PHPDoc block for a model
     *
     * @param array $helper The helper data
     * @return string The PHPDoc block
     */
    protected function generateDocBlock($helper)
    {
        $shortClass = substr($helper['modelClass'], strrpos($helper['modelClass'], '\\') + 1);

        $docBlock = "/**\n";
        $docBlock .= " * {$shortClass} model for {$helper['ctype']} content type\n";
        $docBlock .= " *\n";

        // Add property annotations
        foreach ($helper['properties'] as $name => $info) {
            $docBlock .= " * @property {$info['type']} \${$name} {$info['description']}\n";
        }

        if (!empty($helper['relations'])) {
            $docBlock .= " *\n";
            // Add relation annotations
            foreach ($helper['relations'] as $name => $info) {
                $docBlock .= " * @property-read {$info['type']} \${$name} {$info['description']}\n";
            }
        }

        $docBlock .= " */";

        return $docBlock;
    }
}
