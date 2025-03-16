<?php

namespace giantbits\crelish\components;

/**
 * Interface for data storage implementations in Crelish CMS
 * 
 * This interface defines the contract that all storage implementations must follow,
 * allowing for consistent data access regardless of the underlying storage mechanism.
 */
interface CrelishDataStorage
{
    /**
     * Find a single record by UUID
     * 
     * @param string $ctype Content type
     * @param string $uuid UUID of the record
     * @return array|null The record data or null if not found
     */
    public function findOne(string $ctype, string $uuid): ?array;
    
    /**
     * Find all records of a given content type
     * 
     * @param string $ctype Content type
     * @param array $filter Optional filter criteria
     * @param array $sort Optional sorting criteria
     * @param int $limit Optional limit
     * @return array Array of records
     */
    public function findAll(string $ctype, array $filter = [], array $sort = [], int $limit = 0): array;
    
    /**
     * Save a record
     * 
     * @param string $ctype Content type
     * @param array $data Record data
     * @param bool $isNew Whether this is a new record
     * @return bool Whether the save was successful
     */
    public function save(string $ctype, array $data, bool $isNew = true): bool;
    
    /**
     * Delete a record
     * 
     * @param string $ctype Content type
     * @param string $uuid UUID of the record to delete
     * @return bool Whether the deletion was successful
     */
    public function delete(string $ctype, string $uuid): bool;
    
    /**
     * Get a data provider for the given content type
     * 
     * @param string $ctype Content type
     * @param array $filter Optional filter criteria
     * @param array $sort Optional sorting criteria
     * @param int $pageSize Optional page size for pagination
     * @return \yii\data\DataProviderInterface Data provider
     */
    public function getDataProvider(string $ctype, array $filter = [], array $sort = [], int $pageSize = 30): \yii\data\DataProviderInterface;
} 