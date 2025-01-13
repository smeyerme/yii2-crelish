<?php

namespace giantbits\crelish\plugins\relationselect;

use giantbits\crelish\components\CrelishDynamicModel;
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
      $relatedModel = new CrelishDynamicModel( ['ctype' => $fieldConfig->config->ctype, 'uuid' => $data]);

      // Link it.
      if($relatedModel) {
        $parent->link($fieldConfig->config->key, $relatedModel);
      }
    }

    return $data;
  }

  public static function processData($key, $data, &$processedData)
  {

    if (!empty($data)) {
      $sourceData = new CrelishDynamicModel(['ctype' => 'asset', 'uuid' => $data['uuid']]);

      if ($sourceData) {
        $processedData[$key] = $sourceData;
      } else {
        $processedData[$key] = [];
      }
    } elseif (!empty($data['temp'])) {
      $processedData[$key] = $data;
    }
  }

  public static function processJson($ctype, $key, $data, &$processedData)
  {

    $definition = CrelishDynamicModel::loadElementDefinition($ctype);

    $relatedCtype = $definition->fields[$key]->config->ctype;
		
    if ($data && $relatedCtype) {
      $sourceData = new CrelishDynamicModel( ['ctype' => $relatedCtype, 'uuid' => $data]);
      if ($sourceData) {
        $processedData[$key] = $sourceData;
      }
    }
  }
}
