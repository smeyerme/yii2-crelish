<?php
	
	namespace giantbits\crelish\components;
	
	use Yii;
	use yii\db\BaseActiveRecord;
	use giantbits\crelish\components\CrelishBaseHelper;
	
	class CrelishTranslationBehavior extends \yii\base\Behavior
	{
		
		public bool $skipTranslation = false;
		
		public array $i18n;

		/** @var array<string,array<string,string>>|null translations set in code, written on the next save */
		private ?array $pendingTranslations = null;

		/**
		 * Attributes loadTranslations() swapped for display, with the column value and the translation.
		 * They are restored before an update so a translation never lands in the default column.
		 *
		 * @var array<string,array{original:mixed,translated:mixed}>
		 */
		private array $swapped = [];

		/** @var int > 0 while withoutTranslations() runs; loadTranslations() is then a no-op */
		private static int $suspended = 0;

		/**
		 * Runs $fn with translation loading switched off for every model, so records found
		 * inside carry their default-language column values. Nesting-safe.
		 */
		public static function withoutTranslations(callable $fn): mixed
		{
			self::$suspended++;

			try {
				return $fn();
			} finally {
				self::$suspended--;
			}
		}

		/**
		 * Translations to write on the owner's next save instead of reading the form POST.
		 * An empty value deletes that language's translation.
		 *
		 * @param array<string,array<string,string>> $byLanguage ['en' => ['label' => 'Events'], …]
		 */
		public function setTranslations(array $byLanguage): void
		{
			$this->pendingTranslations = $byLanguage;
		}
		
		public function events(): array
		{
			return [
				BaseActiveRecord::EVENT_AFTER_INSERT => 'saveTranslations',
				BaseActiveRecord::EVENT_BEFORE_UPDATE => 'restoreSwappedAttributes',
				BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
				BaseActiveRecord::EVENT_AFTER_FIND => 'loadTranslations'
			];
		}

		public function afterUpdate(): void
		{
			$this->swapped = [];
			$this->saveTranslations();
		}

		/**
		 * Puts the column value back for every attribute that still holds the translation
		 * loadTranslations() swapped in, so it is not dirty and not written. A value the
		 * caller changed is left alone and saved as usual.
		 */
		public function restoreSwappedAttributes(): void
		{
			foreach ($this->swapped as $attribute => $values) {
				if ($this->owner->{$attribute} === $values['translated']) {
					$this->owner->{$attribute} = $values['original'];
				}
			}
		}
		
		public function loadTranslations(): void
		{
			$this->swapped = [];

			if ($this->skipTranslation || self::$suspended > 0) {
				return;
			}
			
			$language = (string)Yii::$app->language;
			$short = strtok($language, '-_');
			$languages = $short !== false && $short !== $language ? [$language, $short] : [$language];
			$rows = $this->findTranslations($languages);
			
			// A regional locale (en-US) without rows of its own uses its language code (en)
			$translations = array_values(array_filter($rows, fn($row) => $row['language'] === $language));
			
			if (!$translations) {
				$translations = $rows;
			}
			
			$translationsByAttribute = [];
			
			foreach ($translations as $row) {
				$attribute = $row['source_model_attribute'];
				$translationsByAttribute[$attribute] = $row['translation'];
			}
			
			if (count($translationsByAttribute) > 0) {
				foreach ($translationsByAttribute as $attribute => $translation) {
					$original = $this->owner->{$attribute} ?? null;

					if (isset($this->owner->{$attribute}) && is_array($this->owner->{$attribute})) {
						$this->owner->{$attribute} = array_merge($this->owner->{$attribute}, [$translation]);
					} else {
						$this->owner->{$attribute} = $translation;
					}

					// Arrays too: the merged array is compared as a whole and the original put back
					$this->swapped[$attribute] = ['original' => $original, 'translated' => $this->owner->{$attribute}];
				}
			}
		}
		
		private function findTranslations(array $languages): array
		{
			return \giantbits\crelish\models\CrelishTranslation::find()
				->where([
					'language' => $languages,
					'source_model' => $this->owner->tableName(),
					'source_model_uuid' => $this->owner->uuid, // $this->owner is the model instance
				])->asArray()->all();
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

			if ($this->pendingTranslations !== null) {
				$pending = $this->pendingTranslations;
				$this->pendingTranslations = null;
				$this->writeTranslations($pending, true);
				return;
			}

			$request = \Yii::$app->request;

			if (!$request instanceof \yii\web\Request) {
				Yii::debug('No web request (console context), skipping translation save', __METHOD__);
				return;
			}

			$postData = $request->post('CrelishDynamicModel', []);

			if(empty($postData['i18n'])) {
				Yii::debug('No i18n data in POST, skipping translation save', __METHOD__);
				return;
			}

			$this->writeTranslations($postData['i18n'], false);
		}

		/**
		 * @param array $byLanguage language => [attribute => value]
		 * @param bool $deleteEmpty true: an empty value removes the stored translation; false: it is skipped
		 */
		private function writeTranslations(array $byLanguage, bool $deleteEmpty): void
		{
			foreach ($byLanguage as $lang => $attributes) {
				if (!is_array($attributes)) {
					continue;
				}

				foreach ($attributes as $attribute => $value) {
					// A form post can send nested values; they cannot be stored as a translation
					if (!$deleteEmpty && (is_array($value) || is_object($value))) {
						continue;
					}

					$criteria = [
						'language' => $lang,
						'source_model' => $this->owner->tableName(),
						'source_model_attribute' => $attribute,
						'source_model_uuid' => $this->owner->uuid,
					];

					if ($value === '' || $value === null) {
						if ($deleteEmpty) {
							\giantbits\crelish\models\CrelishTranslation::deleteAll($criteria);
						}
						continue;
					}

					$translation = \giantbits\crelish\models\CrelishTranslation::find()->where($criteria)->one();

					if (!$translation) {
						$translation = new \giantbits\crelish\models\CrelishTranslation();
						$translation->uuid = CrelishBaseHelper::GUIDv4();
						$translation->language = $lang;
						$translation->source_model = $this->owner->tableName();
						$translation->source_model_attribute = $attribute;
						$translation->source_model_uuid = $this->owner->uuid;
					}

					$translation->translation = $value;

					if (!$translation->save()) {
						Yii::error('Failed to save translation for ' . $attribute . ' (' . $lang . '): ' . json_encode($translation->errors), __METHOD__);
					}
				}
			}
		}
	}
