<?php

namespace giantbits\crelish\plugins\relationselect;

use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishDataResolver;
use yii\base\Component;

class RelationSelectContentProcessor extends Component
{
  public $data;

  public static function processDataPreSave($key, $data, $fieldConfig, &$parent)
  {
    $UUIDv4 = '/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i';

    if (
      isset($fieldConfig->config->autocreate)
      && $fieldConfig->config->autocreate === true
      && (!is_object($data) && !preg_match($UUIDv4, $data))
      && !empty($data)) {
      
      $model = new CrelishDynamicModel( ['ctype' => $fieldConfig->config->ctype]);
      $model->systitle = $data;
      $model->state = 2;
      $model->save();
      return $model->uuid;
    }

    return $data;
  }

  public static function processDataPostSave($key, $data, $fieldConfig, &$parent)
  {
    if (
      $data &&
      isset($fieldConfig->config->multiple) &&
      $fieldConfig->config->multiple === true &&
      isset($fieldConfig->config->key)
    ) {
      // Ensure data is a string UUID
      $uuid = is_object($data) && isset($data->uuid) ? $data->uuid : $data;
      
      $relatedModel = CrelishDataResolver::resolveModel([
        'ctype' => $fieldConfig->config->ctype, 
        'uuid' => $uuid
      ]);

      // Link it.
      if($relatedModel) {
        $parent->link($fieldConfig->config->key, $relatedModel);
      }
    }

    return $data;
  }

  public static function processData($key, $data, &$processedData, $config)
  {


    if (!empty($data)) {
      // Check if data is an array of UUIDs (for multiple relations)
      if (is_array($data) && isset($data[0]) && !is_array($data[0])) {
        $processedData[$key] = [];
        $idx = 0;
        foreach ($data as $uuid) {
          // Ensure uuid is a string
          $uuid = is_object($uuid) && isset($uuid->uuid) ? $uuid->uuid : $uuid;

          $sourceData = CrelishDataResolver::resolveModel([
            'ctype' => $config->config->ctype,
            'uuid' => $uuid
          ]);

          if ($sourceData) {
            $processedData[$key][$idx] = $sourceData;
          }
          $idx++;
        }
      } else {
        // Single relation
        $uuid = isset($data['uuid']) ? $data['uuid'] : (is_object($data) && isset($data->uuid) ? $data->uuid : $data);

        $sourceData = CrelishDataResolver::resolveModel([
          'ctype' => $config->config->ctype,
          'uuid' => $uuid
        ]);

        if ($sourceData) {
          $processedData[$key] = $sourceData;
        } else {
          $processedData[$key] = [];
        }
      }
    } elseif (!empty($data['temp'])) {
      $processedData[$key] = $data;
    }
  }

  public static function processJson($ctype, $key, $data, &$processedData)
  {
    $definition = CrelishDynamicModel::loadElementDefinition($ctype);
    $relatedCtype = $definition->fields[$key]->config->ctype;
    $multiple = isset($definition->fields[$key]->config->multiple) && $definition->fields[$key]->config->multiple === true;

    if ($data && $relatedCtype) {
      // If data is a JSON string, decode it
      if (is_string($data) && (strpos($data, '[') === 0 || strpos($data, '{') === 0)) {
        $data = json_decode($data, true);
      }

      // Handle multiple relations
      if ($multiple && is_array($data)) {
        $processedData[$key] = [];
        foreach ($data as $uuid) {
          // Ensure uuid is a string
          $uuid = is_object($uuid) && isset($uuid->uuid) ? $uuid->uuid : $uuid;

          $sourceData = new CrelishDynamicModel([], [
            'ctype' => $relatedCtype,
            'uuid' => $uuid
          ]);

          
          if ($sourceData) {
            $processedData[$key][] = $sourceData;
          }
        }
      } else {
        // Single relation
        $uuid = is_object($data) && isset($data->uuid) ? $data->uuid : $data;
        
        $sourceData = CrelishDataResolver::resolveModel([
          'ctype' => $relatedCtype, 
          'uuid' => $uuid
        ]);
        
        if ($sourceData) {
          $processedData[$key] = $sourceData;
        }
      }
    }
  }
}
