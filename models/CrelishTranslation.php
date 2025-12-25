<?php

namespace giantbits\crelish\models;

class CrelishTranslation extends \yii\db\ActiveRecord
{
	public $ctype = 'translation';

	public static function tableName(): string
	{
		return 'translation';
	}

	public static function primaryKey(): array
	{
		return ['uuid'];
	}

	public function rules(): array
	{
		return [
			[['uuid', 'language', 'source_model', 'source_model_uuid', 'source_model_attribute'], 'required'],
			[['uuid', 'source_model_uuid'], 'string', 'max' => 36],
			[['language'], 'string', 'max' => 5],
			[['source_model', 'source_model_attribute'], 'string', 'max' => 128],
			[['translation'], 'safe'],
		];
	}
}
