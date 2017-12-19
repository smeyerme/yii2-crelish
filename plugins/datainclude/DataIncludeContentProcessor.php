<?php

namespace giantbits\crelish\plugins\datainclude;

use giantbits\crelish\components\CrelishBaseContentProcessor;
use giantbits\crelish\components\CrelishDynamicModel;
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
      $fileSource = \Yii::getAlias('@app/workspace/data') . DIRECTORY_SEPARATOR . $data['ctype'] . DIRECTORY_SEPARATOR . $data['uuid'] . '.json';

      if (file_exists($fileSource)) {
        $dataOut = CrelishBaseContentProcessor::processContent($data['ctype'], Json::decode(file_get_contents($fileSource)));
        $processedData[$key] = $dataOut;
      }
    }
  }

  public static function processJson($key, $data, &$processedData)
  {
    if(is_string($data)) {
      $data = Json::decode($data);
    }

    if (empty($processedData[$key])) {
      $processedData[$key] = [];
    }

    if ($data) {
      if(!empty($data['uuid'])){
        $sourceData =  new CrelishDynamicModel([], ['ctype'=>$data['ctype'], 'uuid'=>$data['uuid']]);
        if($sourceData){
          $processedData[$key] = $sourceData;
        }
      } elseif (!empty($data['temp'])) {
        $processedData[$key] = $data;
      }
    }
  }
}
