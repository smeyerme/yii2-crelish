<?php

	namespace giantbits\crelish\components;
	
	use Yii;
	use yii\db\ActiveRecord;
	use yii\helpers\Json;
  use function _\find;

  class CrelishJsonModel extends ActiveRecord
	{
		protected static $jsonConfig;
		public static $key;

		public function __get($name)
		{
			if (!empty(static::$jsonConfig->jsonField) && static::$jsonConfig->jsonField != $name) {
				$jsonField = static::$jsonConfig->jsonField;

				if (is_array($this->getAttribute($jsonField))) {
					if (array_key_exists($name, $this->getAttribute($jsonField))) {
						return $this->getAttribute($jsonField)[$name];
					}
				}
			}
			return parent::__get($name);
		}

		public function __set($name, $value)
		{
			$jsonField = static::$jsonConfig->jsonField;

			if (is_array($this->$jsonField) && array_key_exists($name, $this->$jsonField)) {
				$this->$jsonField[$name] = $value;
				$this->$name = $value;
			} else {
				parent::__set($name, $value);
			}
		}

		public function jsonAttributes()
		{
			$attributes = [];
			foreach (static::$jsonConfig->fields as $field) {
				if (isset($field->jsonAttribute) && $field->jsonAttribute) {
					$attributes[] = $field->key;
				}
			}
			return $attributes;
		}

		public function attributes()
		{
			return array_merge(parent::attributes(), static::jsonAttributes());
		}

		public function beforeSave($insert)
		{
			$jsonField = static::$jsonConfig->jsonField;
			$jsonData = [];

			if ($jsonField) {
				foreach (static::$jsonConfig->fields as $field) {
					if (isset($field->jsonAttribute) && $field->jsonAttribute) {
						$key = $field->key;

						if (!empty($this->$key)) {
							$jsonData[$key] = $this->$key;
						}

						unset($this->$key);
					}
				}
				$this->$jsonField = $jsonData;
			}

			$this->$jsonField = json_encode($this->$jsonField);

      if (!parent::beforeSave($insert)) {
        return false;
      }

			return true;
		}

		public function afterFind()
		{
			parent::afterFind();

			$jsonField = static::$jsonConfig->jsonField;
			$this->$jsonField = json_decode($this->$jsonField, true);

      if(is_array($this->$jsonField)) {
        foreach ($this->$jsonField as $key => $value) {
          // Get transformer.
          $this->$key = $value;
        }
      }
		}

		public static function tableName()
		{
			if (empty(static::$jsonConfig)) {
				static::loadJsonConfig(static::$key);
			}
			return static::$jsonConfig->key;
		}

		public static function jsonRules()
		{
			$rule = [
				"customBillingAddress" => 2
			];

			$rules = [];
			foreach (static::$jsonConfig->fields as $field) {
				$key = $field->key;

				foreach ($field->rules as $ruleO) {
					$rule = json_decode(json_encode($ruleO), true);
					if (empty($rule[1])) {
						$rules[] = [$key, $rule[0]];
					} else {
						static::decodeRule($rule);
						$rules[] = array_merge([$key], $rule);
					}
				}
			}

			return $rules;
		}

		private static function decodeRule(&$rule)
		{

			foreach ($rule as $index => $element) {
				if (is_array($element)) {

					foreach ($element as $key => $value) {

						if ($key == 'when') {
							$rule[$key] = function ($model, $attribute) use ($value) {
								$relatedField = key($value);
								$compareValue = $value[$relatedField];
								if($compareValue === 'notUndefined') {
									return !empty($model->{$relatedField});
								}
								return $model->{$relatedField} === $value[$relatedField];
							};
						} else {
							$rule[$key] = $value;
						}
						unset($rule[$index]);
					}
					unset($rule[$index]);
				}
			}
		}

		public static function jsonLabels()
		{
			$labels = [];
			foreach (static::$jsonConfig->fields as $field) {
				$labels[$field->key] = $field->label;
			}
			return $labels;
		}

		public static function formFields()
		{
			return static::$jsonConfig->fields;
		}

		public static function formTabs()
		{
			return static::$jsonConfig->tabs;
		}

		public function rules()
		{
			return array_merge(parent::rules(), static::jsonRules());
		}

		public function attributeLabels()
		{

			$labels = array_merge(parent::attributeLabels(), static::jsonLabels());

			return array_map(function ($l) {
				return Yii::t('app', $l);
			}, $labels);
		}

		public static function loadJsonConfig($key)
		{
			$json = file_get_contents(Yii::getAlias('@app/workspace/elements/' . $key . '.json'));
			static::$jsonConfig = Json::decode($json, false);
		}

		public static function getFieldConfig($field) {
			$config = find(static::formFields(), function($itm) use ($field) {
				return $itm->key === $field;
			});

			return !empty($config) ? $config : null;
		}

		public static function generateFormFields($form)
		{
			$fields = static::formFields();
			$output = '';

			foreach ($fields as $field) {
				$type = $field['type'];
				switch ($type) {
					case 'textInput':
						$output .= $form->field(static::getInstance(), $field['key'])->textInput();
						break;
					case 'checkboxList':
						$output .= $form->field(static::getInstance(), $field['key'])->checkboxList($field['items'], $field['options']);
						break;
					// Add other input types as needed
				}
			}

			return $output;
		}

		public static function getGridColumns()
		{
			$fields = static::formFields();
			$columns = [];

			foreach ($fields as $field) {
				if ($field->visibleInGrid) {
					$column = [
						'attribute' => $field->key,
						'label' => $field->label,
					];

					if (isset($field->jsonAttribute)) {
						$column['value'] = function ($model) use ($field) {
							if (is_array($model->{static::$jsonConfig->jsonField}))
								if (array_key_exists($field->key, $model->{static::$jsonConfig->jsonField})) {
									return $model->{static::$jsonConfig->jsonField}[$field->key];
								}
						};
					}

					$columns[] = $column;
				}
			}

			return $columns;
		}

    public function getMailProfile($type) {
      $config = static::$jsonConfig;
      $profiles = (array) $config->mailProfiles;
      return $profiles[$type];
    }

    public function getExportProfile($type) {
      $config = static::$jsonConfig;
      $profiles = (array) $config->exportProfiles;
      return $profiles[$type];
    }

    public function getTransformedValue($attribute) {

      $value = $this->{$attribute};
      $fieldConf = self::getFieldConfig($attribute);
      if(isset($fieldConf->transform)) {
        $transformer = '\\giantbits\\crelish\\components\\transformer\\CrelishFieldTransformer' . ucfirst($fieldConf->transform);
        if(class_exists($transformer) && method_exists($transformer, 'transform')) {
          $transformer::transform($fieldConf, $value, !empty($fieldConf->format) ? $fieldConf->format : null );
        }
      }

      return $value;
    }

	}
