<?php

namespace giantbits\crelish\components;

use yii\data\DataProviderInterface;
use yii\db\Query;

/**
 * Interface for Crelish data storage implementations
 */
interface CrelishDataStorage
{
    /**
     * Find a single record by ID
     * 
     * @param string $ctype Content type
     * @param string $uuid UUID
     * @return array|null Record data or null if not found
     */
    public function findOne(string $ctype, string $uuid): ?array;
    
    /**
     * Find all records matching the filter
     * 
     * @param string $ctype Content type
     * @param array $filter Filter criteria
     * @param array $sort Sort criteria
     * @return array Array of records
     */
    public function findAll(string $ctype, array $filter = [], array $sort = []): array;
    
    /**
     * Get a data provider for the content type
     * 
     * @param string $ctype Content type
     * @param array $filter Filter criteria
     * @param array $sort Sort criteria
     * @param int $pageSize Page size
     * @return DataProviderInterface Data provider
     */
    public function getDataProvider(string $ctype, array $filter = [], array $sort = [], int $pageSize = 20): DataProviderInterface;
    
    /**
     * Save a record
     * 
     * @param string $ctype Content type
     * @param array $data Record data
     * @return bool Whether the save was successful
     */
    public function save(string $ctype, array $data): bool;
    
    /**
     * Delete a record
     * 
     * @param string $ctype Content type
     * @param string $uuid UUID
     * @return bool Whether the deletion was successful
     */
    public function delete(string $ctype, string $uuid): bool;
    
    /**
     * Create a query for the content type
     * 
     * @param string $ctype Content type
     * @return Query Query object
     */
    public function createQuery(string $ctype): Query;
} 