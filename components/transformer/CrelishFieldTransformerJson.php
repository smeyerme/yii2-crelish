<?php
	
	namespace giantbits\crelish\components\transformer;
	
	use yii\helpers\Json;
	
	/**
	 *
	 */
	class CrelishFieldTransformerJson extends CrelishFieldBaseTransformer
	{
		
		/**
		 * [transform description]
		 * @param  [type] $value [description]
		 * @return [type]        [description]
		 */
		public static function beforeSave(&$value)
		{
			$value = Json::encode($value);
		}
		
		public static function afterFind(&$value)
		{
			$value = Json::decode($value);
		}
	}
