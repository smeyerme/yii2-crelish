<?php
  /**
   * Created by PhpStorm.
   * User: myrst
   * Date: 09.04.2018
   * Time: 16:01
   */
  
  namespace giantbits\crelish\components;
  
  
  use app\workspace\models\Download;
  use app\workspace\models\Event;
  use app\workspace\models\News;
  use app\workspace\models\Product;
  use app\workspace\models\Reference;
  use app\workspace\models\User;
  use yii\helpers\Url;
  
  class CrelishBaseHelper
  {
    public static function urlFromSlug($slug, $params = [], $langCode = null)
    {
      $url = '/' . $slug;
      
      if (isset(\Yii::$app->params['crelish']['langprefix']) && \Yii::$app->params['crelish']['langprefix']) {
        if (empty($langCode)) {
          $langCode = \Yii::$app->language;
          if (preg_match('/([a-z]{2})-[A-Z]{2}/', $langCode, $sub)) {
            $langCode = $sub[1];
          }
        }
        $url = '/' . $langCode . $url;
      }
      
      return Url::to($url, $params);
    }
    
    public static function currentUrl($params = [])
    {
      return Url::to(array_merge(['/' . \Yii::$app->controller->entryPoint['slug']], $params));
    }
    
    public static function getAccountData($company = null) {
      
      if(!$company) {
        $company = \Yii::$app->user->identity->company->uuid;
      } else {
        $userData = User::findOne(['company' => $company]);
        $user = $userData->role;
      }
      
      // Get user data as it contains payed package.
      if(!$user)
        $user = \Yii::$app->user->identity->role;
      
      $rolePackageMap = [
        "1" => "registered",
        "2" => "basic",
        "4" => "basic_plus",
        "6" => "premium",
        "8" => "premium_plus",
        "9" => "admin"
      ];
      
      $data = new class{};
      
      $data->package = $rolePackageMap[(int) $user];
  
      $packageData =  CrelishGlobals::get('packages')[$data->package];
      
      // Count products, news, events, projects and downloads for the company.
      $data->productCount = (int) Product::find()
        ->where(['company' => $company])
        ->count();
  
      $data->productCountMax = (int) $packageData['productCount'];
      $data->canAddProduct = ((int) $packageData['productCount'] > (int) $data->productCount);
  
      $data->newsCount = (int) News::find()
        ->where(['company' => $company])
        ->count();
  
      $data->newsCountMax = (int) $packageData['newsCount'];
      $data->canAddNews = ((int) $packageData['newsCount'] > (int) $data->newsCount);
  
      $data->eventCount = (int) Event::find()
        ->where(['company' => $company])
        ->count();
  
      $data->eventCountMax = (int) $packageData['eventCount'];
      $data->canAddEvent = ((int) $packageData['eventCount'] > (int) $data->eventCount);
  
      $data->projectCount = (int) Reference::find()
        ->where(['company' => $company])
        ->count();
      
      $data->projectCountMax = (int) $packageData['projectCount'];
      $data->canAddProject = ((int) $packageData['projectCount'] > (int) $data->projectCount);
      
      $data->downloadCount = (int) Download::find()
        ->where(['company' => $company])
        ->count();
  
      $data->downloadCountMax = (int) $packageData['downloadCount'];
      $data->canAddDownload = ((int) $packageData['downloadCount'] > (int) $data->downloadCount);
      
      return $data;
    }
    
    public static function GUIDv4($trim = true)
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
