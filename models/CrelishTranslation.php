<?php
	
	namespace giantbits\crelish\models;
	
	class CrelishTranslation extends \yii\db\ActiveRecord
	{
		
		public $ctype = 'translation';
		
		public static function tableName(): string
		{
			return 'translation';
		}
	}
