<?php

namespace giantbits\crelish\components;

use Underscore\Types\Arrays;
use yii\helpers\FileHelper;
use yii\helpers\Json;

class CrelishDynamicJsonModel extends \yii\base\DynamicModel
{
    /**
     * [$_attributeLabels description]
     * @var [type]
     */
    private $_attributeLabels;

    /**
     * [$identifier description]
     * @var [type]
     */
    public $identifier;

    /**
     * [$uuid description]
     * @var [type]
     */
    public $uuid;

    /**
     * [$ctype description]
     * @var [type]
     */
    public $ctype;

    private $fileSource;

    private $isNew = true;

    /**
     * [$fieldDefinitions description]
     * @var [type]
     */
    public $fieldDefinitions;

    public function init()
    {
        parent::init();

        // Build definitions.
        if (!empty($this->ctype)) {
            $filePath = \Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . $this->ctype . '.json';
            $elementDefinition = CrelishDynamicJsonModel::loadElementDefinition($filePath);

            $this->fieldDefinitions = $elementDefinition;
            $fields = [];

            // Build field array.
            foreach ($elementDefinition->fields as $field) {
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
            foreach ($elementDefinition->fields as $field) {
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

            // Load model from file.
            if (!empty($this->uuid)) {
                $this->isNew = false;
                $this->fileSource = \Yii::getAlias('@app/workspace/data/') . DIRECTORY_SEPARATOR . $this->ctype . DIRECTORY_SEPARATOR . $this->uuid . '.json';
                $data['CrelishDynamicJsonModel'] = Json::decode(file_get_contents($this->fileSource));
                $this->load($data);
            }
        }
    }

    public static function loadElementDefinition($filePath)
    {
        $elementDefinition = Json::decode(file_get_contents($filePath), false);

        // Add core fields.
        $elementDefinition->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
        $elementDefinition->fields[] = Json::decode('{ "label": "Path", "key": "path", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
        //$elementDefinition->fields[] = Json::decode('{ "label": "Slug", "key": "slug", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
        $elementDefinition->fields[] = Json::decode('{ "label": "State", "key": "state", "type": "dropDownList", "visibleInGrid": true, "rules": [["required"], ["integer"]], "options": {"prompt":"Please set state"}, "items": {"0":"Offline", "1":"Draft", "2":"Online", "3":"Archived"}}', false);

        $elementDefinition->fields[] = Json::decode('{ "label": "Created", "key": "created", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
        $elementDefinition->fields[] = Json::decode('{ "label": "Updated", "key": "updated", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
        $elementDefinition->fields[] = Json::decode('{ "label": "Publish from", "key": "from", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
        $elementDefinition->fields[] = Json::decode('{ "label": "Publish to", "key": "to", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
        return $elementDefinition;
    }

    public function getFields()
    {
        return $this->fields;
    }

    /**
     * [defineLabel description]
     * @param  [type] $name  [description]
     * @param  [type] $label [description]
     * @return [type]        [description]
     */
    public function defineLabel($name, $label)
    {
        $this->_attributeLabels[$name] = $label;
    }

    /**
     * [attributeLabels description]
     * @return [type] [description]
     */
    public function attributeLabels()
    {
        return $this->_attributeLabels;
    }

    /**
     * [save description]
     * @return [type] [description]
     */
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
                // Is JSON.
                $modelArray[$attribute] = Json::decode($this->{$attribute});
            } else {
                $modelArray[$attribute] = $this->{$attribute};
            }

            // Check for transformer.
            $fieldDefinitionLook = Arrays::filter($this->fieldDefinitions->fields, function ($value) use ($attribute) {
                return $value->key == $attribute;
            });

            $fieldDefinition = array_shift($fieldDefinitionLook);

            if ($fieldDefinition && property_exists($fieldDefinition, 'transform')) {
                $transformer = 'giantbits\crelish\components\transformer\CrelishFieldTransformer' . ucfirst($fieldDefinition->transform);
                $transformer::beforeSave($modelArray[$attribute]);
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
        $this->updateCache('update', $outModel);

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
    }

    private function updateCache($action, $data)
    {
        //\Yii::$app->cache->flush('crc_' . $this->ctype);
        $cacheStore = \Yii::$app->cache->get('crc_' . $this->ctype);

        if(!$cacheStore) {
            return;
        }

        switch($action){
            case 'delete':
                Arrays::each($cacheStore, function($item, $index) use ($data, $cacheStore) {
                    if($item['uuid'] == $data){
                        unset($cacheStore[$index]);
                        \Yii::$app->cache->set('crc_' . $this->ctype, array_values($cacheStore));
                    }
                });
                break;
            default:
                if($this->isNew) {
                    array_push($cacheStore, $data);
                } else {
                    Arrays::each($cacheStore, function($item, $index) use ($data, $cacheStore) {
                        if($item['uuid'] == $data){
                            $cacheStore[$index] = $data;
                            \Yii::$app->cache->set('crc_' . $this->ctype, array_values($cacheStore));
                        }
                    });
                }
        }

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

    /**
     * [GUIDv4 description]
     * @param bool $trim [description]
     */
    function GUIDv4($trim = true)
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
}
