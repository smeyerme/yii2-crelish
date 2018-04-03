<?php

namespace giantbits\crelish\plugins\relationselect;

use giantbits\crelish\components\CrelishDynamicModel;
use yii\base\Component;
use yii\helpers\Json;

class RelationSelectContentProcessor extends Component
{
  public $data;

  public static function processDataPreSave($key, $data, $fieldConfig) {

    $UUIDv4 = '/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i';

    if(isset($fieldConfig->config->autocreate) && $fieldConfig->config->autocreate === true && !preg_match($UUIDv4, $data)) {
      $model = new CrelishDynamicModel([], ['ctype'=>$fieldConfig->config->ctype]);
      $model->systitle = $data;
      $model->state = 2;
      $model->save();

      return $model->uuid;
    }

    return $data;
  }

  public static function processData($key, $data, &$processedData)
  {
    if (!empty($data)) {
      $sourceData = new CrelishDynamicModel([], ['ctype' => 'asset', 'uuid' => $data['uuid']]);

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
      $sourceData = new CrelishDynamicModel([], ['ctype' => $relatedCtype, 'uuid' => $data]);
      if ($sourceData) {
        $processedData[$key] = $sourceData;
      }
    }
  }
}
