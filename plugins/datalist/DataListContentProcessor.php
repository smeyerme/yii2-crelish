<?php

namespace giantbits\crelish\plugins\datalist;

use giantbits\crelish\components\CrelishJsonDataProvider;
use Underscore\Types\Arrays;
use yii\base\Component;

class DataListContentProcessor extends Component
{
    public $data;

    public static function processData($key, $data, &$processedData)
    {

        if (empty($processedData[$key])) {
            $processedData[$key] = [];
        }

        if ($data) {
            $filters = NULL;
            $sort = NULL;
            $limit = NULL;

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
                $sort['by'] = (!empty($data['sort']['by'])) ? $data['sort']['by'] : NULL;
                $sort['dir'] = (!empty($data['sort']['dir'])) ? $data['sort']['dir'] : NULL;
            }

            if (Arrays::has($data, 'limit')) {
                if ($data['limit'] === FALSE) {
                    $limit = 99999;
                } else {
                    $limit = $data['limit'];
                }
            }

            $sourceData = new CrelishJsonDataProvider($data['source'], [
                'filter' => $filters,
                'sort' => $sort,
                'limit' => $limit
            ]);

            $processedData[$key] = $sourceData->raw();

        }
    }
}
