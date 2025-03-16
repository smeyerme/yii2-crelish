<?php

namespace giantbits\crelish\components;

use Yii;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\helpers\Json;

/**
 * Database storage implementation for Crelish CMS
 */
class CrelishDbStorage implements CrelishDataStorage
{
    /**
     * {@inheritdoc}
     */
    public function findOne(string $ctype, string $uuid): ?array
    {
        $modelClass = $this->getModelClass($ctype);
        $model = $modelClass::findOne(['uuid' => $uuid]);
        
        if (!$model) {
            return null;
        }
        
        return $this->modelToArray($model, $ctype);
    }
    
    /**
     * {@inheritdoc}
     */
    public function findAll(string $ctype, array $filter = [], array $sort = [], int $limit = 0): array
    {
        $modelClass = $this->getModelClass($ctype);
        $query = $modelClass::find();
        
        $this->applyFilter($query, $filter, $ctype);
        $this->applySort($query, $sort);
        
        if ($limit > 0) {
            $query->limit($limit);
        }
        
        $models = $query->all();
        $result = [];
        
        foreach ($models as $model) {
            $result[] = $this->modelToArray($model, $ctype);
        }
        
        return $result;
    }
    
    /**
     * {@inheritdoc}
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
        
        // Set model attributes
        foreach ($data as $key => $value) {
            // Handle JSON fields
            if (is_array($value) && $model->hasAttribute($key)) {
                $model->$key = Json::encode($value);
            } else {
                $model->$key = $value;
            }
        }
        
        // Process special fields using field processors
        $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);
        
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
     * {@inheritdoc}
     */
    public function delete(string $ctype, string $uuid): bool
    {
        $modelClass = $this->getModelClass($ctype);
        $model = $modelClass::findOne(['uuid' => $uuid]);
        
        if (!$model) {
            return false;
        }
        
        return $model->delete() > 0;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getDataProvider(string $ctype, array $filter = [], array $sort = [], int $pageSize = 30): \yii\data\DataProviderInterface
    {
        $modelClass = $this->getModelClass($ctype);
        $query = $modelClass::find();
        
        $this->applyFilter($query, $filter, $ctype);
        
        $sortConfig = $this->buildSortConfig($ctype);
        
        return new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'pageSize' => $pageSize,
            ],
            'sort' => $sortConfig,
        ]);
    }
    
    /**
     * Apply filters to a query
     * 
     * @param ActiveQuery $query Query to filter
     * @param array $filter Filter criteria
     * @param string $ctype Content type
     */
    private function applyFilter(ActiveQuery $query, array $filter, string $ctype): void
    {
        $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);
        
        foreach ($filter as $key => $value) {
            if (empty($value)) {
                continue;
            }
            
            if (is_array($value)) {
                if ($value[0] === 'strict') {
                    $query->andWhere([$key => $value[1]]);
                } elseif ($value[0] === 'noempty') {
                    $query->andWhere(['!=', $key, '']);
                    $query->andWhere(['IS NOT', $key, null]);
                } elseif ($value[0] === 'lt') {
                    $query->andWhere(['<', $key, $value[1]]);
                } elseif ($value[0] === 'gt') {
                    $query->andWhere(['>', $key, $value[1]]);
                } elseif ($value[0] === 'between') {
                    $query->andWhere(['between', $key, min($value[1], $value[2]), max($value[1], $value[2])]);
                }
            } elseif ($key === 'freesearch') {
                $searchFragments = explode(" ", trim($value));
                $searchableFields = [];
                
                // Determine which fields to search in
                if ($elementDefinition) {
                    foreach ($elementDefinition->fields as $field) {
                        if (!property_exists($field, 'virtual') || !$field->virtual) {
                            $searchableFields[] = $field->key;
                        }
                    }
                }
                
                if (empty($searchableFields)) {
                    continue;
                }
                
                $orConditions = ['or'];
                
                foreach ($searchFragments as $fragment) {
                    foreach ($searchableFields as $field) {
                        $orConditions[] = ['like', $field, $fragment];
                    }
                }
                
                $query->andWhere($orConditions);
            } elseif (is_bool($value)) {
                $query->andWhere([$key => $value]);
            } else {
                if ($key === 'slug' || $key === 'state') {
                    $query->andWhere([$key => $value]);
                } else {
                    $query->andWhere(['like', $key, $value]);
                }
            }
        }
    }
    
    /**
     * Apply sorting to a query
     * 
     * @param ActiveQuery $query Query to sort
     * @param array $sort Sort criteria
     */
    private function applySort(ActiveQuery $query, array $sort): void
    {
        if (empty($sort['by']) || !is_array($sort['by'])) {
            return;
        }
        
        $orderBy = [];
        
        foreach ($sort['by'] as $index => $field) {
            if (isset($sort['by'][$index + 1])) {
                $direction = $sort['by'][$index + 1];
                
                if ($direction === 'asc' || $direction === 'desc') {
                    $orderBy[$field] = $direction === 'asc' ? SORT_ASC : SORT_DESC;
                    $index++; // Skip the direction in the next iteration
                } else {
                    $orderBy[$field] = SORT_ASC; // Default to ascending
                }
            } else {
                $orderBy[$field] = SORT_ASC; // Default to ascending for the last field
            }
        }
        
        if (!empty($orderBy)) {
            $query->orderBy($orderBy);
        }
    }
    
    /**
     * Build sort configuration for ActiveDataProvider
     * 
     * @param string $ctype Content type
     * @return array Sort configuration
     */
    private function buildSortConfig(string $ctype): array
    {
        $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);
        $attributes = [];
        $defaultOrder = [];
        
        if ($elementDefinition) {
            foreach ($elementDefinition->fields as $field) {
                if (property_exists($field, 'sortable') && $field->sortable) {
                    $attributes[$field->key] = [
                        'asc' => [$field->key => SORT_ASC],
                        'desc' => [$field->key => SORT_DESC],
                    ];
                }
            }
            
            if (property_exists($elementDefinition, 'sortDefault')) {
                foreach ($elementDefinition->sortDefault as $key => $value) {
                    $defaultOrder[$key] = constant($value);
                }
            }
        }
        
        return [
            'attributes' => $attributes,
            'defaultOrder' => $defaultOrder,
        ];
    }
    
    /**
     * Convert a model to an array
     * 
     * @param object $model Model to convert
     * @param string $ctype Content type
     * @return array Model as array
     */
    private function modelToArray(object $model, string $ctype): array
    {
        $data = $model->toArray();
        $data['ctype'] = $ctype;
        
        // Process JSON fields
        foreach ($data as $key => $value) {
            if (is_string($value) && $this->isJson($value)) {
                $data[$key] = Json::decode($value);
            }
        }
        
        // Process the data through field processors
        $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);
        $processedData = [];
        
        foreach ($data as $key => $value) {
            CrelishBaseContentProcessor::processFieldData($ctype, $elementDefinition, $key, $value, $processedData);
        }
        
        return $processedData;
    }
    
    /**
     * Check if a string is valid JSON
     * 
     * @param string $string String to check
     * @return bool Whether the string is valid JSON
     */
    private function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    /**
     * Get the model class for a content type
     * 
     * @param string $ctype Content type
     * @return string Model class
     */
    private function getModelClass(string $ctype): string
    {
        return 'app\workspace\models\\' . ucfirst($ctype);
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
} 