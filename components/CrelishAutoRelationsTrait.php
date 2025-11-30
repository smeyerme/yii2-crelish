<?php

namespace giantbits\crelish\components;

use Yii;

/**
 * Trait that automatically generates relation methods from JSON element definitions
 *
 * This trait intercepts relation getter calls (e.g., getCategory()) and property access
 * (e.g., $model->category) to auto-generate relations based on relationSelect and
 * assetConnector field definitions in the element JSON.
 *
 * Usage:
 * ```php
 * class Event extends \yii\db\ActiveRecord
 * {
 *     use \giantbits\crelish\components\CrelishAutoRelationsTrait;
 *
 *     public $ctype = 'event';
 *
 *     public static function tableName(): string
 *     {
 *         return 'event';
 *     }
 *
 *     // No need to manually define getCategory() - it's auto-generated from JSON!
 * }
 * ```
 *
 * The trait will:
 * - Auto-generate hasOne/hasMany relations from relationSelect fields
 * - Auto-generate hasOne/hasMany relations from assetConnector fields
 * - Prioritize explicitly defined methods (backwards compatible)
 * - Cache relation definitions for performance
 */
trait CrelishAutoRelationsTrait
{
    /**
     * @var array|null Cached relation definitions from JSON
     */
    private $_autoRelationDefinitions = null;

    /**
     * Override __call to intercept relation getter calls
     *
     * @param string $name Method name
     * @param array $params Method parameters
     * @return mixed
     */
    public function __call($name, $params)
    {
        // Check if this is a relation getter (e.g., getCategory)
        if (strncmp($name, 'get', 3) === 0 && strlen($name) > 3) {
            $relationName = lcfirst(substr($name, 3));
            $relation = $this->getAutoRelation($relationName);

            if ($relation !== null) {
                return $relation;
            }
        }

        return parent::__call($name, $params);
    }

    /**
     * Override __get to intercept relation property access
     *
     * @param string $name Property name
     * @return mixed
     */
    public function __get($name)
    {
        // First try the parent implementation (explicit relations, attributes, etc.)
        try {
            return parent::__get($name);
        } catch (\yii\base\UnknownPropertyException $e) {
            // If parent doesn't have this property, try auto-relation
            $relation = $this->getAutoRelation($name);
            if ($relation !== null) {
                // Cache the relation result in related records
                return $this->getRelatedRecords()[$name] ?? $relation->findFor($name, $this);
            }

            // Re-throw if we can't handle it
            throw $e;
        }
    }

    /**
     * Check if a property can be read (including auto-relations)
     *
     * @param string $name Property name
     * @param bool $checkVars Whether to check member variables
     * @return bool
     */
    public function canGetProperty($name, $checkVars = true)
    {
        if (parent::canGetProperty($name, $checkVars)) {
            return true;
        }

        // Check if it's an auto-relation
        $definitions = $this->getAutoRelationDefinitions();
        return isset($definitions[$name]);
    }

    /**
     * Get an auto-generated relation query
     *
     * @param string $relationName The relation name (e.g., 'category')
     * @return \yii\db\ActiveQuery|null The relation query or null if not found
     */
    protected function getAutoRelation(string $relationName): ?\yii\db\ActiveQuery
    {
        $definitions = $this->getAutoRelationDefinitions();

        if (!isset($definitions[$relationName])) {
            return null;
        }

        $def = $definitions[$relationName];
        $relatedModelClass = $def['modelClass'];
        $foreignKey = $def['foreignKey'];
        $isMultiple = $def['multiple'];

        if ($isMultiple) {
            // For multiple relations, we need to handle JSON-stored arrays
            // This is more complex and typically requires a junction approach
            // For now, return a query that will need post-processing
            return $this->hasMany($relatedModelClass, ['uuid' => $foreignKey]);
        } else {
            return $this->hasOne($relatedModelClass, ['uuid' => $foreignKey]);
        }
    }

    /**
     * Get cached relation definitions from the element JSON
     *
     * @return array Relation definitions [relationName => ['modelClass' => ..., 'foreignKey' => ..., 'multiple' => ...]]
     */
    protected function getAutoRelationDefinitions(): array
    {
        if ($this->_autoRelationDefinitions !== null) {
            return $this->_autoRelationDefinitions;
        }

        $this->_autoRelationDefinitions = [];

        // Get ctype from the model
        $ctype = $this->ctype ?? null;
        if (empty($ctype)) {
            return $this->_autoRelationDefinitions;
        }

        // Load element definition
        $definition = CrelishDynamicModel::loadElementDefinition($ctype);
        if (empty($definition) || empty($definition->fields)) {
            return $this->_autoRelationDefinitions;
        }

        foreach ($definition->fields as $field) {
            if (!isset($field->type) || !isset($field->key)) {
                continue;
            }

            // Handle relationSelect fields
            if ($field->type === 'relationSelect' && isset($field->config->ctype)) {
                $relatedCtype = $field->config->ctype;
                $isMultiple = isset($field->config->multiple) && $field->config->multiple === true;

                try {
                    $relatedModelClass = CrelishModelResolver::getModelClass($relatedCtype);

                    // Add main relation (using field key)
                    $this->_autoRelationDefinitions[$field->key] = [
                        'modelClass' => $relatedModelClass,
                        'foreignKey' => $field->key,
                        'multiple' => $isMultiple,
                        'type' => 'relationSelect',
                    ];

                    // Also add relation by ctype name for convenience (e.g., getEventcategory())
                    // Only if it doesn't conflict with the field key
                    if ($relatedCtype !== $field->key) {
                        $this->_autoRelationDefinitions[$relatedCtype] = [
                            'modelClass' => $relatedModelClass,
                            'foreignKey' => $field->key,
                            'multiple' => $isMultiple,
                            'type' => 'relationSelect',
                        ];
                    }
                } catch (\Exception $e) {
                    Yii::warning("Could not resolve model for relation '$relatedCtype': " . $e->getMessage(), __METHOD__);
                }
            }

            // Handle assetConnector fields (links to Asset model)
            if ($field->type === 'assetConnector') {
                $isMultiple = isset($field->config->multiple) && $field->config->multiple === true;

                try {
                    $assetModelClass = CrelishModelResolver::getModelClass('asset');

                    // Create a descriptive relation name (e.g., logoAsset for 'logo' field)
                    $relationName = $field->key . 'Asset';

                    $this->_autoRelationDefinitions[$relationName] = [
                        'modelClass' => $assetModelClass,
                        'foreignKey' => $field->key,
                        'multiple' => $isMultiple,
                        'type' => 'assetConnector',
                    ];

                    // Also allow direct access via field key if not already defined
                    if (!isset($this->_autoRelationDefinitions[$field->key])) {
                        $this->_autoRelationDefinitions[$field->key . 'Image'] = [
                            'modelClass' => $assetModelClass,
                            'foreignKey' => $field->key,
                            'multiple' => $isMultiple,
                            'type' => 'assetConnector',
                        ];
                    }
                } catch (\Exception $e) {
                    Yii::warning("Could not resolve Asset model for field '{$field->key}': " . $e->getMessage(), __METHOD__);
                }
            }
        }

        return $this->_autoRelationDefinitions;
    }

    /**
     * Get list of auto-generated relation names
     *
     * @return array List of relation names
     */
    public function getAutoRelationNames(): array
    {
        return array_keys($this->getAutoRelationDefinitions());
    }

    /**
     * Check if a relation is auto-generated
     *
     * @param string $name Relation name
     * @return bool
     */
    public function isAutoRelation(string $name): bool
    {
        $definitions = $this->getAutoRelationDefinitions();
        return isset($definitions[$name]);
    }

    /**
     * Clear the cached relation definitions
     * Useful for testing or when element definitions change
     */
    public function clearAutoRelationCache(): void
    {
        $this->_autoRelationDefinitions = null;
    }
}
