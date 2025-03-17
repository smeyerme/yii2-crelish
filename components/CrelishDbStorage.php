<?php

namespace giantbits\crelish\components;

use Yii;
use yii\data\ActiveDataProvider;
use yii\data\DataProviderInterface;
use yii\db\ActiveRecord;
use yii\helpers\Inflector;
use yii\helpers\Json;

/**
 * Database storage implementation for Crelish CMS
 */
class CrelishDbStorage implements CrelishDataStorage
{
    /**
     * Find a single record by UUID
     * 
     * @param string $ctype Content type
     * @param string $uuid UUID of the record
     * @return array|null The record data or null if not found
     */
    public function findOne(string $ctype, string $uuid): ?array
    {
        $model = $this->getModelClass($ctype)::findOne($uuid);
        
        if ($model === null) {
            return null;
        }
        
        return $model->attributes;
    }
    
    /**
     * Find all records of a given content type
     * 
     * @param string $ctype Content type
     * @param array $filter Optional filter criteria
     * @param array $sort Optional sorting criteria
     * @param int $limit Optional limit
     * @return array Array of records
     */
    public function findAll(string $ctype, array $filter = [], array $sort = [], int $limit = 0): array
    {
        $query = $this->getModelClass($ctype)::find();
        
        // Apply filters
        if (!empty($filter)) {
            foreach ($filter as $attribute => $value) {
                if ($attribute === 'freesearch') {
                    // Handle freesearch by searching across all fields
                    $searchFragments = explode(" ", trim($value));
                    $orConditions = ['or'];
                    
                    // Get the table schema to find all searchable columns
                    $modelClass = $this->getModelClass($ctype);
                    $tableSchema = $modelClass::getTableSchema();
                    
                    foreach ($tableSchema->columns as $column) {
                        // Only search in string/text columns
                        if (in_array($column->type, ['string', 'text', 'char'])) {
                            foreach ($searchFragments as $fragment) {
                                $orConditions[] = ['like', $column->name, $fragment];
                            }
                        }
                    }
                    
                    $query->andWhere($orConditions);
                } elseif (is_array($value) && isset($value[0]) && $value[0] === 'strict') {
                    $query->andWhere([$attribute => $value[1]]);
                } else {
                    $query->andWhere(['like', $attribute, $value]);
                }
            }
        }
        
        // Apply sorting - handle both array format and defaultOrder format
        if (!empty($sort)) {
            if (isset($sort['defaultOrder'])) {
                // If defaultOrder is provided, use it directly
                $query->orderBy($sort['defaultOrder']);
            } else {
                // Otherwise use the sort array as is
                $query->orderBy($sort);
            }
        }
        
        // Apply limit
        if ($limit > 0) {
            $query->limit($limit);
        }
        
        $models = $query->all();
        $result = [];
        
        foreach ($models as $model) {
            $result[] = $model->attributes;
        }
        
        return $result;
    }
    
    /**
     * Save a record
     * 
     * @param string $ctype Content type
     * @param array $data Record data
     * @param bool $isNew Whether this is a new record
     * @return bool Whether the save was successful
     */
    public function save(string $ctype, array $data, bool $isNew = true): bool
    {
        $modelClass = $this->getModelClass($ctype);
        
        if ($isNew) {
            $model = new $modelClass();
            
            if (empty($data['uuid'])) {
                $data['uuid'] = $this->generateUuid();
            }
            
            if (empty($data['created'])) {
                $data['created'] = time();
            }
        } else {
            $model = $modelClass::findOne(['uuid' => $data['uuid']]);
            
            if (!$model) {
                return false;
            }
            
            $data['updated'] = time();
        }
        
        // Get element definition to check field types
        $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);
        
        // Set model attributes
        foreach ($data as $key => $value) {
            // Skip if the model doesn't have this attribute
            if (!$model->hasAttribute($key)) {
                continue;
            }
            
            // Handle special field types
            if ($elementDefinition && property_exists($elementDefinition, 'fields')) {
                $fieldDef = null;
                
                // Find the field definition
                foreach ($elementDefinition->fields as $field) {
                    if (property_exists($field, 'key') && $field->key === $key) {
                        $fieldDef = $field;
                        break;
                    }
                }
                
                if ($fieldDef && property_exists($fieldDef, 'type')) {
                    // Handle assetConnector - store only the UUID
                    if ($fieldDef->type === 'assetConnector' && is_array($value) && isset($value['uuid'])) {
                        $model->$key = $value['uuid'];
                        continue;
                    }
                    
                    // Handle JSON fields
                    if ((property_exists($fieldDef, 'transform') && $fieldDef->transform === 'json') || 
                        in_array($fieldDef->type, ['checkboxList', 'matrixConnector', 'widgetConnector'])) {
                        if (is_array($value)) {
                            $model->$key = Json::encode($value);
                            continue;
                        }
                    }
                }
            }
            
            // Default handling
            if (is_array($value)) {
                $model->$key = Json::encode($value);
            } else {
                $model->$key = $value;
            }
        }
        
        // Process special fields using field processors
        if ($elementDefinition) {
            foreach ($elementDefinition->fields as $field) {
                if (property_exists($field, 'type') && isset($data[$field->key])) {
                    $fieldType = $field->type;
                    $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';
                    
                    if (class_exists($processorClass) && method_exists($processorClass, 'processDataPreSave')) {
                        $model->{$field->key} = $processorClass::processDataPreSave($field->key, $data[$field->key], $field, $model);
                    }
                }
            }
        }
        
        $result = $model->save(false);
        
        // Process post-save operations
        if ($result && $elementDefinition) {
            foreach ($elementDefinition->fields as $field) {
                if (property_exists($field, 'type') && isset($data[$field->key])) {
                    $fieldType = $field->type;
                    $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';
                    
                    if (class_exists($processorClass) && method_exists($processorClass, 'processDataPostSave')) {
                        $processorClass::processDataPostSave($field->key, $data[$field->key], $field, $model);
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Delete a record
     * 
     * @param string $ctype Content type
     * @param string $uuid UUID of the record to delete
     * @return bool Whether the deletion was successful
     */
    public function delete(string $ctype, string $uuid): bool
    {
        $model = $this->getModelClass($ctype)::findOne($uuid);
        
        if ($model === null) {
            return false;
        }
        
        return $model->delete() > 0;
    }
    
    /**
     * Get a data provider for the given content type
     * 
     * @param string $ctype Content type
     * @param array $filter Optional filter criteria
     * @param array $sort Optional sorting criteria
     * @param int $pageSize Optional page size for pagination
     * @return DataProviderInterface Data provider
     */
    public function getDataProvider(string $ctype, array $filter = [], array $sort = [], int $pageSize = 30): DataProviderInterface
    {
        $query = $this->getModelClass($ctype)::find();
        
        // Apply filters
        if (!empty($filter)) {
            foreach ($filter as $attribute => $value) {
                if ($attribute === 'freesearch') {
                    // Handle freesearch by searching across all fields
                    $searchFragments = explode(" ", trim($value));
                    $orConditions = ['or'];
                    
                    // Get the table schema to find all searchable columns
                    $modelClass = $this->getModelClass($ctype);
                    $tableSchema = $modelClass::getTableSchema();
                    
                    foreach ($tableSchema->columns as $column) {
                        // Only search in string/text columns
                        if (in_array($column->type, ['string', 'text', 'char'])) {
                            foreach ($searchFragments as $fragment) {
                                $orConditions[] = ['like', $column->name, $fragment];
                            }
                        }
                    }
                    
                    $query->andWhere($orConditions);
                } elseif (is_array($value) && isset($value[0]) && $value[0] === 'strict') {
                    $query->andWhere([$attribute => $value[1]]);
                } else {
                    $query->andWhere(['like', $attribute, $value]);
                }
            }
        }
        
        // Prepare sort configuration
        $sortConfig = [];
        if (!empty($sort)) {
            if (isset($sort['defaultOrder'])) {
                $sortConfig['defaultOrder'] = $sort['defaultOrder'];
            } else {
                $sortConfig['defaultOrder'] = $sort;
            }
        }
        
        return new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'pageSize' => $pageSize,
            ],
            'sort' => $sortConfig,
        ]);
    }
    
    /**
     * Get the model class for a content type
     * 
     * @param string $ctype Content type
     * @return string Fully qualified class name
     */
    protected function getModelClass(string $ctype): string
    {
        $className = Inflector::id2camel($ctype, '_');
        $modelClass = "app\\workspace\\models\\$className";
        
        if (!class_exists($modelClass)) {
            throw new \Exception("Model class not found for content type '$ctype': $modelClass");
        }
        
        return $modelClass;
    }
    
    /**
     * Generate a UUID v4
     * 
     * @return string UUID
     */
    private function generateUuid(): string
    {
        // OSX/Linux
        if (function_exists('openssl_random_pseudo_bytes')) {
            $data = openssl_random_pseudo_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);    // set version to 0100
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);    // set bits 6-7 to 10
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
        
        // Fallback
        mt_srand((double)microtime() * 10000);
        $charid = strtolower(md5(uniqid(rand(), true)));
        $hyphen = chr(45);                  // "-"
        $uuid = substr($charid, 0, 8) . $hyphen .
            substr($charid, 8, 4) . $hyphen .
            substr($charid, 12, 4) . $hyphen .
            substr($charid, 16, 4) . $hyphen .
            substr($charid, 20, 12);
        return strtolower($uuid);
    }
    
    /**
     * Create a query for the content type
     * 
     * @param string $ctype Content type
     * @return \yii\db\Query Query object
     */
    public function createQuery(string $ctype): \yii\db\Query
    {
        $query = new \yii\db\Query();
        $query->from("{{%{$ctype}}}");
        
        return $query;
    }
} 