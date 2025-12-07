<?php

namespace giantbits\crelish\components;

use Cocur\Slugify\Slugify;
use Yii;
use yii\base\DynamicModel;
use yii\helpers\FileHelper;
use yii\helpers\Json;

/**
 *
 * @property-read bool $isNewRecord
 * @property-read mixed $fields
 */
class CrelishDynamicModel extends DynamicModel
{
  public $identifier;
  public $uuid;
  public $ctype;
  public $fieldDefinitions;
  public $elementDefinition;
  private $_attributeLabels;
  private $fileSource;
  private $isNew = true;

  public $i18n = [];

  private $_elementDefinition = null;
  private $_ctype = null;
  private $_uuid = null;

  public $allTranslations = null;

  public function __construct($attributes = [], $config = [])
  {
    if (isset($attributes['ctype'])) {
      $this->_ctype = $attributes['ctype'];
      $this->ctype = $attributes['ctype'];
      $this->_elementDefinition = self::loadElementDefinition($this->_ctype);
    }

    if(isset($attributes['uuid'])) {
      $this->_uuid = $attributes['uuid'];
      $this->uuid = $attributes['uuid'];
    }
    parent::__construct($attributes, $config);
  }

  public function init()
  {
    // Build definitions.
    if (!empty($this->_ctype)) {

      //$this->_ctype = $this->ctype;
      $this->_elementDefinition = self::loadElementDefinition($this->_ctype);

      // Rest of your init code...
      $this->fieldDefinitions = $this->_elementDefinition;
      $fields = [];

      // Build field array.
      foreach ($this->_elementDefinition->fields as $field) {
        $fields[] = $field->key;
      }

      $this->identifier = $this->_ctype;

      // Initialize the i18n array with supported languages
      $this->initializeI18n();

      // Populate attributes.
      foreach ($fields as $name => $value) {
        if (is_int($name)) {
          $this->defineAttribute($value, null);
        } else {
          $this->defineAttribute($name, $value);
        }
      }
      
      // Add validation rules.
      foreach ($this->_elementDefinition->fields as $field) {
        $this->defineLabel($field->key, Yii::t('app', $field->label));
        if (!empty($field->rules)) {
          foreach ($field->rules as $rule) {
            // Handle different rule formats
            if (is_string($rule)) {
              // Simple validator with no parameters
              $this->addRule([$field->key], $rule);
            } else if (is_array($rule)) {
              if (isset($rule[0])) {
                if (is_array($rule[0]) && count($rule) === 1) {
                  // Handle nested array format like your current JSON
                  $nestedRule = $rule[0];
                  $validator = $nestedRule[0];
                  $params = isset($nestedRule[1]) ? (array)$nestedRule[1] : [];
                  $this->addRule([$field->key], $validator, $params);
                } else {
                  // Standard format [validator, params]
                  $validator = $rule[0];
                  $params = isset($rule[1]) ? (array)$rule[1] : [];
                  $this->addRule([$field->key], $validator, $params);
                }
              }
            }
          }
        }
      }

      // Load model from file if uuid is set.
      if (!empty($this->_uuid)) {
        $this->loadModelData();
      }

    }

    parent::init();
  }

  /**
   * Initialize the i18n array with all supported languages
   */
  protected function initializeI18n()
  {
    // Check if i18n is already initialized
    if (!is_array($this->i18n)) {
      $this->i18n = [];
    }
    
    // Add all supported languages if they exist in params
    if (isset(Yii::$app->params['crelish']['languages']) && is_array(Yii::$app->params['crelish']['languages'])) {
      foreach (Yii::$app->params['crelish']['languages'] as $lang) {
        if (!isset($this->i18n[$lang])) {
          $this->i18n[$lang] = [];
        }
      }
    } else {
      // Fallback to at least the current language
      $currentLang = Yii::$app->language;
      if (!isset($this->i18n[$currentLang])) {
        $this->i18n[$currentLang] = [];
      }
    }
  }

  public function setCtype($value)
  {
    $this->_ctype = $value;
  }

  public function getElementDefinition()
  {
    return $this->_elementDefinition;
  }

  public function getCtype()
  {
    return $this->_ctype;
  }

  private function loadModelData(): void
  {
    $this->isNew = false;
    $attributes = [];
    $rawData = [];
    $finalArr = [];

    // Define source.
    if (!property_exists($this->_elementDefinition, 'storage')) {
      $this->_elementDefinition->storage = 'json';
    }

    // Fetch from source.
    switch ($this->_elementDefinition->storage) {
      case 'db':
        $modelClass = CrelishModelResolver::getModelClass($this->_ctype);
        $rawData = $modelClass::find()->where(['uuid' => $this->_uuid])->one();

        if ($rawData && $rawData->hasMethod('loadAllTranslations')) {
          $this->allTranslations = $rawData->loadAllTranslations();
        }
        break;
      default:
        $this->fileSource = Yii::getAlias('@app/workspace/data/') . DIRECTORY_SEPARATOR . $this->ctype . DIRECTORY_SEPARATOR . $this->_uuid . '.json';
        if (file_exists($this->fileSource)) {
          $rawData = Json::decode(file_get_contents($this->fileSource));
        } else {
          $rawData = null;
          $this->attributes = null;
          $this->uuid = null;
          return;
        }
    }

    // Set data values.
    foreach ($this->_elementDefinition->fields as $field) {
      if (!empty($rawData[$field->key])) {
        $attributes[$field->key] = $rawData[$field->key];
      }
    }

    // Handle i18n data if present
    if (!empty($rawData['i18n'])) {
      $this->i18n = $rawData['i18n'];
      
      // Process each translatable field in i18n data
      foreach ($this->_elementDefinition->fields as $field) {
        if (property_exists($field, 'translatable') && $field->translatable === true) {
          foreach ($this->i18n as $lang => $langData) {
            if (isset($langData[$field->key])) {
              $fieldValue = $langData[$field->key];
              
              // Handle JSON fields
              if (property_exists($field, 'type') && $field->type === 'jsonEditor') {
                if (is_string($fieldValue)) {
                  // Convert JSON string to array/object
                  try {
                    $this->i18n[$lang][$field->key] = Json::decode($fieldValue);
                  } catch (\Exception $e) {
                    // If not valid JSON, keep as is
                  }
                }
              }
              
              // Apply transformer if defined
              if (property_exists($field, 'transform')) {
                $transformer = 'giantbits\crelish\components\transformer\CrelishFieldTransformer' . ucfirst($field->transform);
                if (method_exists($transformer, 'afterFind')) {
                  $transformer::afterFind($this->i18n[$lang][$field->key]);
                }
              }
            }
          }
        }
      }
    }

    // Process data values based on field types.
    foreach ($attributes as $attr => $value) {
      CrelishBaseContentProcessor::processFieldData($this->_ctype, $this->_elementDefinition, $attr, $value, $finalArr);
    }

    // Set it.
    $this->attributes = $finalArr;
  }

  public function save()
  {
    $saveSuccess = false;
    $modelArray = [];
    
    if (empty($this->uuid)) {
      $this->uuid = $this->GUIDv4();
      $this->isNew = true;
    } else {
      $this->isNew = false;
    }

    $modelArray['uuid'] = $this->uuid;

    if (!empty($this->new_password)) {
      $this->defineAttribute('password', \Yii::$app->getSecurity()->generatePasswordHash($this->new_password));
    }

    // Handle i18n data if present
    if (!empty($this->i18n)) {
      $modelArray['i18n'] = $this->i18n;
      
      // Process each field in i18n data
      foreach ($this->fieldDefinitions->fields as $field) {
        if (property_exists($field, 'translatable') && $field->translatable === true) {
          foreach ($this->i18n as $lang => $langData) {
            if (isset($langData[$field->key])) {
              $fieldValue = $langData[$field->key];
              
              // Check for field type specific processing
              if (property_exists($field, 'type') && $field->type === 'jsonEditor') {
                if (is_array($fieldValue) || is_object($fieldValue)) {
                  // Store as JSON string
                  $modelArray['i18n'][$lang][$field->key] = Json::encode($fieldValue);
                }
              }
              
              // Apply transformer if defined
              if (property_exists($field, 'transform')) {
                $transformer = 'giantbits\crelish\components\transformer\CrelishFieldTransformer' . ucfirst($field->transform);
                $transformer::beforeSave($modelArray['i18n'][$lang][$field->key]);
              }
            }
          }
        }
      }
    }

    // Transform and set data
    foreach ($this->attributes() as $attribute) {
      // Skip attributes that are handled via i18n
      if ($attribute === 'i18n') {
        continue;
      }
      
      $modelArray[$attribute] = $this->{$attribute};

      // Check for transformer
      $fieldDefinitionLook = CrelishArrayHelper::filter($this->fieldDefinitions->fields, function ($value) use ($attribute) {
        return $value->key == $attribute;
      });

      $fieldDefinition = array_shift($fieldDefinitionLook);

      if ($fieldDefinition && property_exists($fieldDefinition, 'transform')) {
        $transformer = 'giantbits\crelish\components\transformer\CrelishFieldTransformer' . ucfirst($fieldDefinition->transform);
        $transformer::beforeSave($modelArray[$attribute]);
      }
      // Note: jsonEditor fields are already processed by JsonEditorContentProcessor
      // No need to apply additional JSON transformer to avoid double-encoding

      if ($attribute == "created" && $this->isNew) {
        $modelArray[$attribute] = time();
      }

      if ($attribute == "updated" && !$this->isNew) {
        $modelArray[$attribute] = time();
      }
    }

    // Use the storage factory to get the appropriate storage implementation
    $storage = CrelishStorageFactory::getStorage($this->ctype);
    $saveSuccess = $storage->save($this->ctype, $modelArray, $this->isNew);

    // Handle hooks
    if ($saveSuccess) {
      if (method_exists('\\app\\workspace\\hooks\\' . ucfirst($this->ctype) . 'Hooks', 'afterSave')) {
        call_user_func(['\\app\\workspace\\hooks\\' . ucfirst($this->ctype) . 'Hooks', 'afterSave'], ['data' => $this]);
      }
    }

    // Handle slug storage
    if ($saveSuccess && !empty($this->slug)) {
      $ds = DIRECTORY_SEPARATOR;
      $slugStore = [];
      $slugStoreFolder = Yii::getAlias('@runtime') . $ds . 'slugstore';
      $slugStoreFile = 'slugs.json';

      if (!is_dir($slugStoreFolder)) {
        FileHelper::createDirectory($slugStoreFolder);
      }

      if (file_exists($slugStoreFolder . $ds . $slugStoreFile)) {
        $slugStore = Json::decode(file_get_contents($slugStoreFolder . $ds . $slugStoreFile), true);
      }

      // Update store
      $slugStore[$this->slug] = ['ctype' => $this->ctype, 'uuid' => $this->uuid];
      file_put_contents($slugStoreFolder . $ds . $slugStoreFile, Json::encode($slugStore));
    }

    return $saveSuccess;
  }

  public function delete()
  {
    // Use the storage factory to get the appropriate storage implementation
    $storage = CrelishStorageFactory::getStorage($this->ctype);
    
    // Call hooks before deletion
    if (method_exists('\\app\\workspace\\hooks\\' . ucfirst($this->ctype) . 'Hooks', 'beforeDelete')) {
      call_user_func(['\\app\\workspace\\hooks\\' . ucfirst($this->ctype) . 'Hooks', 'beforeDelete'], ['data' => $this]);
    }
    
    $result = $storage->delete($this->ctype, $this->uuid);
    
    // Call hooks after deletion
    if ($result && method_exists('\\app\\workspace\\hooks\\' . ucfirst($this->ctype) . 'Hooks', 'afterDelete')) {
      call_user_func(['\\app\\workspace\\hooks\\' . ucfirst($this->ctype) . 'Hooks', 'afterDelete'], ['data' => $this]);
    }
    
    return $result;
  }

  public function getFields()
  {
    return $this->fields;
  }

  public function getField($field): object
  {
    $fieldDef = array_filter($this->_elementDefinition->fields, function ($def) use ($field) {
      return $def->key == $field;
    });

    return array_values($fieldDef)[0];
  }

  public function defineLabel($name, $label)
  {
    $this->_attributeLabels[$name] = $label;
  }

  public function attributeLabels()
  {
    return $this->_attributeLabels;
  }

  public function setAttributess($values, $safeOnly = true)
  {
    if (is_array($values)) {
      if (!empty($values['CrelishDynamicModel'])) {
        $values = $values['CrelishDynamicModel'];
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
    $cacheStore = Yii::$app->cache->get('crc_' . $this->ctype);

    if (Yii::$app->cache->exists('crc_' . $this->ctype)) {
      Yii::$app->cache->delete('crc_' . $this->ctype);
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

  public static function loadElementDefinition($ctype)
  {

    $elementDefinition = null;
    $definitionPath = Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . $ctype . '.json';
    if (file_exists($definitionPath)) {
      $elementDefinition = Json::decode(file_get_contents($definitionPath), false);
    }

    if (empty($elementDefinition)) {
      return null;
    }

    $usePublishingMeta = !((!property_exists($elementDefinition, 'usePublishingMeta') || $elementDefinition->usePublishingMeta === false));

    // Add core fields.
    if (empty(CrelishArrayHelper::find($elementDefinition->fields, function ($elem) {
      return $elem->key == "uuid";
    }))
    ) {
      $elementDefinition->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
    }

    if (empty(CrelishArrayHelper::find($elementDefinition->fields, function ($elem) {
      return $elem->key == "state";
    }))
    ) {
      $elementDefinition->fields[] = Json::decode('{ "label": "State", "key": "state",  "type": "dropDownList", "transform": "state", "visibleInGrid": true, "rules": [["required"], ["integer"]], "items": {"0":"Offline", "1":"Draft", "2":"Online", "3":"Archived"}}', false);
    }

    if (empty(CrelishArrayHelper::find($elementDefinition->fields, function ($elem) {
      return $elem->key == "created";
    }))
    ) {
      $elementDefinition->fields[] = Json::decode('{ "label": "Created", "key": "created", "type": "textInput", "visibleInGrid": true, "format": "date", "transform": "datetime", "rules": [["safe"]]}', false);
    }

    if (empty(CrelishArrayHelper::find($elementDefinition->fields, function ($elem) {
      return $elem->key == "updated";
    }))
    ) {
      $elementDefinition->fields[] = Json::decode('{ "label": "Updated", "key": "updated", "type": "textInput", "visibleInGrid": true, "format": "date", "rules": [["safe"]]}', false);
    }

    if ($usePublishingMeta) {
      if (empty(CrelishArrayHelper::find($elementDefinition->fields, function ($elem) {
        return $elem->key == "from";
      }))
      ) {
        $elementDefinition->fields[] = Json::decode('{ "label": "Publish from", "key": "from", "type": "textInput", "visibleInGrid": true, "format": "date", "transform": "date", "rules": [["string", {"max": 128}]]}', false);
      }

      if (empty(CrelishArrayHelper::find($elementDefinition->fields, function ($elem) {
        return $elem->key == "to";
      }))
      ) {
        $elementDefinition->fields[] = Json::decode('{ "label": "Publish to", "key": "to", "type": "textInput", "visibleInGrid": true, "format": "datetime", "transform": "datetime", "rules": [["string", {"max": 128}]]}', false);
      }
    }

    foreach ($elementDefinition->fields as $def) {
      $fieldDefinitions[$def->key] = $def;
    }

    $elementDefinition->fields = $fieldDefinitions;

    return $elementDefinition;
  }

  public function getIsNewRecord()
  {
    return $this->isNew;
  }

  public static function getDb()
  {
    return \Yii::$app->getDb();
  }

}
