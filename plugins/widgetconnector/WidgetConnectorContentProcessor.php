<?php

namespace giantbits\crelish\plugins\widgetconnector;

use giantbits\crelish\components\CrelishDataProvider;
use yii\base\Component;
use yii\helpers\Json;

class WidgetConnectorContentProcessor extends Component
{
  public $data;

  public static function processData($key, $data, &$processedData)
  {

    $config = explode(':', $data);

    if(count($config) > 1) {
      $widgetToLoad = "app\\workspace\\widgets\\" . $config[0] . "\\" . $config[0];
      $config = [
        'action' => $config[1]
      ];
    } else {
      $widgetToLoad = "app\\workspace\\widgets\\" . $data . "\\" . $data;
      $config = null;
    }

    $processedData[$key] = $widgetToLoad::widget($config);
  }
}
