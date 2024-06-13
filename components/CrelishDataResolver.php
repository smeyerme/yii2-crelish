<?php
	
	namespace giantbits\crelish\components;
	
	use yii\data\ActiveDataProvider;
	
	class CrelishDataResolver
	{
		
		public static function resolveModel($modelInfo)
		{
			
			if (str_contains($modelInfo['ctype'], 'db:')) {
				$model = str_replace('db:', '', $modelInfo['ctype']);
				return call_user_func_array('app\workspace\models\\' . ucfirst($model) . '::find', ['uuid' => $modelInfo['uuid']])->one();
			}
			
			return new CrelishDynamicJsonModel([], ['ctype' => $modelInfo['ctype'], 'uuid' => $modelInfo['uuid']]);
		}
		
		public static function resolveProvider($ctype, $options)
		{
			
			$elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);
			
			if (property_exists($elementDefinition, 'storage') && $elementDefinition->storage === 'db') {
				$query = call_user_func('app\workspace\models\\' . ucfirst($ctype) . '::find');
				if(!empty($options['filter'])) {
					$query->where($options['filter']);
				}
				return new ActiveDataProvider([
					'query' => $query,
					'key' => 'uuid',
				]);
			}
			
			return new CrelishJsonDataProvider($ctype, $options);
		}
	}
