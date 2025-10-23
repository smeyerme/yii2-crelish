<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\Component;
use yii\db\Query;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\web\NotFoundHttpException;

/**
 * Content service for Crelish CMS
 */
class ContentService extends Component
{
    /**
     * @var string Base path for content type definitions
     */
    public string $contentTypesPath = '@app/config/content-types';
    
    /**
     * @var array Cache for content type definitions
     */
    private array $contentTypeCache = [];
    
    /**
     * Check if a content type exists
     * 
     * @param string $type Content type name
     * @return bool Whether the content type exists
     */
    public function contentTypeExists(string $type): bool
    {
        try {
            $definition = CrelishDynamicModel::loadElementDefinition(ucfirst($type));
            return $definition !== null;
        } catch (\Exception $e) {
            Yii::error("Content type check failed: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }
    
    /**
     * Get content type definition
     * 
     * @param string $type Content type name
     * @return array Content type definition
     * @throws NotFoundHttpException If content type does not exist
     */
    public function getContentTypeDefinition(string $type): array
    {

        $type = ucfirst($type);

        // Check cache first
        if (isset($this->contentTypeCache[$type])) {
            return $this->contentTypeCache[$type];
        }
        
        // Get content type definition using CrelishDynamicModel
        $definition = CrelishDynamicModel::loadElementDefinition($type);
        
        if (!$definition) {
            throw new NotFoundHttpException("Content type '{$type}' not found");
        }
        
        // Convert to array and cache
        $definitionArray = json_decode(json_encode($definition), true);
        $this->contentTypeCache[$type] = $definitionArray;
        
        return $definitionArray;
    }
    
    /**
     * Get query for content type
     * 
     * @param string $type Content type name
     * @return Query Query object
     */
    public function getQuery(string $type): Query
    {
        // Create a data manager for this content type
        $dataManager = new CrelishDataManager(ucfirst($type));
        
        // Get the storage implementation
        $storage = CrelishStorageFactory::getStorage(ucfirst($type));
        
        // Return the query
        return $storage->createQuery(ucfirst($type));
    }
    
    /**
     * Apply filter to query
     * 
     * @param Query $query Query object
     * @param string $filter Filter string
     * @return Query Modified query object
     */
    public function applyFilter(Query $query, string $filter): Query
    {
        // Parse filter string (format: field:operator:value,field2:operator2:value2)
        $filterParts = explode(',', $filter);
        $filterArray = [];
        
        foreach ($filterParts as $part) {
            $criteria = explode(':', $part);
            
            if (count($criteria) !== 3) {
                continue;
            }
            
            [$field, $operator, $value] = $criteria;
            
            switch ($operator) {
                case 'eq':
                    $filterArray[$field] = $value;
                    break;
                case 'neq':
                    $query->andWhere(['!=', $field, $value]);
                    break;
                case 'gt':
                    $query->andWhere(['>', $field, $value]);
                    break;
                case 'gte':
                    $query->andWhere(['>=', $field, $value]);
                    break;
                case 'lt':
                    $query->andWhere(['<', $field, $value]);
                    break;
                case 'lte':
                    $query->andWhere(['<=', $field, $value]);
                    break;
                case 'like':
                    $query->andWhere(['like', $field, $value]);
                    break;
                case 'in':
                    $values = explode('|', $value);
                    $query->andWhere(['in', $field, $values]);
                    break;
            }
        }
        
        return $query;
    }
    
    /**
     * Get content item by ID
     * 
     * @param string $type Content type name
     * @param string $id Content item ID
     * @return array|null Content item or null if not found
     */
    public function getContentById(string $type, string $id): ?array
    {
        // Create a data manager for this content type and ID
        $dataManager = new CrelishDataManager(ucfirst($type), [], $id);
        
        // Get the item
        return $dataManager->one();
    }
    
    /**
     * Create new content item
     * 
     * @param string $type Content type name
     * @param array $data Content item data
     * @return array Result with success status, message, and item data
     */
    public function createContent(string $type, array $data): array
    {
        try {
            // Get content type definition
            $definition = $this->getContentTypeDefinition($type);
            
            // Validate data against definition
            $validationResult = $this->validateData($data, $definition);
            
            if (!$validationResult['success']) {
                return $validationResult;
            }
            
            // Generate ID if not provided
            if (!isset($data['id'])) {
                $data['id'] = $this->generateUuid();
            }
            
            // Add timestamps
            $now = date('Y-m-d H:i:s');
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            
            // Get storage implementation
            $storage = CrelishStorageFactory::getStorage($type);
            
            // Save the data
            $result = $storage->save($type, $data);
            
            if (!$result) {
                return [
                    'success' => false,
                    'message' => 'Failed to create content item',
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Content item created successfully',
                'item' => $data,
            ];
        } catch (\Exception $e) {
            Yii::error($e->getMessage(), __METHOD__);
            
            return [
                'success' => false,
                'message' => 'Failed to create content item: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Update content item
     * 
     * @param string $type Content type name
     * @param string $id Content item ID
     * @param array $data Content item data
     * @return array Result with success status, message, and item data
     */
    public function updateContent(string $type, string $id, array $data): array
    {
        try {
            // Get content type definition
            $definition = $this->getContentTypeDefinition($type);
            
            // Get existing item
            $existingItem = $this->getContentById($type, $id);
            
            if ($existingItem === null) {
                return [
                    'success' => false,
                    'message' => "Content item with ID '{$id}' not found",
                ];
            }
            
            // Merge existing data with new data
            $mergedData = array_merge($existingItem, $data);
            $mergedData['id'] = $id; // Ensure ID is preserved
            
            // Validate merged data against definition
            $validationResult = $this->validateData($mergedData, $definition);
            
            if (!$validationResult['success']) {
                return $validationResult;
            }
            
            // Update timestamp
            $mergedData['updated_at'] = date('Y-m-d H:i:s');
            
            // Get storage implementation
            $storage = CrelishStorageFactory::getStorage($type);
            
            // Save the data
            $result = $storage->save($type, $mergedData);
            
            if (!$result) {
                return [
                    'success' => false,
                    'message' => 'Failed to update content item',
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Content item updated successfully',
                'item' => $mergedData,
            ];
        } catch (\Exception $e) {
            Yii::error($e->getMessage(), __METHOD__);
            
            return [
                'success' => false,
                'message' => 'Failed to update content item: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Delete content item
     * 
     * @param string $type Content type name
     * @param string $id Content item ID
     * @return bool Whether the deletion was successful
     */
    public function deleteContent(string $type, string $id): bool
    {
        try {
            // Create a data manager for this content type and ID
            $dataManager = new CrelishDataManager($type, [], $id);
            
            // Delete the item
            return $dataManager->delete();
        } catch (\Exception $e) {
            Yii::error($e->getMessage(), __METHOD__);
            return false;
        }
    }
    
    /**
     * Validate data against content type definition
     * 
     * @param array $data Data to validate
     * @param array $definition Content type definition
     * @return array Validation result with success status, message, and errors
     */
    private function validateData(array $data, array $definition): array
    {
        $errors = [];
        $fields = $definition['fields'] ?? [];
        
        foreach ($fields as $fieldName => $fieldDef) {
            // Skip if field is not required and not provided
            if ((!isset($fieldDef['required']) || !$fieldDef['required']) && !isset($data[$fieldName])) {
                continue;
            }
            
            // Check required fields
            if (isset($fieldDef['required']) && $fieldDef['required'] && !isset($data[$fieldName])) {
                $errors[$fieldName][] = "Field '{$fieldName}' is required";
                continue;
            }
            
            // Skip validation if field is not provided
            if (!isset($data[$fieldName])) {
                continue;
            }
            
            $value = $data[$fieldName];
            
            // Validate field type
            if (isset($fieldDef['type'])) {
                switch ($fieldDef['type']) {
                    case 'string':
                        if (!is_string($value)) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be a string";
                        } elseif (isset($fieldDef['minLength']) && strlen($value) < $fieldDef['minLength']) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be at least {$fieldDef['minLength']} characters long";
                        } elseif (isset($fieldDef['maxLength']) && strlen($value) > $fieldDef['maxLength']) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be at most {$fieldDef['maxLength']} characters long";
                        }
                        break;
                    case 'integer':
                        if (!is_numeric($value) || (int)$value != $value) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be an integer";
                        } elseif (isset($fieldDef['min']) && $value < $fieldDef['min']) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be at least {$fieldDef['min']}";
                        } elseif (isset($fieldDef['max']) && $value > $fieldDef['max']) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be at most {$fieldDef['max']}";
                        }
                        break;
                    case 'boolean':
                        if (!is_bool($value)) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be a boolean";
                        }
                        break;
                    case 'array':
                        if (!is_array($value)) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be an array";
                        } elseif (isset($fieldDef['minItems']) && count($value) < $fieldDef['minItems']) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must have at least {$fieldDef['minItems']} items";
                        } elseif (isset($fieldDef['maxItems']) && count($value) > $fieldDef['maxItems']) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must have at most {$fieldDef['maxItems']} items";
                        }
                        break;
                    case 'date':
                        if (!strtotime($value)) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be a valid date";
                        }
                        break;
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be a valid email address";
                        }
                        break;
                    case 'url':
                        if (!filter_var($value, FILTER_VALIDATE_URL)) {
                            $errors[$fieldName][] = "Field '{$fieldName}' must be a valid URL";
                        }
                        break;
                    case 'enum':
                        if (!isset($fieldDef['values']) || !in_array($value, $fieldDef['values'])) {
                            $values = isset($fieldDef['values']) ? implode(', ', $fieldDef['values']) : '';
                            $errors[$fieldName][] = "Field '{$fieldName}' must be one of: {$values}";
                        }
                        break;
                }
            }
        }
        
        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors,
            ];
        }
        
        return [
            'success' => true,
        ];
    }
    
    /**
     * Generate UUID
     * 
     * @return string UUID
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
} 