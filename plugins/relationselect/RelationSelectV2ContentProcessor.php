<?php
namespace giantbits\crelish\plugins\relationselect;

use giantbits\crelish\components\CrelishAbstractContentProcessor;
use giantbits\crelish\components\CrelishDynamicModel;

/**
 * Class RelationSelectV2ContentProcessor
 * 
 * Content processor for RelationSelectV2 widget
 * 
 * @package giantbits\crelish\plugins\relationselect
 */
class RelationSelectV2ContentProcessor extends CrelishAbstractContentProcessor
{
    /**
     * {@inheritdoc}
     */
    public static function processData($key, $data, &$processedData, $fieldConfig = null)
    {
        if (empty($data)) {
            $processedData[$key] = [];
            return;
        }

        $ctype = static::getFieldConfig($fieldConfig, 'ctype', '');
        $multiple = static::getFieldConfig($fieldConfig, 'multiple', false);
        
        // Normalize to array
        $uuids = static::normalizeToArray($data);
        
        // Load related models
        $items = [];
        foreach ($uuids as $uuid) {
            if (empty($uuid) || !static::isUuid($uuid)) {
                continue;
            }
            
            $model = static::loadRelatedModel($uuid, $ctype);
            if ($model && !empty($model->uuid)) {
                $items[] = $model;
            }
        }
        
        // Return based on multiple setting
        if ($multiple) {
            $processedData[$key] = $items;
        } else {
            $processedData[$key] = !empty($items) ? $items[0] : null;
        }
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
        
        $multiple = static::getFieldConfig($fieldConfig, 'multiple', false);
        
        // Normalize to array of UUIDs
        $uuids = static::normalizeToArray($data);
        
        // Filter valid UUIDs
        $validUuids = array_filter($uuids, function($uuid) {
            return !empty($uuid) && static::isUuid($uuid);
        });
        
        if (empty($validUuids)) {
            unset($parent[$key]);
            return;
        }
        
        // Save based on multiple setting
        if ($multiple) {
            $parent[$key] = array_values($validUuids);
        } else {
            $parent[$key] = $validUuids[0];
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function processDataPostSave($key, $data, $fieldConfig, &$parent)
    {
        // Could be used for updating relation indexes, search data, etc.
        // For now, no post-save processing needed
    }

    /**
     * Normalize data to array of UUIDs
     * 
     * @param mixed $data
     * @return array
     */
    protected static function normalizeToArray($data)
    {
        if (empty($data)) {
            return [];
        }

        // Handle arrays
        if (is_array($data)) {
            $result = [];
            foreach ($data as $item) {
                if (is_object($item) && isset($item->uuid)) {
                    $result[] = $item->uuid;
                } elseif (is_string($item)) {
                    $result[] = $item;
                }
            }
            return array_filter($result);
        }

        // Handle objects
        if (is_object($data)) {
            if (isset($data->uuid)) {
                return [$data->uuid];
            }
            return [];
        }

        // Handle JSON strings
        if (is_string($data)) {
            if (substr($data, 0, 1) === '{' || substr($data, 0, 1) === '[') {
                $decoded = static::safeJsonDecode($data);
                return static::normalizeToArray($decoded);
            }
            
            // Handle comma-separated values
            if (strpos($data, ',') !== false) {
                return array_map('trim', explode(',', $data));
            }
            
            // Single UUID
            return [$data];
        }

        return [];
    }
}