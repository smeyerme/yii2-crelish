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

    // Process widget fields if schema is available, otherwise use nested structure processing
    if ($schema) {
      $processedData[$key] = self::processWidgetFields($data, $schema);
    } else {
      $processedData[$key] = self::processNestedStructure($data, $schema);
    }
  }

  private static function loadSchemaForField($fieldKey)
  {
    // Try to load schema based on field key or configuration
    // This could be enhanced to load the actual schema file
    // For now, return null and fall back to heuristic processing
    return null;
  }

  /**
   * Process widget fields within JSON structure data
   * @param array $data The data array
   * @param array|null $schema Optional schema for type detection
   * @return array Processed data
   */
  public static function processWidgetFields($data, $schema = null)
  {
    if (!is_array($data)) {
      return $data;
    }

    $processed = [];

    // If schema is provided, use it to identify widget fields
    if ($schema) {
      // Process fields based on schema
      if (isset($schema['fields'])) {
        foreach ($schema['fields'] as $fieldDef) {
          $fieldKey = $fieldDef['key'] ?? null;
          if (!$fieldKey || !isset($data[$fieldKey])) continue;

          $fieldType = $fieldDef['type'] ?? 'text';
          $widgetClass = $fieldDef['widgetClass'] ?? null;

          // Check if this is a widget field
          if ($widgetClass || $fieldType === 'widget' || strpos($fieldType, 'widget_') === 0 ||
              in_array($fieldType, ['assetConnector', 'asset'])) {
            // Process as widget field
            $processed[$fieldKey] = self::processWidgetValue($fieldType, $data[$fieldKey], $fieldDef);
          } else {
            $processed[$fieldKey] = $data[$fieldKey];
          }
        }
      }

      // Process arrays based on schema
      if (isset($schema['arrays'])) {
        foreach ($schema['arrays'] as $arrayDef) {
          $arrayKey = $arrayDef['key'] ?? null;
          if (!$arrayKey || !isset($data[$arrayKey])) continue;

          $processed[$arrayKey] = [];
          foreach ($data[$arrayKey] as $item) {
            $processed[$arrayKey][] = self::processWidgetFields($item, $arrayDef['itemSchema'] ?? null);
          }
        }
      }
    } else {
      // Fallback to heuristic processing
      foreach ($data as $key => $value) {
        if (is_array($value) && !self::isAssociativeArray($value)) {
          // Process array items
          $processed[$key] = [];
          foreach ($value as $item) {
            $processed[$key][] = self::processWidgetFields($item);
          }
        } else {
          // Use existing processing logic
          $processed[$key] = self::processFieldValue($key, $value);
        }
      }
    }

    return $processed;
  }

  /**
   * Process a widget field value
   */
  private static function processWidgetValue($fieldType, $value, $fieldDef = null)
  {
    // Handle asset-type widgets
    if (in_array($fieldType, ['assetConnector', 'asset']) || 
        (isset($fieldDef['widgetClass']) && strpos($fieldDef['widgetClass'], 'AssetConnector') !== false)) {
      if (self::isAssetUuid($value)) {
        $assetData = new CrelishDynamicModel(['ctype' => 'asset', 'uuid' => $value]);
        return $assetData ?: $value;
      }
    }

    // Add processing for other widget types as needed
    return $value;
  }

  /**
   * Process a field value using existing logic
   */
  private static function processFieldValue($key, $value)
  {
    if (is_array($value)) {
      return self::processNestedStructure($value);
    } elseif (self::isAssetUuid($value)) {
      $assetData = new CrelishDynamicModel(['ctype' => 'asset', 'uuid' => $value]);
      return $assetData ?: $value;
    } else {
      return $value;
    }
  }

  /**
   * Check if array is associative
   */
  private static function isAssociativeArray($arr)
  {
    if (!is_array($arr) || empty($arr)) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
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