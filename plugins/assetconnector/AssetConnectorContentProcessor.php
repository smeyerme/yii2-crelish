<?php

namespace giantbits\crelish\plugins\assetconnector;

use giantbits\crelish\components\CrelishDynamicModel;
use yii\base\Component;
use yii\helpers\Json;

class AssetConnectorContentProcessor extends Component
{
  public $data;

  public static function processData($key, $data, &$processedData)
  {
    if (is_string($data)) {
      $data = Json::decode($data);
    }

    if (empty($processedData[$key])) {
      $processedData[$key] = [];
    }

    if (!empty($data['uuid'])) {
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
    $definition = CrelishDynamicModel::loadElementDefinition('asset');

    // Get relation ctype.
    if ($data) {
      $sourceData = new CrelishDynamicModel([], ['ctype' => 'asset', 'uuid' => $data]);

      if ($sourceData) {
        $processedData[$key] = $sourceData;
      }
    }
  }
}
