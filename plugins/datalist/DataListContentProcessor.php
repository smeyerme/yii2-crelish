<?php
	
	namespace giantbits\crelish\plugins\datalist;
	
	use giantbits\crelish\components\CrelishDataProvider;
	
	use yii\base\Component;
	use yii\helpers\Json;
	
	class DataListContentProcessor extends Component
	{
		public $data;
		
		public static function processData($key, $data, &$processedData)
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
						$processedData[$key]['data'] = $sourceData->rawAll();
						$processedData[$key]['provider'] = $sourceData->raw();
						$processedData[$key]['ctype'] = $data['source'];
					}
				} elseif (!empty($data['temp'])) {
					$processedData[$key] = $data;
				}
			}
		}
		
		public static function processJson($ctype, $key, $data, &$processedData)
		{
			if (is_string($data)) {
				$data = Json::decode($data);
			}
			
			$processedData[$key] = $data;
		}
	}
