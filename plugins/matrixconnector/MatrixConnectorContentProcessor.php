<?php
namespace giantbits\crelish\plugins\matrixconnector;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;

class MatrixConnectorContentProcessor extends Component
{
  public $data;

  public static function processData($caller, $key, $data, &$processedData)
  {

    if (empty($processedData[$key])) {
      $processedData[$key] = [];
    }

    if ($data) {
      foreach ($data as $section => $subContent) {

        if (empty($processedData[$key][$section])) {
          $processedData[$key][$section] = '';
        }

        foreach ($subContent as $subContentdata) {
          $sourceData = new CrelishJsonDataProvider($subContentdata['ctype'], [], $subContentdata['uuid']);

          // @todo: nesting again.
          $sourceDataOut = $caller->processContent($subContentdata['ctype'], $sourceData->one());

          $processedData[$key][$section] .= $caller->renderPartial($subContentdata['ctype'] . '.twig', ['data' => $sourceDataOut]);
        }
      }

    }

  }
}
