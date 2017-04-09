<?php

namespace giantbits\crelish\plugins\datainclude;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;

class DataIncludeContentProcessor extends Component
{
    public $data;

    public static function processData($caller, $key, $data, &$processedData)
    {

        if (empty($processedData[$key])) {
            $processedData[$key] = [];
        }

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
    }

    public static function processJson($caller, $key, $data, &$processedData)
    {

        if (empty($processedData[$key])) {
            $processedData[$key] = [];
        }

        if ($data && !empty($data['ctype'])) {
            $sourceData = new CrelishJsonDataProvider($data['ctype'], [], $data['uuid']);

            $processedData[$key] = $sourceData->one();
        }
    }
}
