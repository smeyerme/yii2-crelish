<?php
namespace giantbits\crelish\plugins\jsonstructureeditor;

use giantbits\crelish\components\CrelishDynamicModel;
use yii\base\Component;
use yii\helpers\Json;

class JsonStructureEditorContentProcessor extends Component
{
  public static function processData($key, $data, &$processedData)
  {
    if (is_string($data)) {
      $data = Json::decode($data);
    }

    if (empty($data)) {
      $processedData[$key] = [];
      return;
    }

    // Try to load the schema to understand field types
    $schema = self::loadSchemaForField($key);

    // Process the nested structure and handle asset/relation fields
    $processedData[$key] = self::processNestedStructure($data, $schema);
  }

  private static function loadSchemaForField($fieldKey)
  {
    // Try to load schema based on field key or configuration
    // This could be enhanced to load the actual schema file
    // For now, return null and fall back to heuristic processing
    return null;
  }

  private static function processWithSchema($data, $schema = null)
  {
    if (!is_array($data)) {
      return $data;
    }

    $processed = [];

    foreach ($data as $fieldKey => $fieldValue) {
      if (is_array($fieldValue)) {
        // Handle arrays - process each item
        $processed[$fieldKey] = [];
        foreach ($fieldValue as $itemIndex => $item) {
          $processed[$fieldKey][$itemIndex] = self::processWithSchema($item, $schema);
        }
      } else {
        // Determine field type from schema or heuristics
        $fieldType = self::getFieldType($fieldKey, $fieldValue, $schema);

        switch ($fieldType) {
          case 'asset':
            if (!empty($fieldValue)) {
              $assetData = new CrelishDynamicModel(['ctype' => 'asset', 'uuid' => $fieldValue]);
              $processed[$fieldKey] = $assetData ?: $fieldValue;
            } else {
              $processed[$fieldKey] = null;
            }
            break;

          case 'relation':
            // Load related model if needed
            $processed[$fieldKey] = $fieldValue;
            break;

          default:
            $processed[$fieldKey] = $fieldValue;
        }
      }
    }

    return $processed;
  }

  public static function processJson($ctype, $key, $data, &$processedData)
  {
    if ($data) {
      $processedData[$key] = self::processNestedStructure(Json::decode($data));
    }
  }

  private static function getFieldType($fieldKey, $fieldValue, $schema)
  {
    // If schema is available, use it
    if ($schema && isset($schema['fieldTypes'][$fieldKey])) {
      return $schema['fieldTypes'][$fieldKey];
    }

    // Fall back to heuristics based on field name and value
    if (self::isAssetUuid($fieldValue)) {
      return 'asset';
    }

    if (self::isRelationId($fieldKey, $fieldValue)) {
      return 'relation';
    }

    // Check field name patterns that typically indicate assets
    $assetFieldPatterns = ['image', 'photo', 'picture', 'file', 'document', 'media', 'asset'];
    foreach ($assetFieldPatterns as $pattern) {
      if (strpos(strtolower($fieldKey), $pattern) !== false) {
        return 'asset';
      }
    }

    return 'text';
  }

  private static function processNestedStructure($data, $schema = null)
  {
    if (!is_array($data)) {
      return $data;
    }

    $processed = [];

    foreach ($data as $fieldKey => $fieldValue) {
      if (is_array($fieldValue)) {
        // Handle arrays (like slides, days, sessions, slots)
        $processed[$fieldKey] = [];
        foreach ($fieldValue as $itemIndex => $item) {
          $processed[$fieldKey][$itemIndex] = self::processNestedStructure($item, $schema);
        }
      } elseif (self::isAssetUuid($fieldValue)) {
        // Process asset fields - load the actual asset data
        $assetData = new CrelishDynamicModel(['ctype' => 'asset', 'uuid' => $fieldValue]);
        $processed[$fieldKey] = $assetData ?: $fieldValue;
      } elseif (self::isRelationId($fieldKey, $fieldValue)) {
        // Process relation fields if needed
        // You could load related models here
        $processed[$fieldKey] = $fieldValue;
      } else {
        // Check if this field should be treated as an asset based on field name
        $fieldType = self::getFieldType($fieldKey, $fieldValue, $schema);
        if ($fieldType === 'asset' && !empty($fieldValue)) {
          $assetData = new CrelishDynamicModel(['ctype' => 'asset', 'uuid' => $fieldValue]);
          $processed[$fieldKey] = $assetData ?: $fieldValue;
        } else {
          // Regular text/textarea fields
          $processed[$fieldKey] = $fieldValue;
        }
      }
    }

    return $processed;
  }

  private static function isAssetUuid($value)
  {
    // Check if it looks like a UUID (basic validation)
    return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
  }

  private static function isRelationId($fieldKey, $value)
  {
    // Check if this is a relation field based on naming convention or other logic
    return (strpos($fieldKey, '_id') !== false || strpos($fieldKey, 'category') !== false) && is_numeric($value);
  }
}