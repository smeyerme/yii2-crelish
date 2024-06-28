<?php

namespace giantbits\crelish\plugins\widgetconnector;

use giantbits\crelish\components\CrelishDataProvider;
use yii\base\Component;
use yii\helpers\Json;
use yii\helpers\VarDumper;

class WidgetConnectorContentProcessor extends Component
{
  public $data;

  public static function processData($key, $data, &$processedData)
  {
		
		if(is_null($data)) {
			return;
		}
	 
		$widgetData = explode('|', $data);
    $config = explode(':', $data);

	  
    if(count($config) > 1 && $config[0] != '') {
      $widgetToLoad = "app\\workspace\\widgets\\" . $config[0] . "\\" . $config[0];
      $config = [
        'action' => $config[1],
	      'data' => !empty($widgetData[1]) ? $widgetData[1] : null
      ];
    } else {
      $widgetToLoad = "app\\workspace\\widgets\\" . $data . "\\" . $data;
      $config = null;
    }
		
    $processedData[$key] = $widgetToLoad::widget($config);
  }
}
