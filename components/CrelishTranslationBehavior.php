<?php
	
	namespace giantbits\crelish\components;
	
	use Yii;
	use yii\db\BaseActiveRecord;
	use giantbits\crelish\components\CrelishBaseHelper;
	
	class CrelishTranslationBehavior extends \yii\base\Behavior
	{
		
		public bool $skipTranslation = false;
		
		public array $i18n;
		
		public function events(): array
		{
			return [
				BaseActiveRecord::EVENT_AFTER_INSERT => 'saveTranslations',
				BaseActiveRecord::EVENT_AFTER_UPDATE => 'saveTranslations',
				BaseActiveRecord::EVENT_AFTER_FIND => 'loadTranslations'
			];
		}
		
		public function loadTranslations(): void
		{
			if ($this->skipTranslation) {
				return;
			}
			
			$translations = \giantbits\crelish\models\CrelishTranslation::find()
				->where([
					'language' => Yii::$app->language,
					'source_model' => $this->owner->tableName(),
					'source_model_uuid' => $this->owner->uuid, // $this->owner is the model instance
				])->asArray()->all();
			
			$translationsByAttribute = [];
			
			foreach ($translations as $row) {
				$attribute = $row['source_model_attribute'];
				$translationsByAttribute[$attribute] = $row['translation'];
			}
			
			if (count($translationsByAttribute) > 0) {
				foreach ($translationsByAttribute as $attribute => $translation) {
					if (isset($this->owner->{$attribute}) && is_array($this->owner->{$attribute})) {
						$this->owner->{$attribute} = array_merge($this->owner->{$attribute}, [$translation]);
					} else {
						$this->owner->{$attribute} = $translation;
					}
				}
			}
		}
		
		public function loadAllTranslations(): array
		{
			$allTranslations = \giantbits\crelish\models\CrelishTranslation::find()
				->where([
					'source_model' => $this->owner->tableName(),
					'source_model_uuid' => $this->owner->uuid,
				])->asArray()->all();
			
			$translationsByAttributeAndLanguage = [];
			
			foreach ($allTranslations as $row) {
				$attribute = $row['source_model_attribute'];
				$language = $row['language'];
				if (!isset($translationsByAttributeAndLanguage[$attribute])) {
					$translationsByAttributeAndLanguage[$attribute] = [];
				}
				$translationsByAttributeAndLanguage[$attribute][$language] = $row['translation'];
			}
			
			return $translationsByAttributeAndLanguage;
		}
		
		
		public function saveTranslations(): void
		{
			if ($this->skipTranslation) {
				return;
			}

			$postData = \Yii::$app->request->post('CrelishDynamicModel', []);

			if(empty($postData['i18n'])) {
				Yii::debug('No i18n data in POST, skipping translation save', __METHOD__);
				return;
			}
			
			foreach ($postData['i18n'] as $lang => $attributes) {
				
				if (is_array($attributes)) { // This means translations are present
					foreach ($attributes as $attribute => $value) {
						
						if(empty($value)) {
							continue;
						}
						
						$translation = \giantbits\crelish\models\CrelishTranslation::find()
							->where([
								'language' => $lang,
								'source_model' => $this->owner->tableName(),
								'source_model_attribute' => $attribute,
								'source_model_uuid' => $this->owner->uuid,
							])->one();

						if (!$translation) {
							$translation = new \giantbits\crelish\models\CrelishTranslation();
							$translation->uuid = CrelishBaseHelper::GUIDv4();
							$translation->language = $lang;
							$translation->source_model = $this->owner->tableName();
							$translation->source_model_attribute = $attribute;
							$translation->source_model_uuid = $this->owner->uuid;
						}

						$translation->translation = $value;

						$saveResult = $translation->save();

						if (!$saveResult) {
							Yii::error('Failed to save translation for ' . $attribute . ' (' . $lang . '): ' . json_encode($translation->errors), __METHOD__);
						}
					}
				}
			}
		}
	}
