<?php

namespace giantbits\crelish\plugins\datalist;

use giantbits\crelish\components\CrelishDataProvider;
use Underscore\Types\Arrays;
use yii\base\Component;
use yii\helpers\Json;

class DataListContentProcessor extends Component
{
    public $data;

    public static function processData($key, $data, &$processedData)
    {

        $data = Json::decode($data);

        if (empty($processedData[$key])) {
            $processedData[$key] = [];
        }

        if ($data) {
            $filters = null;
            $sort = null;
            $limit = null;

            if (!empty($_GET['freesearch']))
                $data['filter'][] = 'freesearch';

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

            if (Arrays::has($data, 'limit')) {
                if ($data['limit'] === false) {
                    $limit = 99999;
                } else {
                    $limit = $data['limit'];
                }
            }

            $sourceData = new CrelishDataProvider($data['source'], [
                'filter' => $filters,
                'sort' => $sort,
                'limit' => $limit
            ]);

            $processedData[$key]['raw'] = $sourceData->rawAll();
            $processedData[$key]['provider'] = $sourceData->raw();
            $processedData[$key]['ctype'] = $data['source'];
        }
    }

    public static function processJson($key, $data, &$processedData)
    {
        if (is_string($data)) {
            $data = Json::decode($data);
        }

        if ($data) {

            if (empty($processedData[$key])) {
                $processedData[$key] = [];
            }

            if (!empty($data['source'])) {
                $sourceData = new CrelishDataProvider($data['source']);

                if ($sourceData) {
                    $processedData[$key] = $sourceData->rawAll();
                }
            } elseif (!empty($data['temp'])) {
                $processedData[$key] = $data;
            }
        }
    }
}
