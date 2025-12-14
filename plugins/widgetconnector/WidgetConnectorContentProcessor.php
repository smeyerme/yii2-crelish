<?php

namespace giantbits\crelish\plugins\widgetconnector;

use yii\base\Component;
use yii\helpers\Json;

class WidgetConnectorContentProcessor extends Component
{
    public $data;

    public static function processData($key, $data, &$processedData, $config): void
    {
        if (is_null($data)) {
            return;
        }

        // Handle options - can be JSON string or already decoded array
        $options = [];
        if (!empty($data->options)) {
            if (is_string($data->options)) {
                try {
                    $options = Json::decode($data->options);
                } catch (\Exception $e) {
                    $options = [];
                }
            } elseif (is_array($data->options)) {
                $options = $data->options;
            } elseif (is_object($data->options)) {
                $options = (array)$data->options;
            }
        }

        // Get widget name - support both 'widgetType' and legacy 'widget' field names
        $widget = !empty($data->widgetType) ? $data->widgetType : (!empty($data->widget) ? $data->widget : null);
        $widgetAction = null;

        if (empty($widget)) {
            return;
        }

        // Handle widget:action syntax
        if (str_contains($widget, ":")) {
            [$widget, $widgetAction] = explode(':', $widget, 2);
        }

        $widgetToLoad = "app\\workspace\\widgets\\{$widget}\\{$widget}";

        // Verify class exists
        if (!class_exists($widgetToLoad)) {
            return;
        }

        // Add action to options if specified
        if (!empty($widgetAction) && property_exists($widgetToLoad, 'action')) {
            $options['action'] = $widgetAction;
        }

        // Pass options via 'data' property if widget supports it
        if (count($options) >= 1 && property_exists($widgetToLoad, 'data')) {
            $options['data'] = $options;
        }

        $processedData[$key] = $widgetToLoad::widget($options);
    }
}