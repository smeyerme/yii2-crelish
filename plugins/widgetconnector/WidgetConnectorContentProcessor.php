<?php
	
	namespace giantbits\crelish\plugins\widgetconnector;
	
	use giantbits\crelish\components\CrelishDataProvider;
	use yii\base\Component;
	use yii\helpers\Json;
	use yii\helpers\VarDumper;
	
	class WidgetConnectorContentProcessor extends Component
	{
		public $data;
		
		public static function processData($key, $data, &$processedData, $config): void
		{
			$options = !empty($data->options) ? Json::decode($data->options) : [];
			$widget = !empty($data->widget) ? $data->widget : null;
			$widgetAction = null;
			
			if (is_null($data)) {
				return;
			}
			
			if (str_contains($widget, ":")) {
				$widgetAction = explode(':', $widget)[1];
				$widget = explode(':', $widget)[0];
			}
			
			$widgetToLoad = "app\\workspace\\widgets\\" . $widget . "\\" . $widget;
			
			if (count($options) >= 1 && !empty($widget)) {
				if(!empty($widgetAction)) {
					$options['action'] = $widgetAction;
				}
				
				if (property_exists($widgetToLoad, 'data')) {
					$options['data'] = $options;
				}
			} else {
				if(property_exists($widgetToLoad, 'action')) {
					$options['action'] = $widgetAction;
				}
			};
			
			$processedData[$key] = $widgetToLoad::widget($options);
		}
	}
