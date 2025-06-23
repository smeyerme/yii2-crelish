<?php
namespace giantbits\crelish\components;

use yii\base\Component;
use giantbits\crelish\components\interfaces\CrelishContentProcessorInterface;

/**
 * Class CrelishAbstractContentProcessor
 * 
 * Abstract base class for all content processors
 * Provides common functionality and default implementations
 * 
 * @package giantbits\crelish\components
 */
abstract class CrelishAbstractContentProcessor extends Component implements CrelishContentProcessorInterface
{
    /**
     * {@inheritdoc}
     */
    public static function processData($key, $data, &$processedData, $fieldConfig = null)
    {
        // Default implementation - just pass through
        $processedData[$key] = $data;
    }

    /**
     * {@inheritdoc}
     */
    public static function processDataPreSave($key, $data, $fieldConfig, &$parent)
    {
        // Default implementation - no pre-save processing
        $parent[$key] = $data;
    }

    /**
     * {@inheritdoc}
     */
    public static function processDataPostSave($key, $data, $fieldConfig, &$parent)
    {
        // Default implementation - no post-save processing
    }

    /**
     * {@inheritdoc}
     */
    public static function processJson($ctype, $key, $data, &$processedData)
    {
        // Default implementation for backward compatibility
        static::processData($key, $data, $processedData);
    }

    /**
     * Helper method to check if a value is a UUID
     * 
     * @param mixed $value
     * @return bool
     */
    protected static function isUuid($value)
    {
        if (!is_string($value)) {
            return false;
        }
        
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Helper method to check if a value is JSON
     * 
     * @param mixed $value
     * @return bool
     */
    protected static function isJson($value)
    {
        if (!is_string($value)) {
            return false;
        }
        
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Helper method to safely decode JSON
     * 
     * @param string $json
     * @param bool $associative
     * @return mixed
     */
    protected static function safeJsonDecode($json, $associative = true)
    {
        if (empty($json)) {
            return $associative ? [] : null;
        }
        
        $decoded = json_decode($json, $associative);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $associative ? [] : null;
        }
        
        return $decoded;
    }

    /**
     * Helper method to safely encode JSON
     * 
     * @param mixed $data
     * @param int $options
     * @return string
     */
    protected static function safeJsonEncode($data, $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    {
        if (empty($data)) {
            return '{}';
        }
        
        $encoded = json_encode($data, $options);
        
        if ($encoded === false) {
            return '{}';
        }
        
        return $encoded;
    }

    /**
     * Load a related model by UUID
     * 
     * @param string $uuid
     * @param string $ctype
     * @return CrelishDynamicModel|null
     */
    protected static function loadRelatedModel($uuid, $ctype)
    {
        if (empty($uuid) || !static::isUuid($uuid)) {
            return null;
        }
        
        try {
            return new CrelishDynamicModel(['ctype' => $ctype, 'uuid' => $uuid]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Process an array of items
     * 
     * @param array $items
     * @param callable $processor
     * @return array
     */
    protected static function processArray($items, $processor)
    {
        if (!is_array($items)) {
            return [];
        }
        
        $processed = [];
        foreach ($items as $key => $item) {
            $processed[$key] = $processor($item, $key);
        }
        
        return $processed;
    }

    /**
     * Get field configuration value
     * 
     * @param \stdClass|null $fieldConfig
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected static function getFieldConfig($fieldConfig, $key, $default = null)
    {
        if (!$fieldConfig || !is_object($fieldConfig)) {
            return $default;
        }
        
        // Check in config property first
        if (isset($fieldConfig->config) && isset($fieldConfig->config->$key)) {
            return $fieldConfig->config->$key;
        }
        
        // Check in field config directly
        if (isset($fieldConfig->$key)) {
            return $fieldConfig->$key;
        }
        
        return $default;
    }
}