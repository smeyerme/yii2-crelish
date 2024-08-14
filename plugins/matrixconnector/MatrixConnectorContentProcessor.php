<?php
	
	namespace giantbits\crelish\plugins\matrixconnector;
	
	use giantbits\crelish\components\CrelishBaseContentProcessor;
	use giantbits\crelish\components\CrelishDataProvider;
	use giantbits\crelish\components\CrelishDynamicModel;
	use giantbits\crelish\components\CrelishJsonDataProvider;
	use yii\base\Component;
	use yii\helpers\Json;
	use yii\helpers\VarDumper;
	use yii\web\View;
	
	class MatrixConnectorContentProcessor extends Component
	{
		public $data;
		
		public static function processData($key, $data, &$processedData): void
		{
			if (empty($processedData[$key])) {
				$processedData[$key] = [];
			}
			
			if ($data && $data != '{"main":[]}') {
				
				if (is_string($data)) {
					$data = Json::decode(stripcslashes(trim($data, '"')));
				}
				
				foreach ($data as $section => $subContent) {
					
					if (empty($processedData[$key][$section])) {
						$processedData[$key][$section] = '';
					}
					
					foreach ($subContent as $subContentdata) {
						// @todo: nesting again.
						if ($data && !empty($subContentdata['ctype']) && !empty($subContentdata['uuid'])) {
							$sourceData = new CrelishDynamicModel([], ['ctype' => $subContentdata['ctype'], 'uuid' => $subContentdata['uuid']]);
						}
						
						$sourceDataOut = CrelishBaseContentProcessor::processContent($subContentdata['ctype'], $sourceData);
						
						if (!empty($processedData['uuid'])) {
							$sourceDataOut['parentUuid'] = $processedData['uuid'];
						}
						
						$view = file_exists(\Yii::$app->view->theme->basePath . '/frontend/elements/'.$subContentdata['ctype'] . '.twig') ? 'elements/'.$subContentdata['ctype'] . '.twig' : $subContentdata['ctype'] . '.twig';
						$processedData[$key][$section] .= \Yii::$app->controller->renderPartial($view, ['data' => $sourceDataOut]);
					}
				}
			}
		}
	}
