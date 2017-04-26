<?php

namespace giantbits\crelish\plugins\assetconnector;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;
use yii\helpers\Json;

class AssetConnectorContentProcessor extends Component
{
    public $data;

    public static function processData($key, $data, &$processedData)
    {
        if (empty($processedData[$key])) {
            $processedData[$key] = [];
        }

        if (is_array($data) && sizeOf($data) > 0) {
            $provider = new CrelishJsonDataProvider('asset', [], $data['uuid']);
            $processedData[$key] = $provider->one(); //Json::decode(file_get_contents($fileSource));
        }
    }

    public static function processJson($key, $data, &$processedData)
    {

        if (empty($processedData[$key])) {
            $processedData[$key] = [];
        }

        if ($data) {
            if(!empty($data['uuid'])){
                $fileSource = \Yii::getAlias('@app/workspace/data') . DIRECTORY_SEPARATOR . 'asset' . DIRECTORY_SEPARATOR . $data['uuid'] . '.json';
                $processedData[$key] = Json::decode(file_get_contents($fileSource));
            } elseif (!empty($data['temp'])) {
                $processedData[$key] = $data;
            }
        }
    }
}
