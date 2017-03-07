<?php
namespace giantbits\crelish\plugins\widgetconnector;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;
use yii\helpers\Json;

class WidgetConnectorContentProcessor extends Component
{
  public $data;

  public static function processData($caller, $key, $data, &$processedData)
  {
    $html = '';
    $sourceData = new CrelishJsonDataProvider('widget', [], $processedData['uuid']);
    $widgetToLoad = "app\\workspace\\widgets\\" . $sourceData->one()['widget'];

    $processedData[$key] = $widgetToLoad::widget();
  }
}
