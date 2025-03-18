<?php

namespace giantbits\crelish\plugins\jsoneditor;

use giantbits\crelish\components\transformer\CrelishFieldTransformerJson;
use Yii;
use yii\base\Component;
use yii\helpers\Json;

/**
 * JSON Editor content processor
 * Handles transformations for JSON data when saving and retrieving
 */
class JsonEditorContentProcessor extends Component
{
  /**
   * Process data during standard processing
   * @param string $key Field key
   * @param mixed $value Field value
   * @param array $processedData Processed data array
   */
  public static function processData($key, $value, &$processedData)
  {
    // Just set the value in the processed data array
    $processedData[$key] = $value;
  }
  
  /**
   * Process JSON data
   * @param string $ctype Content type
   * @param string $key Field key
   * @param mixed $value Field value
   * @param array $finalArr Final data array
   */
  public static function processJson($ctype, $key, $value, &$finalArr)
  {
    // Process json data
    if (is_string($value) && self::isJson($value)) {
      // Convert JSON string to array/object
      $value = Json::decode($value);
    }
    
    $finalArr[$key] = $value;
  }
  
  /**
   * Process field during save operation
   * @param string $key Field key
   * @param mixed $value Field value
   * @param mixed $field Field definition
   * @return mixed Processed value
   */
  public static function processSave($key, $value, $field)
  {
    // If value is already a JSON string, return it
    if (is_string($value) && self::isJson($value)) {
      return $value;
    }
    
    // If value is null or empty, use default or empty structure
    if ($value === null || (is_string($value) && trim($value) === '')) {
      if (property_exists($field, 'schema')) {
        if ($field->schema->type === 'array') {
          $value = [];
        } else {
          $value = new \stdClass();
        }
      } else {
        $value = new \stdClass();
      }
    }
    
    // Handle translatable fields
    if (property_exists($field, 'translatable') && $field->translatable === true) {
      // Process each language version if it's an array with language keys
      if (is_array($value) && !empty($value)) {
        foreach ($value as $lang => $langValue) {
          if (is_array($langValue) || is_object($langValue)) {
            // Convert array/object to JSON string
            $value[$lang] = Json::encode($langValue);
          } else if ($langValue === null) {
            // Handle null values
            $value[$lang] = '{}';
          }
        }
      }
    } else {
      // Convert to JSON string if it's an array or object
      if (is_array($value) || is_object($value)) {
        $value = Json::encode($value);
      }
    }
    
    return $value;
  }
  
  /**
   * Process field during find operation
   * @param string $key Field key
   * @param mixed $value Field value
   * @param mixed $field Field definition
   * @return mixed Processed value
   */
  public static function processFind($key, $value, $field)
  {
    // Handle translatable fields
    if (property_exists($field, 'translatable') && $field->translatable === true) {
      // Process each language version if it's an array with language keys
      if (is_array($value) && !empty($value)) {
        foreach ($value as $lang => $langValue) {
          if (is_string($langValue) && self::isJson($langValue)) {
            // Convert JSON string to array/object
            $value[$lang] = Json::decode($langValue);
          }
        }
      }
    } else if (is_string($value) && self::isJson($value)) {
      // Convert JSON string to array/object
      $value = Json::decode($value);
    }
    
    return $value;
  }
  
  /**
   * Check if a string is valid JSON
   * @param string $string String to check
   * @return bool True if string is valid JSON
   */
  private static function isJson($string)
  {
    if (!is_string($string)) {
      return false;
    }
    
    json_decode($string);
    return (json_last_error() == JSON_ERROR_NONE);
  }
} 