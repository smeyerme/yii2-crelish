<?php

namespace giantbits\crelish\plugins\matrixconnector;

use giantbits\crelish\components\CrelishBaseContentProcessor;
use giantbits\crelish\components\CrelishDataProvider;
use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;
use yii\helpers\Json;
use yii\web\View;

class MatrixConnectorContentProcessor extends Component
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

    if ($data) {
      foreach ($data as $section => $subContent) {

        if (empty($processedData[$key][$section])) {
          $processedData[$key][$section] = '';
        }

        foreach ($subContent as $subContentdata) {
          // @todo: nesting again.
          if ($data && !empty($subContentdata['ctype']) && !empty($subContentdata['uuid'])) {
            $sourceData = new CrelishDynamicModel([], ['ctype' => $subContentdata['ctype'], 'uuid' => $subContentdata['uuid']]);
          }

          $sourceDataOut = CrelishBaseContentProcessor::processContent($subContentdata['ctype'], $sourceData);

          if (!empty($processedData['uuid'])) {
            $sourceDataOut['parentUuid'] = $processedData['uuid'];
          }

          $processedData[$key][$section] .= \Yii::$app->controller->renderPartial($subContentdata['ctype'] . '.twig', ['data' => $sourceDataOut]);
        }
      }
    }
  }
}
