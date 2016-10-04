<?php
namespace giantbits\crelish\plugins\assetconnector;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;

class AssetConnectorContentProcessor extends Component
{
  public $data;

  public static function processData($caller, $key, $data, &$processedData)
  {

    $include = new CrelishJsonDataProvider('asset', [], $data['uuid']);
    $processedData[$key] = $include->one();
  }
}
