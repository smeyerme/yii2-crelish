<?php

namespace giantbits\crelish\components;

use Yii;
use yii\data\ArrayDataProvider;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use function _\filter;

/**
 * JSON file-based storage implementation for Crelish CMS
 */
class CrelishJsonStorage implements CrelishDataStorage
{
    /**
     * @var string Base path for data storage
     */
    private $basePath;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->basePath = Yii::getAlias('@app/workspace/data');
    }
    
    /**
     * {@inheritdoc}
     */
    public function findOne(string $ctype, string $uuid): ?array
    {
        $filePath = $this->getFilePath($ctype, $uuid);
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        $data = Json::decode(file_get_contents($filePath), true);
        $data['ctype'] = $ctype;
        
        return $this->processRecord($ctype, $data);
    }
    
    /**
     * {@inheritdoc}
     */
    public function findAll(string $ctype, array $filter = [], array $sort = [], int $limit = 0): array
    {
        $records = $this->loadAllRecords($ctype);
        
        if (!empty($filter)) {
            $records = $this->applyFilter($records, $filter);
        }
        
        if (!empty($sort)) {
            $records = $this->applySort($records, $sort);
        }
        
        if ($limit > 0 && count($records) > $limit) {
            $records = array_slice($records, 0, $limit);
        }
        
        return $records;
    }
    
    /**
     * {@inheritdoc}
     */
    public function save(string $ctype, array $data, bool $isNew = true): bool
    {
        if (empty($data['uuid'])) {
            $data['uuid'] = $this->generateUuid();
        }
        
        if ($isNew && empty($data['created'])) {
            $data['created'] = time();
        }
        
        if (!$isNew) {
            $data['updated'] = time();
        }
        
        $folderPath = $this->getFolderPath($ctype);
        if (!file_exists($folderPath)) {
            FileHelper::createDirectory($folderPath, 0775, true);
        }
        
        $filePath = $this->getFilePath($ctype, $data['uuid']);
        $result = file_put_contents($filePath, Json::encode($data));
        @chmod($filePath, 0777);
        
        // Update cache
        $this->updateCache($ctype, $data, $isNew ? 'create' : 'update');
        
        return $result !== false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function delete(string $ctype, string $uuid): bool
    {
        $filePath = $this->getFilePath($ctype, $uuid);
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        // Update cache
        $this->updateCache($ctype, ['uuid' => $uuid], 'delete');
        
        return unlink($filePath);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getDataProvider(string $ctype, array $filter = [], array $sort = [], int $pageSize = 30): \yii\data\DataProviderInterface
    {
        $records = $this->findAll($ctype, $filter, $sort);
        
        return new ArrayDataProvider([
            'allModels' => $records,
            'pagination' => [
                'pageSize' => $pageSize,
            ],
            'sort' => $this->buildSortConfig($ctype),
        ]);
    }
    
    /**
     * Load all records for a content type
     * 
     * @param string $ctype Content type
     * @return array Array of records
     */
    private function loadAllRecords(string $ctype): array
    {
        $cacheKey = 'crc_' . $ctype;
        $records = Yii::$app->cache->get($cacheKey);
        
        if ($records === false) {
            $records = [];
            $folderPath = $this->getFolderPath($ctype);
            
            if (!file_exists($folderPath)) {
                FileHelper::createDirectory($folderPath, 0775, true);
                return $records;
            }
            
            $files = FileHelper::findFiles($folderPath, ['only' => ['*.json'], 'recursive' => false]);
            
            foreach ($files as $file) {
                $content = file_get_contents($file);
                $data = Json::decode($content, true);
                
                if ($data === null) {
                    continue;
                }
                
                $data['ctype'] = $ctype;
                $records[] = $this->processRecord($ctype, $data);
            }
            
            Yii::$app->cache->set($cacheKey, $records);
        }
        
        return $records;
    }
    
    /**
     * Apply filters to records
     * 
     * @param array $records Records to filter
     * @param array $filter Filter criteria
     * @return array Filtered records
     */
    private function applyFilter(array $records, array $filter): array
    {
        foreach ($filter as $key => $value) {
            if (empty($value)) {
                continue;
            }
            
            if (is_array($value)) {
                if ($value[0] === 'strict') {
                    $records = filter($records, function ($record) use ($key, $value) {
                        return isset($record[$key]) && $record[$key] == $value[1];
                    });
                } elseif ($value[0] === 'noempty') {
                    $records = filter($records, function ($record) use ($key) {
                        return isset($record[$key]) && $record[$key] !== '';
                    });
                } elseif ($value[0] === 'lt') {
                    $records = filter($records, function ($record) use ($key, $value) {
                        return isset($record[$key]) && $record[$key] < $value[1];
                    });
                } elseif ($value[0] === 'gt') {
                    $records = filter($records, function ($record) use ($key, $value) {
                        return isset($record[$key]) && $record[$key] > $value[1];
                    });
                } elseif ($value[0] === 'between') {
                    $records = filter($records, function ($record) use ($key, $value) {
                        return isset($record[$key]) && (
                            ($record[$key] >= $value[1] && $record[$key] <= $value[2]) ||
                            ($record[$key] >= $value[2] && $record[$key] <= $value[1])
                        );
                    });
                }
            } elseif ($key === 'freesearch') {
                $records = filter($records, function ($record) use ($value) {
                    $isMatch = true;
                    $itemString = strtolower(json_encode($record));
                    $searchFragments = explode(" ", trim($value));
                    
                    foreach ($searchFragments as $fragment) {
                        if (strpos($itemString, strtolower($fragment)) === false) {
                            $isMatch = false;
                        }
                    }
                    
                    return $isMatch;
                });
            } elseif (is_bool($value)) {
                $records = filter($records, function ($record) use ($key, $value) {
                    return isset($record[$key]) && $record[$key] === $value;
                });
            } else {
                $records = filter($records, function ($record) use ($key, $value) {
                    if ($key === 'slug' || $key === 'state') {
                        return isset($record[$key]) && $record[$key] === $value;
                    } else {
                        return isset($record[$key]) && stripos($record[$key], $value) !== false;
                    }
                });
            }
        }
        
        return array_values($records);
    }
    
    /**
     * Apply sorting to records
     * 
     * @param array $records Records to sort
     * @param array $sort Sort criteria
     * @return array Sorted records
     */
    private function applySort(array $records, array $sort): array
    {
        if (empty($sort['by']) || !is_array($sort['by'])) {
            return $records;
        }
        
        $args = [$records];
        
        foreach ($sort['by'] as $field) {
            $args[] = $field;
        }
        
        return $this->arrayOrderBy(...$args);
    }
    
    /**
     * Process a record to ensure all fields are properly formatted
     * 
     * @param string $ctype Content type
     * @param array $data Record data
     * @return array Processed record
     */
    private function processRecord(string $ctype, array $data): array
    {
        $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);
        $processedData = [];
        
        foreach ($data as $key => $value) {
            CrelishBaseContentProcessor::processFieldData($ctype, $elementDefinition, $key, $value, $processedData);
        }
        
        return $processedData;
    }
    
    /**
     * Update the cache for a content type
     * 
     * @param string $ctype Content type
     * @param array $data Record data
     * @param string $action Action (create, update, delete)
     */
    private function updateCache(string $ctype, array $data, string $action): void
    {
        $cacheKey = 'crc_' . $ctype;
        $cacheData = Yii::$app->cache->get($cacheKey) ?: [];
        
        switch ($action) {
            case 'create':
                $cacheData[] = $this->processRecord($ctype, $data);
                break;
                
            case 'update':
                foreach ($cacheData as $index => $item) {
                    if ($item['uuid'] === $data['uuid']) {
                        $cacheData[$index] = $this->processRecord($ctype, $data);
                        break;
                    }
                }
                break;
                
            case 'delete':
                foreach ($cacheData as $index => $item) {
                    if ($item['uuid'] === $data['uuid']) {
                        unset($cacheData[$index]);
                        break;
                    }
                }
                $cacheData = array_values($cacheData);
                break;
        }
        
        Yii::$app->cache->set($cacheKey, $cacheData);
    }
    
    /**
     * Build sort configuration for ArrayDataProvider
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
                    $attributes[] = $field->key;
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
     * Get the folder path for a content type
     * 
     * @param string $ctype Content type
     * @return string Folder path
     */
    private function getFolderPath(string $ctype): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $ctype;
    }
    
    /**
     * Get the file path for a record
     * 
     * @param string $ctype Content type
     * @param string $uuid UUID of the record
     * @return string File path
     */
    private function getFilePath(string $ctype, string $uuid): string
    {
        return $this->getFolderPath($ctype) . DIRECTORY_SEPARATOR . $uuid . '.json';
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
     * Multi-dimensional array sort
     * 
     * @return array Sorted array
     */
    private function arrayOrderBy(): array
    {
        $args = func_get_args();
        $data = array_shift($args);
        
        foreach ($args as $n => $field) {
            if (is_string($field)) {
                $tmp = [];
                
                foreach ($data as $key => $row) {
                    if (strpos($field, ".") !== false) {
                        $tmp[$key] = \_\get($row, $field);
                    } else {
                        $tmp[$key] = $row[$field] ?? null;
                    }
                }
                
                $args[$n] = $tmp;
            }
        }
        
        $args[] = &$data;
        @call_user_func_array('array_multisort', $args);
        
        return array_pop($args);
    }
} 