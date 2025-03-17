<?php

namespace giantbits\crelish\components;

use Yii;
use yii\data\ArrayDataProvider;
use yii\data\DataProviderInterface;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use function _\filter;

/**
 * JSON file-based storage implementation for Crelish CMS
 */
class CrelishJsonStorage implements CrelishDataStorage
{
    /**
     * @var string Base directory for content files
     */
    protected $contentDir;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->contentDir = Yii::getAlias('@app/workspace/content');
    }
    
    /**
     * Find a single record by UUID
     * 
     * @param string $ctype Content type
     * @param string $uuid UUID of the record
     * @return array|null The record data or null if not found
     */
    public function findOne(string $ctype, string $uuid): ?array
    {
        $filePath = $this->getFilePath($ctype, $uuid);
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        try {
            $data = Json::decode(file_get_contents($filePath), true);
            $data['ctype'] = $ctype;
            return $data;
        } catch (\Exception $e) {
            Yii::error("Error reading JSON file: $filePath - " . $e->getMessage());
            return null;
        }
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
        $typeDir = $this->getTypeDir($ctype);
        
        if (!is_dir($typeDir)) {
            return [];
        }
        
        $files = FileHelper::findFiles($typeDir, ['only' => ['*.json']]);
        $records = [];
        
        foreach ($files as $file) {
            try {
                $data = Json::decode(file_get_contents($file), true);
                $data['ctype'] = $ctype;
                $records[] = $data;
            } catch (\Exception $e) {
                Yii::error("Error reading JSON file: $file - " . $e->getMessage());
            }
        }
        
        // Apply filters
        if (!empty($filter)) {
            $records = $this->filterRecords($records, $filter);
        }
        
        // Apply sorting - handle both array format and defaultOrder format
        if (!empty($sort)) {
            if (isset($sort['defaultOrder'])) {
                $records = $this->sortRecords($records, $sort['defaultOrder']);
            } else {
                $records = $this->sortRecords($records, $sort);
            }
        }
        
        // Apply limit
        if ($limit > 0 && count($records) > $limit) {
            $records = array_slice($records, 0, $limit);
        }
        
        return $records;
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
        if (!isset($data['uuid'])) {
            $data['uuid'] = $this->generateUuid();
        }
        
        // Set timestamps
        $now = date('Y-m-d H:i:s');
        
        if ($isNew) {
            $data['created_at'] = $now;
        }
        
        $data['updated_at'] = $now;
        
        // Process special field types
        $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);
        
        if ($elementDefinition && property_exists($elementDefinition, 'fields')) {
            foreach ($elementDefinition->fields as $field) {
                if (property_exists($field, 'key') && property_exists($field, 'type') && isset($data[$field->key])) {
                    // Handle assetConnector - store only the UUID
                    if ($field->type === 'assetConnector' && is_array($data[$field->key]) && isset($data[$field->key]['uuid'])) {
                        $data[$field->key] = $data[$field->key]['uuid'];
                    }
                }
            }
        }
        
        // Ensure the directory exists
        $typeDir = $this->getTypeDir($ctype);
        FileHelper::createDirectory($typeDir);
        
        // Save the file
        $filePath = $this->getFilePath($ctype, $data['uuid']);
        
        try {
            // Remove ctype from data before saving
            $saveData = $data;
            unset($saveData['ctype']);
            
            file_put_contents($filePath, Json::encode($saveData, JSON_PRETTY_PRINT));
            return true;
        } catch (\Exception $e) {
            Yii::error("Error saving JSON file: $filePath - " . $e->getMessage());
            return false;
        }
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
        $filePath = $this->getFilePath($ctype, $uuid);
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        try {
            unlink($filePath);
            return true;
        } catch (\Exception $e) {
            Yii::error("Error deleting JSON file: $filePath - " . $e->getMessage());
            return false;
        }
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
        $records = $this->findAll($ctype, $filter);
        
        // Prepare sort configuration
        $sortConfig = [];
        if (!empty($sort)) {
            if (isset($sort['defaultOrder'])) {
                $sortConfig['defaultOrder'] = $sort['defaultOrder'];
            } else {
                $sortConfig['defaultOrder'] = $sort;
            }
        }
        
        return new ArrayDataProvider([
            'allModels' => $records,
            'pagination' => [
                'pageSize' => $pageSize,
            ],
            'sort' => $sortConfig,
        ]);
    }
    
    /**
     * Filter records based on criteria
     * 
     * @param array $records Records to filter
     * @param array $filter Filter criteria
     * @return array Filtered records
     */
    protected function filterRecords(array $records, array $filter): array
    {
        $result = [];
        
        foreach ($records as $record) {
            $match = true;
            
            foreach ($filter as $key => $value) {
                if (!isset($record[$key])) {
                    $match = false;
                    break;
                }
                
                if (is_array($value) && isset($value[0])) {
                    if ($value[0] === 'strict' && $record[$key] !== $value[1]) {
                        $match = false;
                        break;
                    }
                } elseif (is_string($value) && stripos($record[$key], $value) === false) {
                    $match = false;
                    break;
                } elseif ($record[$key] != $value) {
                    $match = false;
                    break;
                }
            }
            
            if ($match) {
                $result[] = $record;
            }
        }
        
        return $result;
    }
    
    /**
     * Sort records based on criteria
     * 
     * @param array $records Records to sort
     * @param array $sort Sort criteria
     * @return array Sorted records
     */
    protected function sortRecords(array $records, array $sort): array
    {
        if (empty($sort)) {
            return $records;
        }
        
        usort($records, function ($a, $b) use ($sort) {
            foreach ($sort as $key => $direction) {
                if (!isset($a[$key]) || !isset($b[$key])) {
                    continue;
                }
                
                $valueA = $a[$key];
                $valueB = $b[$key];
                
                if ($valueA == $valueB) {
                    continue;
                }
                
                $result = ($valueA < $valueB) ? -1 : 1;
                
                if ($direction === SORT_DESC) {
                    $result = -$result;
                }
                
                return $result;
            }
            
            return 0;
        });
        
        return $records;
    }
    
    /**
     * Get the directory path for a content type
     * 
     * @param string $ctype Content type
     * @return string Directory path
     */
    protected function getTypeDir(string $ctype): string
    {
        return $this->contentDir . '/' . $ctype;
    }
    
    /**
     * Get the file path for a record
     * 
     * @param string $ctype Content type
     * @param string $uuid UUID of the record
     * @return string File path
     */
    protected function getFilePath(string $ctype, string $uuid): string
    {
        return $this->getTypeDir($ctype) . '/' . $uuid . '.json';
    }
    
    /**
     * Generate a UUID
     * 
     * @return string UUID
     */
    protected function generateUuid(): string
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
    
    /**
     * Create a query for the content type
     * 
     * @param string $ctype Content type
     * @return \yii\db\Query Query object
     */
    public function createQuery(string $ctype): \yii\db\Query
    {
        // For JSON storage, we'll create a query that works with the ArrayDataProvider
        // This is a simple implementation that will be replaced with actual data in getDataProvider
        $query = new \yii\db\Query();
        $query->from($ctype);
        
        return $query;
    }
} 