<?php

namespace giantbits\crelish\plugins\datainclude;

use giantbits\crelish\components\CrelishBaseContentProcessor;
use yii\base\Component;
use yii\helpers\Json;

class DataIncludeContentProcessor extends Component
{
    public $data;

    public static function processData($key, $data, &$processedData)
    {

        if (empty($processedData[$key])) {
            $processedData[$key] = [];
        }

        /*
        if ($data) {
            $filters = null;
            $sort = null;
            $limit = null;

            if (!empty($data['filter'])) {
                foreach ($data['filter'] as $filter) {
                    if (is_array($filter)) {
                        $filters[key($filter)] = $filter[key($filter)];
                    } elseif (!empty($_GET[$filter])) {
                        $filters[$filter] = $_GET[$filter];
                    }
                }
            }

            if (!empty($data['sort'])) {
                $sort['by'] = (!empty($data['sort']['by'])) ? $data['sort']['by'] : null;
                $sort['dir'] = (!empty($data['sort']['dir'])) ? $data['sort']['dir'] : null;
            }

            if (!empty($data['limit'])) {
                if ($data['limit'] === false) {
                    $limit = 99999;
                } else {
                    $limit = $data['limit'];
                }
            }

            $sourceData = new CrelishJsonDataProvider($data['source'], ['filter' => $filters, 'sort' => $sort, 'limit' => $limit]);
            $processedData[$key] = $sourceData->raw();

        }
        */
    }

    public static function processJson($key, $data, &$processedData)
    {

        if (empty($processedData[$key])) {
            $processedData[$key] = [];
        }

        if ($data && !empty($data['ctype']) && !empty($data['uuid'])) {
            $fileSource = \Yii::getAlias('@app/workspace/data') . DIRECTORY_SEPARATOR . $data['ctype'] . DIRECTORY_SEPARATOR . $data['uuid'] . '.json';
            $dataOut = CrelishBaseContentProcessor::processContent($data['ctype'], Json::decode(file_get_contents($fileSource)));
            $processedData[$key] = $dataOut;
        }
    }
}
