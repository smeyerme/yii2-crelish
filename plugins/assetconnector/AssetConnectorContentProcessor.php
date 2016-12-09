<?php
namespace giantbits\crelish\plugins\assetconnector;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;

class AssetConnectorContentProcessor extends Component
{
  public $data;

  public static function processData($caller, $key, $data, &$processedData)
  {
    if (empty($processedData[$key])) {
      $processedData[$key] = [];
    }

    if (is_array($data) && sizeOf($data) > 0) {
	    $include = new CrelishJsonDataProvider('asset', [], $data['uuid']);
	    $processedData[$key] = $include->one();
  	}
  }

  public static function processJson($caller, $key, $data, &$processedData)
  {

    if (empty($processedData[$key])) {
      $processedData[$key] = [];
    }

    if ($data && !empty($data['ctype'])) {
      $sourceData = new CrelishJsonDataProvider($data['ctype'], [], $data['uuid']);

      $processedData[$key] = $sourceData->one();
    }
  }
}
