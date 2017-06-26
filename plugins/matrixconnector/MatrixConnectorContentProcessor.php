<?php

namespace giantbits\crelish\plugins\matrixconnector;

use giantbits\crelish\components\CrelishBaseContentProcessor;
use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;
use yii\helpers\Json;
use yii\web\View;

class MatrixConnectorContentProcessor extends Component
{
    public $data;

    public static function processData($key, $data, &$processedData)
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
                    // @todo: nesting again.
                    if ($data && !empty($subContentdata['ctype']) && !empty($subContentdata['uuid'])) {
                        $fileSource = \Yii::getAlias('@app/workspace/data') . DIRECTORY_SEPARATOR . $subContentdata['ctype'] . DIRECTORY_SEPARATOR . $subContentdata['uuid'] . '.json';
                        $sourceData = Json::decode(file_get_contents($fileSource));

                    }

                    $sourceDataOut = CrelishBaseContentProcessor::processContent($subContentdata['ctype'], $sourceData);
                    if(!empty($processedData['uuid'])) {
                        $sourceDataOut['parentUuid'] = $processedData['uuid'];
                    }

                    $processedData[$key][$section] .= \Yii::$app->controller->renderPartial($subContentdata['ctype'] . '.twig', ['data' => $sourceDataOut]);
                }
            }
        }
    }
}
