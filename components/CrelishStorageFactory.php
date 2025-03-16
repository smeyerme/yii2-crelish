<?php

namespace giantbits\crelish\components;

use Yii;

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
     * Get a storage implementation for a content type
     * 
     * @param string $ctype Content type
     * @return CrelishDataStorage Storage implementation
     */
    public static function getStorage(string $ctype): CrelishDataStorage
    {
        $storageType = self::getStorageType($ctype);
        
        if (!isset(self::$instances[$storageType])) {
            self::$instances[$storageType] = self::createStorage($storageType);
        }
        
        return self::$instances[$storageType];
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