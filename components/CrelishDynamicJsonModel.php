<?php
	
namespace giantbits\crelish\components;

use yii\helpers\FileHelper;
use yii\helpers\Json;
use function _\filter;
use function _\find;

/**
 * @deprecated since version 2.0.0, use CrelishDynamicModel instead.
 * This class is maintained for backward compatibility and will be removed in a future version.
 */
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
		
		// Use the storage factory to get the appropriate storage implementation
		$storage = CrelishStorageFactory::getStorage($this->ctype);
		$result = $storage->save($this->ctype, $modelArray, $this->isNew);
		
		// Handle slug storage.
		if ($result && !empty($this->slug)) {
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
		
		return $result;
	}
	
	public function delete()
	{
		// Use the storage factory to get the appropriate storage implementation
		$storage = CrelishStorageFactory::getStorage($this->ctype);
		return $storage->delete($this->ctype, $this->uuid);
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
	
	private function loadModelData()
	{
		$this->isNew = false;
		
		// Use the storage factory to get the appropriate storage implementation
		$storage = CrelishStorageFactory::getStorage($this->ctype);
		$rawData = $storage->findOne($this->ctype, $this->uuid);
		
		if (!$rawData) {
			$this->attributes = null;
			$this->uuid = null;
			return;
		}
		
		$attributes = [];
		
		foreach ($this->elementDefinition->fields as $field) {
			if (!empty($rawData[$field->key])) {
				$attributes[$field->key] = $rawData[$field->key];
			}
		}
		
		$this->attributes = $attributes;
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
	
	public static function loadElementDefinition($ctype)
	{
		return CrelishDynamicModel::loadElementDefinition($ctype);
	}
}
