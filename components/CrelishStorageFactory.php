<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\InvalidArgumentException;

/**
 * Factory class for creating storage implementations
 */
class CrelishStorageFactory
{
    /**
     * @var array Storage implementation instances
     */
    private static $instances = [];
    
    /**
     * Get a storage implementation for the given content type
     * 
     * @param string $ctype Content type
     * @return CrelishDataStorage Storage implementation
     * @throws InvalidArgumentException If the storage type is invalid
     */
    public static function getStorage(string $ctype): CrelishDataStorage
    {
        // Get the element definition
        $definition = CrelishDynamicModel::loadElementDefinition($ctype);

        // Determine the storage type
        $storageType = $definition->storage ?? 'json';

        // Create the appropriate storage implementation
        switch ($storageType) {
            case 'db':
                return new CrelishDbStorage();
            case 'json':
                return new CrelishJsonStorage();
            default:
                throw new InvalidArgumentException("Invalid storage type: $storageType");
        }
    }
    
    /**
     * Create a storage implementation
     * 
     * @param string $storageType Storage type
     * @return CrelishDataStorage Storage implementation
     */
    private static function createStorage(string $storageType): CrelishDataStorage
    {
        switch ($storageType) {
            case 'db':
                return new CrelishDbStorage();
            case 'json':
            default:
                return new CrelishJsonStorage();
        }
    }
    
    /**
     * Get the storage type for a content type
     * 
     * @param string $ctype Content type
     * @return string Storage type
     */
    private static function getStorageType(string $ctype): string
    {
        $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);
        
        if ($elementDefinition && property_exists($elementDefinition, 'storage') && $elementDefinition->storage === 'db') {
            return 'db';
        }
        
        return 'json';
    }
} 