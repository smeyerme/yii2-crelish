<?php
	
	namespace giantbits\crelish\components;
	
	use yii\helpers\FileHelper;
	use yii\helpers\Json;
	use function _\filter;
	use function _\find;
	
	class CrelishDynamicJsonModel extends \yii\base\DynamicModel
	{
		public $identifier;
		public $uuid;
		public $ctype;
		public $fieldDefinitions;
		public $elementDefinition;
		private $_attributeLabels;
		private $fileSource;
		private $isNew = true;
		
		public function init()
		{
			parent::init();
			
			// Build definitions.
			if (!empty($this->ctype)) {
				$this->elementDefinition = CrelishDynamicJsonModel::loadElementDefinition($this->ctype);
				
				$this->fieldDefinitions = $this->elementDefinition;
				$fields = [];
				
				// Build field array.
				foreach ($this->elementDefinition->fields as $field) {
					array_push($fields, $field->key);
				}
				
				$this->identifier = $this->ctype;
				
				// Populate attributes.
				foreach ($fields as $name => $value) {
					if (is_int($name)) {
						$this->defineAttribute($value, null);
					} else {
						$this->defineAttribute($name, $value);
					}
				}
				
				// Add validation rules.
				foreach ($this->elementDefinition->fields as $field) {
					$this->defineLabel($field->key, $field->label);
					if (!empty($field->rules)) {
						
						foreach ($field->rules as $rule) {
							if (empty($rule[1])) {
								$this->addRule([$field->key], $rule[0]);
							} else {
								$this->addRule([$field->key], $rule[0], (array)$rule[1]);
							}
						}
					}
				}
				
				// Load model from file if uuid is set.
				if (!empty($this->uuid)) {
					$this->loadModelData();
				}
			}
		}
		
		public function save()
		{
			$modelArray = [];
			if (empty($this->uuid)) {
				$this->uuid = $this->GUIDv4();
			} else {
				$this->isNew = false;
			}
			
			$modelArray['uuid'] = $this->uuid;
			
			// Transform and set data, detect json.
			foreach ($this->attributes() as $attribute) {
				$jsonCheck = @json_decode($this->{$attribute});
				if (json_last_error() == JSON_ERROR_NONE) {
					$modelArray[$attribute] = (is_array($this->{$attribute})) ? $this->{$attribute} : Json::decode($this->{$attribute});
				} else {
					$modelArray[$attribute] = $this->{$attribute};
				}
				
				// Check for transformer.
				$fieldDefinitionLook = filter($this->fieldDefinitions->fields, function ($value) use ($attribute) {
					return $value->key == $attribute;
				});
				
				$fieldDefinition = array_shift($fieldDefinitionLook);
				
				if ($fieldDefinition && property_exists($fieldDefinition, 'transform')) {
					$transformer = 'giantbits\crelish\components\transformer\CrelishFieldTransformer' . ucfirst($fieldDefinition->transform);
					$transformer::beforeSave($modelArray[$attribute]);
				}
				
				if ($attribute == "created" && $this->isNew) {
					$modelArray[$attribute] = time();
				}
				
				if ($attribute == "updated" && !$this->isNew) {
					$modelArray[$attribute] = time();
				}
			}
			
			$outModel = Json::encode($modelArray);
			$path = \Yii::getAlias('@app') . DIRECTORY_SEPARATOR . 'workspace' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $this->identifier;
			// Create folder if not present.
			FileHelper::createDirectory($path, 0775, true);
			
			// Set full filename.
			$path .= DIRECTORY_SEPARATOR . $this->uuid . '.json';
			
			// Save the file.
			file_put_contents($path, $outModel);
			@chmod($path, 0777);
			
			// Update cache
			$this->updateCache(($this->isNew) ? 'create' : 'update', CrelishBaseContentProcessor::processElement($this->ctype, $modelArray));
			//\Yii::$app->cache->flush();
			
			// Todo: Create entry in slug storage.
			if (!empty($this->slug)) {
				$ds = DIRECTORY_SEPARATOR;
				$slugStore = [];
				$slugStoreFolder = \Yii::getAlias('@runtime') . $ds . 'slugstore';
				$slugStoreFile = 'slugs.json';
				
				if (!is_dir($slugStoreFolder)) {
					FileHelper::createDirectory($slugStoreFolder);
				}
				
				if (file_exists($slugStoreFolder . $ds . $slugStoreFile)) {
					$slugStore = Json::decode(file_get_contents($slugStoreFolder . $ds . $slugStoreFile), true);
				}
				
				// Update store.
				$slugStore[$this->slug] = ['ctype' => $this->ctype, 'uuid' => $this->uuid];
				
				file_put_contents($slugStoreFolder . $ds . $slugStoreFile, Json::encode($slugStore));
			}
			
			return true;
		}
		
		public function delete()
		{
			$this->updateCache('delete', $this->uuid);
			if (file_exists($this->fileSource)) {
				unlink($this->fileSource);
			}
		}
		
		public function getFields()
		{
			return $this->fields;
		}
		
		public function defineLabel($name, $label)
		{
			$this->_attributeLabels[$name] = $label;
		}
		
		public function attributeLabels()
		{
			return $this->_attributeLabels;
		}
		
		public function setAttributes($values, $safeOnly = true)
		{
			if (is_array($values)) {
				if (!empty($values['CrelishDynamicJsonModel'])) {
					$values = $values['CrelishDynamicJsonModel'];
				}
				$attributes = array_flip($safeOnly ? $this->safeAttributes() : $this->attributes());
				foreach ($values as $name => $value) {
					if (isset($attributes[$name])) {
						$this->$name = $value;
					} elseif ($safeOnly) {
						$this->onUnsafeAttribute($name, $value);
					}
				}
			}
		}
		
		private function updateCache($action, $data)
		{
			
			$cacheStore = \Yii::$app->cache->get('crc_' . $this->ctype);
			
			switch ($action) {
				case 'delete':
					$data = $this->uuid;
					\_\each($cacheStore, function ($cacheItem, $index) use ($data, $cacheStore) {
						if (!empty($cacheItem['uuid']) && $cacheItem['uuid'] == $data) {
							unset($cacheStore[$index]);
							\Yii::$app->cache->set('crc_' . $this->ctype, array_values($cacheStore));
						}
					});
					break;
				case 'update':
					if (!$this->isNew) {
						\_\each($cacheStore, function ($cacheItem, $index) use ($data, $cacheStore) {
							if ($cacheItem['uuid'] == $data['uuid']) {
								$data['ctype'] = $this->ctype;
								$cacheStore[$index] = $data;
								\Yii::$app->cache->set('crc_' . $this->ctype, array_values($cacheStore));
							}
						});
					}
					break;
				default:
					$data['ctype'] = $this->ctype;
					if (!$cacheStore) {
						$cacheStore = [];
					}
					array_push($cacheStore, $data);
					\Yii::$app->cache->set('crc_' . $this->ctype, array_values($cacheStore));
			}
			
			if (is_a(\Yii::$app, 'yii\web\Application')) {
				\Yii::$app->session->set('intellicache', $this->uuid);
			}
		}
		
		private function GUIDv4($trim = true)
		{
			// Windows
			if (function_exists('com_create_guid') === true) {
				if ($trim === true)
					return trim(com_create_guid(), '{}');
				else
					return com_create_guid();
			}
			
			// OSX/Linux
			if (function_exists('openssl_random_pseudo_bytes') === true) {
				$data = openssl_random_pseudo_bytes(16);
				$data[6] = chr(ord($data[6]) & 0x0f | 0x40);    // set version to 0100
				$data[8] = chr(ord($data[8]) & 0x3f | 0x80);    // set bits 6-7 to 10
				return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
			}
			
			// Fallback (PHP 4.2+)
			mt_srand((double)microtime() * 10000);
			$charid = strtolower(md5(uniqid(rand(), true)));
			$hyphen = chr(45);                  // "-"
			$lbrace = $trim ? "" : chr(123);    // "{"
			$rbrace = $trim ? "" : chr(125);    // "}"
			$guidv4 = $lbrace .
				substr($charid, 0, 8) . $hyphen .
				substr($charid, 8, 4) . $hyphen .
				substr($charid, 12, 4) . $hyphen .
				substr($charid, 16, 4) . $hyphen .
				substr($charid, 20, 12) .
				$rbrace;
			return strtolower($guidv4);
		}
		
		private function loadModelData()
		{
			$this->isNew = false;
			$this->fileSource = \Yii::getAlias('@app/workspace/data/') . DIRECTORY_SEPARATOR . $this->ctype . DIRECTORY_SEPARATOR . $this->uuid . '.json';
			if (file_exists($this->fileSource)) {
				$rawData = Json::decode(file_get_contents($this->fileSource));
			} else {
				$rawData = [];
			}
			$attributes = [];
			
			foreach ($this->elementDefinition->fields as $field) {
				
				/* Do field transform.
				if(property_exists($field, 'transform') && !empty($field->transform)) {

						$transformer = 'giantbits\\crelish\\components\\transformer\\CrelishFieldTransformer' . ucfirst(strtolower($field->transform));
						if (class_exists($transformer)) {
								//$transformer::afterFind($rawData[$field->key]);
						}
				}*/
				
				if (!empty($rawData[$field->key])) {
					$attributes[$field->key] = $rawData[$field->key];
				}
			}
			
			$this->attributes = $attributes;
		}
		
		public static function loadElementDefinition($ctype)
		{
			$definitionPath = \Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . str_replace('db:', '', $ctype) . '.json';
			$elementDefinition = Json::decode(file_get_contents($definitionPath), false);
			
			// Add core fields.
			if (empty(find($elementDefinition->fields, function ($elem) {
				return $elem->key == "uuid";
			}))
			) {
				$elementDefinition->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
			}
			
			if (empty(find($elementDefinition->fields, function ($elem) {
				return $elem->key == "path";
			}))
			) {
				//$elementDefinition->fields[] = Json::decode('{ "label": "Path", "key": "path", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
			}
			
			if (empty(find($elementDefinition->fields, function ($elem) {
				return $elem->key == "slug";
			}))
			) {
				//$elementDefinition->fields[] = Json::decode('{ "label": "Slug", "key": "slug", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
			}
			
			if (empty(find($elementDefinition->fields, function ($elem) {
				return $elem->key == "state";
			}))
			) {
				$elementDefinition->fields[] = Json::decode('{ "label": "State", "key": "state",  "type": "dropDownList", "transform": "state", "visibleInGrid": true, "rules": [["required"], ["integer"]], "options": {"prompt":"Please set state"}, "items": {"0":"Offline", "1":"Draft", "2":"Online", "3":"Archived"}}', false);
			}
			
			if (empty(find($elementDefinition->fields, function ($elem) {
				return $elem->key == "created";
			}))
			) {
				$elementDefinition->fields[] = Json::decode('{ "label": "Created", "key": "created", "type": "textInput", "visibleInGrid": true, "format": "date", "transform": "datetime", "rules": [["safe"]]}', false);
			}
			
			if (empty(find($elementDefinition->fields, function ($elem) {
				return $elem->key == "updated";
			}))
			) {
				$elementDefinition->fields[] = Json::decode('{ "label": "Updated", "key": "updated", "type": "textInput", "visibleInGrid": true, "format": "date", "rules": [["safe"]]}', false);
			}
			
			if (empty(find($elementDefinition->fields, function ($elem) {
				return $elem->key == "from";
			}))
			) {
				$elementDefinition->fields[] = Json::decode('{ "label": "Publish from", "key": "from", "type": "textInput", "visibleInGrid": true, "format": "date", "transform": "date", "rules": [["string", {"max": 128}]]}', false);
			}
			
			if (empty(find($elementDefinition->fields, function ($elem) {
				return $elem->key == "to";
			}))
			) {
				$elementDefinition->fields[] = Json::decode('{ "label": "Publish to", "key": "to", "type": "textInput", "visibleInGrid": true, "format": "datetime", "transform": "datetime", "rules": [["string", {"max": 128}]]}', false);
			}
			
			return $elementDefinition;
		}
	}
