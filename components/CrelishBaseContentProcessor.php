<?php
/**
 * Created by PhpStorm.
 * User: myrst
 * Date: 12.04.2017
 * Time: 21:52
 */

namespace giantbits\crelish\components;


use Underscore\Types\Arrays;
use yii\base\Component;

class CrelishBaseContentProcessor extends Component
{
    public $data;

    public static function processData($key, $data, &$processedData)
    {
        $processedData = $processedData;
    }

    public static function processJson($key, $data, &$processedData)
    {
        $processedData = $processedData;
    }

    public static function processContent($ctype, $data)
    {
        $processedData = [];

        $elementDefinition =  CrelishDynamicJsonModel::loadElementDefinition($ctype);

        if ($data) {

            foreach ($data as $key => $content) {

                $fieldType = Arrays::find($elementDefinition->fields, function ($value) use ($key) {
                    return $value->key == $key;
                });

                if (!empty($fieldType) && is_object($fieldType)) {
                    $fieldType = $fieldType->type;
                }

                if (!empty($fieldType)) {
                    // Get processor class.
                    $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';

                    if (strpos($fieldType, "widget_") !== false) {
                        $processorClass = str_replace("widget_", "", $fieldType) . 'ContentProcessor';
                    }

                    if (class_exists($processorClass)) {
                        $processorClass::processData($key, $content, $processedData);
                    } else {
                        $processedData[$key] = $content;
                    }
                }
            }
        }

        return $processedData;
    }
}
