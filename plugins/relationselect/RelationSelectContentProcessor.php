<?php

namespace giantbits\crelish\plugins\relationselect;

use giantbits\crelish\components\CrelishDynamicModel;
use yii\base\Component;
use yii\helpers\Json;

class RelationSelectContentProcessor extends Component
{
  public $data;

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
