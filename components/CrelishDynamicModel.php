<?php

namespace giantbits\crelish\components;

use yii\helpers\Json;

class CrelishDynamicModel extends \yii\base\DynamicModel
{
  private $_attributeLabels;
  public $identifier;
  public $uuid;

  public function defineAttribute($name, $value = null)
  {
    $this->_attributes[$name] = $value;
    return $this;
  }

  public function defineLabel($name, $label)
  {
    $this->_attributeLabels[$name] = $label;
  }

  public function attributeLabels()
  {
    return $this->_attributeLabels;
  }

  public function save()
  {
    $modelArray = [];
    if (empty($this->uuid)) {
      $this->uuid = $this->GUIDv4();
    }

    $modelArray['uuid'] = $this->uuid;

    // Set data, detect json.
    foreach ($this->attributes() as $attribute) {

      $jsonCheck = @json_decode($this->{$attribute});
      if (json_last_error() == JSON_ERROR_NONE) {
        // Is JSON.
        $modelArray[$attribute] = Json::decode($this->{$attribute});
      } else {
        $modelArray[$attribute] = $this->{$attribute};
      }
    }

    $outModel = Json::encode($modelArray);
    $path = \Yii::getAlias('@app') . DIRECTORY_SEPARATOR . 'workspace' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $this->identifier . DIRECTORY_SEPARATOR . $this->uuid . '.json';
    file_put_contents($path, $outModel);
    @chmod($path, 0777);

    return true;
  }

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
    return $guidv4;
  }
}