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
      $sourceData = new CrelishDynamicModel( ['ctype' => 'asset', 'uuid' => $data['uuid']]);

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
    if (empty($data)) {
      return;
    }

    // Check if this is a multiple asset field (JSON array of UUIDs)
    // If data starts with '[', it's a JSON array string - pass through as-is
    if (is_string($data) && str_starts_with(trim($data), '[')) {
      // Multiple mode: keep the JSON string as-is for the plugin to handle
      $processedData[$key] = $data;
      return;
    }

    // Check if it's already an array
    if (is_array($data)) {
      // Multiple mode with decoded array - re-encode as JSON
      $processedData[$key] = Json::encode($data);
      return;
    }

    // Single UUID mode: load the asset model
    $sourceData = new CrelishDynamicModel(['ctype' => 'asset', 'uuid' => $data]);

    if ($sourceData) {
      $processedData[$key] = $sourceData;
    }
  }
}
