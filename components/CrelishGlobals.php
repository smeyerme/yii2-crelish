<?php

namespace giantbits\crelish\components;


use yii\helpers\Json;

class CrelishGlobals
{
  public static function getAll()
  {

    $data = [];
    $dir = \Yii::getAlias('@app/workspace/globals');

    if (is_dir($dir)) {
      if ($dh = opendir($dir)) {
        while (($file = readdir($dh)) !== false) {
          if (!is_file($dir . DIRECTORY_SEPARATOR . $file)) {
            continue;
          }

          $key = str_replace(".json", "", $file);
          $data[$key] = self::get($key);
        }
        closedir($dh);
      }
    }

    return $data;
  }

  public static function get($key = '')
  {
    if (!empty($key)) {
      $rawData = file_get_contents(\Yii::getAlias('@app/workspace/globals/' . $key . '.json'));
      if($rawData) {
        $data = Json::decode($rawData);
      }

      return  $data['data'];
    }

    return '';
  }
}
