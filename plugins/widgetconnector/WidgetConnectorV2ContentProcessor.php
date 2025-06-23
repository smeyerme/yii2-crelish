<?php
namespace giantbits\crelish\plugins\widgetconnector;

use giantbits\crelish\components\CrelishAbstractContentProcessor;

/**
 * Class WidgetConnectorV2ContentProcessor
 * 
 * Content processor for WidgetConnectorV2 widget
 * 
 * @package giantbits\crelish\plugins\widgetconnector
 */
class WidgetConnectorV2ContentProcessor extends CrelishAbstractContentProcessor
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

        $dataFormat = static::getFieldConfig($fieldConfig, 'dataFormat', 'string');
        
        // Process data according to specified format
        switch ($dataFormat) {
            case 'json':
                if (is_string($data)) {
                    $processedData[$key] = static::safeJsonDecode($data);
                } else {
                    $processedData[$key] = $data;
                }
                break;
                
            case 'array':
                if (is_string($data)) {
                    if (substr($data, 0, 1) === '[') {
                        $processedData[$key] = static::safeJsonDecode($data);
                    } else {
                        $processedData[$key] = array_map('trim', explode(',', $data));
                    }
                } elseif (is_array($data)) {
                    $processedData[$key] = $data;
                } else {
                    $processedData[$key] = [$data];
                }
                break;
                
            case 'string':
            default:
                if (is_array($data) || is_object($data)) {
                    $processedData[$key] = static::safeJsonEncode($data);
                } else {
                    $processedData[$key] = (string)$data;
                }
                break;
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
        
        $dataFormat = static::getFieldConfig($fieldConfig, 'dataFormat', 'string');
        
        // Validate and normalize data for storage
        switch ($dataFormat) {
            case 'json':
                if (is_string($data)) {
                    // Validate JSON
                    $decoded = static::safeJsonDecode($data);
                    $parent[$key] = static::safeJsonEncode($decoded);
                } else {
                    $parent[$key] = static::safeJsonEncode($data);
                }
                break;
                
            case 'array':
                if (is_array($data)) {
                    $parent[$key] = static::safeJsonEncode($data);
                } elseif (is_string($data)) {
                    if (substr($data, 0, 1) === '[') {
                        // Already JSON array
                        $decoded = static::safeJsonDecode($data);
                        $parent[$key] = static::safeJsonEncode($decoded);
                    } else {
                        // Convert CSV to JSON array
                        $array = array_map('trim', explode(',', $data));
                        $parent[$key] = static::safeJsonEncode($array);
                    }
                } else {
                    $parent[$key] = static::safeJsonEncode([$data]);
                }
                break;
                
            case 'string':
            default:
                $parent[$key] = (string)$data;
                break;
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function processDataPostSave($key, $data, $fieldConfig, &$parent)
    {
        // No post-save processing needed for widget connector
    }
}