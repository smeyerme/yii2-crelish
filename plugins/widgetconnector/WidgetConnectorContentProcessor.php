<?php
	
	namespace giantbits\crelish\plugins\widgetconnector;
	
	use giantbits\crelish\components\CrelishDataProvider;
	use yii\base\Component;
	use yii\helpers\Json;
	use yii\helpers\VarDumper;
	
	class WidgetConnectorContentProcessor extends Component
	{
		public $data;
		
		public static function processData($key, $data, &$processedData): void
		{
			$options = !empty($data->options) ? Json::decode($data->options) : [];
			$widget = !empty($data->widget) ? $data->widget : null;
			$widgetAction = null;
			$widgetData = null;
			
			if (is_null($data)) {
				return;
			}
			
			/*if (str_contains($data->widget, "|")) {
				$widgetData = explode('|', $data->widget);
			}*/
			
			if (str_contains($widget, ":")) {
				$widgetAction = explode(':', $widget)[1];
				$widget = explode(':', $widget)[0];
			}
			
			if (count($options) > 1 && !empty($widget)) {
				$widgetToLoad = "app\\workspace\\widgets\\" . $widget . "\\" . $widget;
				$options['action'] = $widgetAction;
				
				if (property_exists($widgetToLoad, 'data')) {
					$options['data'] = $options;
				}
			} else {
				$widgetToLoad = "app\\workspace\\widgets\\" . $widget . "\\" . $widget;
				$options = null;
			};
			
			$processedData[$key] = $widgetToLoad::widget($options);
		}
	}
