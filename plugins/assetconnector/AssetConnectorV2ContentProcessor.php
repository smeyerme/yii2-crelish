<?php
namespace giantbits\crelish\plugins\assetconnector;

use giantbits\crelish\components\CrelishAbstractContentProcessor;
use giantbits\crelish\components\CrelishDynamicModel;

/**
 * Class AssetConnectorV2ContentProcessor
 * 
 * Content processor for AssetConnectorV2 widget
 * 
 * @package giantbits\crelish\plugins\assetconnector
 */
class AssetConnectorV2ContentProcessor extends CrelishAbstractContentProcessor
{
    /**
     * {@inheritdoc}
     */
    public static function processData($key, $data, &$processedData, $fieldConfig = null)
    {
        if (empty($data)) {
            $processedData[$key] = null;
            return;
        }

        // Extract UUID from various data formats
        $uuid = static::extractAssetUuid($data);
        
        if (empty($uuid) || !static::isUuid($uuid)) {
            $processedData[$key] = null;
            return;
        }
        
        // Load asset model
        $assetModel = static::loadRelatedModel($uuid, 'asset');
        $processedData[$key] = $assetModel ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public static function processDataPreSave($key, $data, $fieldConfig, &$parent)
    {
        if (empty($data)) {
            unset($parent[$key]);
            return;
        }
        
        // Extract and validate UUID
        $uuid = static::extractAssetUuid($data);
        
        if (empty($uuid) || !static::isUuid($uuid)) {
            unset($parent[$key]);
            return;
        }
        
        // Save the UUID
        $parent[$key] = $uuid;
    }

    /**
     * {@inheritdoc}
     */
    public static function processDataPostSave($key, $data, $fieldConfig, &$parent)
    {
        // Could be used for updating asset usage tracking, thumbnails, etc.
        // For now, no post-save processing needed
    }

    /**
     * Extract asset UUID from various data formats
     * 
     * @param mixed $data
     * @return string|null
     */
    protected static function extractAssetUuid($data)
    {
        if (empty($data)) {
            return null;
        }
        
        // Handle string UUIDs
        if (is_string($data)) {
            // Check if it's JSON
            if (substr($data, 0, 1) === '{') {
                $decoded = static::safeJsonDecode($data, false);
                if ($decoded && isset($decoded->uuid)) {
                    return $decoded->uuid;
                }
            }
            
            // Assume it's a direct UUID
            return $data;
        }
        
        // Handle objects
        if (is_object($data)) {
            if (isset($data->uuid)) {
                return $data->uuid;
            }
        }
        
        // Handle arrays
        if (is_array($data) && isset($data['uuid'])) {
            return $data['uuid'];
        }
        
        return null;
    }
}