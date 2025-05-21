<?php

namespace giantbits\crelish\plugins\relationselect;

use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishDataResolver;
use yii\base\Component;
use Yii;
use yii\helpers\Json;

class RelationSelectContentProcessor extends Component
{
  public $data;

  /**
   * Helper method to normalize various data formats to an array of UUIDs
   *
   * @param mixed $data The data to normalize
   * @return array An array of UUIDs
   */
  private static function normalizeToArray($data) {
    // Empty values
    if (empty($data)) {
      return [];
    }

    // Already an array
    if (is_array($data)) {
      // Make sure we have only string UUIDs, not objects or nested arrays
      return array_map(function($item) {
        if (is_object($item) && isset($item->uuid)) {
          return $item->uuid;
        } elseif (is_array($item) && isset($item['uuid'])) {
          return $item['uuid'];
        } else {
          return (string)$item;
        }
      }, $data);
    }

    // If it's an object with a uuid property
    if (is_object($data) && isset($data->uuid)) {
      return [$data->uuid];
    }

    // Try to parse as JSON (could be an array or object)
    if (is_string($data) && (strpos($data, '[') === 0 || strpos($data, '{') === 0)) {
      try {
        $decoded = Json::decode($data);
        return self::normalizeToArray($decoded); // Recursive call to handle the decoded value
      } catch (\Exception $e) {
        // Not valid JSON, treat as a string UUID
      }
    }

    // Assume it's a single UUID string
    return [(string)$data];
  }

  public static function processDataPreSaveOff($key, $data, $fieldConfig, &$parent)
  {
    $UUIDv4 = '/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i';

    if (
      isset($fieldConfig->config->autocreate)
      && $fieldConfig->config->autocreate === true
      && (!is_object($data) && !preg_match($UUIDv4, $data))
      && !empty($data)) {

      $model = new CrelishDynamicModel(['ctype' => $fieldConfig->config->ctype]);
      $model->systitle = $data;
      $model->state = 2;
      $model->save();
      return $model->uuid;
    }

    // Check if this is a multiple or single relation
    $isMultiple = isset($fieldConfig->config->multiple) && $fieldConfig->config->multiple === true;


    // Normalize the data to an array of UUIDs regardless of input format
    $normalizedData = self::normalizeToArray($data);

    // For single relations, return only the first UUID if available
    if (!$isMultiple) {
      if (empty($normalizedData)) {
        return null;
      }
      return $normalizedData[0];
    }

    // For multiple relations, return the JSON encoded array
    return Json::encode($normalizedData);
  }

  public static function processDataPostSave($key, $data, $fieldConfig, &$parent)
  {

    $isMultiple = isset($fieldConfig->config->multiple) && $fieldConfig->config->multiple === true;

    if(!$isMultiple) {
      return $data;
    }

    if (
      $data &&
      isset($key)
    ) {
      $relationKey = $key;
      $relationGetter = 'get' . ucfirst($relationKey);
      $existingRelations = $parent->$relationGetter()->all();

      $existingUuids = [];
      foreach ($existingRelations as $relation) {
        $parent->unlink($key, $relation, true);
      }

      $data = JSON::decode($data);

      // Check if we're dealing with an array
      if (is_array($data)) {
        // Process each item in the array
        foreach ($data as $item) {
          // Ensure item is a string UUID
          $uuid = is_object($item) && isset($item->uuid) ? $item->uuid : $item;

          if (in_array($uuid, $existingUuids, true)) {
            continue;
          }

          $relatedModel = CrelishDataResolver::resolveModel([
            'ctype' => $fieldConfig->config->ctype,
            'uuid' => $uuid
          ]);

          // Link it.
          if ($relatedModel) {
            $parent->link($key, $relatedModel);
          }
        }
      } else {
        // Process a single item (original code)
        $uuid = is_object($data) && isset($data->uuid) ? $data->uuid : $data;

        $relatedModel = CrelishDataResolver::resolveModel([
          'ctype' => $fieldConfig->config->ctype,
          'uuid' => $uuid
        ]);

        // Link it.
        if ($relatedModel) {
          $parent->link($fieldConfig->config->key, $relatedModel);
        }
      }
    }

    return $data;
  }

  public static function processData($key, $data, &$processedData, $config)
  {
    // Check if this is a multiple or single relation
    $isMultiple = isset($config->config->multiple) && $config->config->multiple === true;
    $contentType = isset($config->config->ctype) ? $config->config->ctype : null;

    if (!empty($data) && $contentType) {
      // Normalize to an array of UUIDs regardless of input format
      $normalizedData = self::normalizeToArray($data);

      // For multiple relations, process all items
      if ($isMultiple) {
        $processedData[$key] = [];
        $idx = 0;

        foreach ($normalizedData as $uuid) {
          $sourceData = CrelishDataResolver::resolveModel([
            'ctype' => $contentType,
            'uuid' => $uuid
          ]);

          if ($sourceData) {
            $processedData[$key][$idx] = $sourceData;
            $idx++;
          }
        }
      } else {
        // For single relation, only process the first item if available
        if (!empty($normalizedData)) {
          $uuid = $normalizedData[0];

          $sourceData = CrelishDataResolver::resolveModel([
            'ctype' => $contentType,
            'uuid' => $uuid
          ]);

          if ($sourceData) {
            $processedData[$key] = $sourceData;
          } else {
            $processedData[$key] = null;
          }
        } else {
          $processedData[$key] = null;
        }
      }
    } elseif (!empty($data['temp'])) {
      $processedData[$key] = $data;
    } else {
      // No data provided
      $processedData[$key] = $isMultiple ? [] : null;
    }
  }

  public static function processJson($ctype, $key, $data, &$processedData)
  {
    $definition = CrelishDynamicModel::loadElementDefinition($ctype);
    if (!isset($definition->fields[$key]) || !isset($definition->fields[$key]->config->ctype)) {
      Yii::warning("Missing configuration for field {$key} in content type {$ctype}");
      return;
    }

    $relatedCtype = $definition->fields[$key]->config->ctype;
    $multiple = isset($definition->fields[$key]->config->multiple) && $definition->fields[$key]->config->multiple === true;

    if (empty($data) || empty($relatedCtype)) {
      $processedData[$key] = $multiple ? [] : null;
      return;
    }

    // Normalize to an array of UUIDs regardless of input format
    $normalizedData = self::normalizeToArray($data);

    // Handle based on multiple configuration
    if ($multiple) {
      $processedData[$key] = [];
      foreach ($normalizedData as $uuid) {
        $sourceData = CrelishDataResolver::resolveModel([
          'ctype' => $relatedCtype,
          'uuid' => $uuid
        ]);

        if ($sourceData) {
          $processedData[$key][] = $sourceData;
        }
      }
    } else {
      // For single relations, use only the first UUID if available
      if (!empty($normalizedData)) {
        $uuid = $normalizedData[0];

        $sourceData = CrelishDataResolver::resolveModel([
          'ctype' => $relatedCtype,
          'uuid' => $uuid
        ]);

        if ($sourceData) {
          $processedData[$key] = $sourceData;
        } else {
          $processedData[$key] = null;
        }
      } else {
        $processedData[$key] = null;
      }
    }
  }
}