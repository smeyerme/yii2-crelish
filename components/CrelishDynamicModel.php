<?php

namespace giantbits\crelish\components;

use Cocur\Slugify\Slugify;
use Yii;
use yii\base\DynamicModel;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use function _\filter;
use function _\find;

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
            if (empty($rule[1])) {
              $this->addRule([$field->key], $rule[0]);
            } else {
              $this->addRule([$field->key], $rule[0], (array)$rule[1]);
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
        $rawData = call_user_func('app\workspace\models\\' . ucfirst($this->_ctype) . '::find')->where(['uuid' => $this->_uuid])->one();

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
    } else {
      $this->isNew = false;
    }

    $modelArray['uuid'] = $this->uuid;

    if (!empty($this->new_password)) {
      $this->defineAttribute('password', \Yii::$app->getSecurity()->generatePasswordHash($this->new_password));
    }

    // Transform and set data, detect json.
    foreach ($this->attributes() as $attribute) {
      $modelArray[$attribute] = $this->{$attribute};

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

    if (!property_exists($this->_elementDefinition, 'storage')) {
      $this->_elementDefinition->storage = 'json';
    }

    switch ($this->_elementDefinition->storage) {
      case 'db':
        if ($this->isNew) {
          $class = 'app\workspace\models\\' . ucfirst($this->_ctype);
          $model = new $class();
        } else {
          $model = call_user_func('app\workspace\models\\' . ucfirst($this->_ctype) . '::find')->where(['uuid' => $this->uuid])->one();
        }

        // Process data.
        foreach ($this->attributes() as $attribute) {
          $fieldType = 'textInput';

          $fieldDef = find($this->_elementDefinition->fields, function ($def) use ($attribute) {
            return $def->key == $attribute;
          });

          if (!empty($fieldDef) && is_object($fieldDef)) {
            $fieldType = (property_exists($fieldDef, 'type')) ? $fieldDef->type : 'textInput';
          }

          if ($attribute == 'slug') {
            $slugger = new Slugify(['regexp' => '([^A-Za-z0-9\/]+)']);
            $model->{$attribute} = $slugger->slugify($modelArray[$attribute]);
          }

          if (!empty($fieldType)) {

            // Get processor class.
            $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';

            if (strpos($fieldType, "widget_") !== false) {
              $processorClass = str_replace("widget_", "", $fieldType) . 'ContentProcessor';
            }

            // Do processor based pre processing.
            if (class_exists($processorClass) && method_exists($processorClass, 'processDataPreSave')) {
              if ($fieldType !== 'relationSelect') {
                $model->{$attribute} = $processorClass::processDataPreSave($attribute, $modelArray[$attribute], $this->_elementDefinition->fields[$attribute], $model);
              } else {
                if (!empty($fieldDef->config) && is_object($fieldDef->config) && isset($fieldDef->config->ctype) && (!isset($fieldDef->config->multiple) || $fieldDef->config->multiple === false)) {
                  @$model->{$attribute} = $modelArray[$attribute];
                }
              }
            } else {
              if ($attribute !== 'i18n' && $model->hasAttribute($attribute)) {
                @$model->{$attribute} = $modelArray[$attribute];
              }
            }
          }
        }

        if (method_exists('\\app\\workspace\\hooks\\' . ucfirst($this->ctype) . 'Hooks', 'beforeSave')) {
          return call_user_func(['\\app\\workspace\\hooks\\' . ucfirst($this->ctype) . 'Hooks', 'afterSave'], ['data' => $this]);
        }

        if ($model->save(false)) {



          if (method_exists('\\app\\workspace\\hooks\\' . ucfirst($this->ctype) . 'Hooks', 'afterSave')) {
            call_user_func(['\\app\\workspace\\hooks\\' . ucfirst($this->ctype) . 'Hooks', 'afterSave'], ['data' => $this]);
          }

          // New post save handlers.
          foreach ($this->attributes() as $attribute) {

            $fieldDef = find($this->_elementDefinition->fields, function ($def) use ($attribute) {
              return $def->key == $attribute;
            });
            if (!empty($fieldDef) && is_object($fieldDef)) {
              $fieldType = (property_exists($fieldDef, 'type')) ? $fieldDef->type : 'textInput';
            }
            if (!empty($fieldType)) {

              // Get processor class.
              $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';

              if (strpos($fieldType, "widget_") !== false) {
                $processorClass = str_replace("widget_", "", $fieldType) . 'ContentProcessor';
              }

              // Do processor based pre processing.
              if (class_exists($processorClass) && method_exists($processorClass, 'processDataPostSave')) {
                if ($fieldType === 'relationSelect') {
                  $processorClass::processDataPostSave($attribute, $modelArray[$attribute], $this->_elementDefinition->fields[$attribute], $model);
                } else {
                  $model->{$attribute} = $processorClass::processDataPostSave($attribute, $modelArray[$attribute], $this->_elementDefinition->fields[$attribute], $model);
                }
              } else {
                if ($attribute !== 'i18n') {
                  $model->{$attribute} = $modelArray[$attribute];
                }
              }
            }
          }

          $saveSuccess = true;
        }

        break;
      default:
        $outModel = Json::encode($modelArray);
        $path = Yii::getAlias('@app') . DIRECTORY_SEPARATOR . 'workspace' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $this->identifier;
        // Create folder if not present.
        FileHelper::createDirectory($path, 0775, true);

        // Set full filename.
        $path .= DIRECTORY_SEPARATOR . $this->uuid . '.json';

        // Save the file.
        file_put_contents($path, $outModel);
        @chmod($path, 0777);
        $saveSuccess = true;
    }

    // Update cache
    $this->updateCache(($this->isNew) ? 'create' : 'update', CrelishBaseContentProcessor::processElement($this->ctype, $modelArray));

    // Todo: Create entry in slug storage.
    if (!empty($this->slug)) {
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

      // Update store.
      $slugStore[$this->slug] = ['ctype' => $this->ctype, 'uuid' => $this->uuid];

      file_put_contents($slugStoreFolder . $ds . $slugStoreFile, Json::encode($slugStore));
    }

    return $saveSuccess;
  }

  public function delete()
  {
    $this->updateCache('delete', $this->uuid);

    if (file_exists($this->fileSource)) {
      unlink($this->fileSource);
    }

    if (is_object($this->_elementDefinition) && $this->_elementDefinition->storage == 'db') {
      $model = call_user_func('app\workspace\models\\' . ucfirst($this->ctype) . '::find')->where(['uuid' => $this->uuid])->one();
      if ($model) {
        $model->delete();
      }
    }
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
    if (empty(find($elementDefinition->fields, function ($elem) {
      return $elem->key == "uuid";
    }))
    ) {
      $elementDefinition->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
    }

    if (empty(find($elementDefinition->fields, function ($elem) {
      return $elem->key == "state";
    }))
    ) {
      $elementDefinition->fields[] = Json::decode('{ "label": "State", "key": "state",  "type": "dropDownList", "transform": "state", "visibleInGrid": true, "rules": [["required"], ["integer"]], "items": {"0":"Offline", "1":"Draft", "2":"Online", "3":"Archived"}}', false);
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

    if ($usePublishingMeta) {
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
