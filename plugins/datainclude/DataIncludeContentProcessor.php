<?php

namespace giantbits\crelish\plugins\datainclude;

use giantbits\crelish\components\CrelishBaseContentProcessor;
use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;
use yii\helpers\Json;

class DataIncludeContentProcessor extends CrelishBaseContentProcessor
{
    public $data;

    public static function processData($key, $data, &$processedData)
    {
        if (empty($processedData[$key])) {
            $processedData[$key] = [];
        }

        if ($data && !empty($data['ctype']) && !empty($data['uuid'])) {
            $dataProvider = new CrelishJsonDataProvider($data['ctype'], [], $data['uuid']);

            //$fileSource = \Yii::getAlias('@app/workspace/data') . DIRECTORY_SEPARATOR . $data['ctype'] . DIRECTORY_SEPARATOR . $data['uuid'] . '.json';

            //if (file_exists($fileSource)) {
            $dataOut = CrelishBaseContentProcessor::processContent($data['ctype'], $dataProvider->one() );
            $processedData[$key] = $dataOut;
            //}
        }
    }

    public static function processJson($key, $data, &$processedData)
    {

        if (empty($processedData[$key])) {
            $processedData[$key] = [];
        }

        if ($data && !empty($data['ctype']) && !empty($data['uuid'])) {
            $fileSource = \Yii::getAlias('@app/workspace/data') . DIRECTORY_SEPARATOR . $data['ctype'] . DIRECTORY_SEPARATOR . $data['uuid'] . '.json';

            if (file_exists($fileSource)) {
                $dataOut = CrelishBaseContentProcessor::processContent($data['ctype'], Json::decode(file_get_contents($fileSource)));
                $processedData[$key] = $dataOut;
            }
        }
    }
}
