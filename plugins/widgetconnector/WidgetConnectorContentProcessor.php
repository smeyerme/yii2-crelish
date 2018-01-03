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
        $html = '';
        //$sourceData = new CrelishDataProvider('widget', [], $processedData['uuid']);
        $widgetToLoad = "app\\workspace\\widgets\\" . $data . "\\" . $data;

        $processedData[$key] = $widgetToLoad::widget();
    }
}
