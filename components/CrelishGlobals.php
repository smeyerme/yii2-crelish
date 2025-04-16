<?php

namespace giantbits\crelish\components;


use yii\helpers\Json;

class CrelishGlobals
{
  /**
   * Gets all global variables from JSON files
   * @return array All global variables
   */
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

  /**
   * Gets a global variable by key
   * @param string $key The key of the global variable
   * @return mixed The global variable value
   */
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
  
  /**
   * Gets the current page slug
   * 
   * This method provides a consistent way to access the current slug
   * throughout the application, avoiding direct dependency on app.params.content.slug
   * 
   * @return string The current page slug or the entryPoint slug if not available
   */
  public static function getCurrentSlug()
  {
    // Try to get from content
    if (isset(\Yii::$app->params['content']) && isset(\Yii::$app->params['content']['slug'])) {
      return \Yii::$app->params['content']['slug'];
    }
    
    // If not available, try to get from controller
    if (isset(\Yii::$app->controller) && isset(\Yii::$app->controller->entryPoint) && isset(\Yii::$app->controller->entryPoint['slug'])) {
      return \Yii::$app->controller->entryPoint['slug'];
    }
    
    // Fallback to the default entryPoint
    if (isset(\Yii::$app->params['crelish']['entryPoint']['slug'])) {
      return \Yii::$app->params['crelish']['entryPoint']['slug'];
    }
    
    return 'home'; // Ultimate fallback
  }
  
  /**
   * Gets the current page content
   * 
   * This method provides a consistent way to access the current page content
   * throughout the application.
   * 
   * @return array|object|null The current page content or null if not available
   */
  public static function getCurrentPage()
  {
    // Try to get from params
    if (isset(\Yii::$app->params['content'])) {
      return \Yii::$app->params['content'];
    }
    
    // If not available and we're in a frontend controller, try to use the content type and slug
    if (isset(\Yii::$app->controller) && \Yii::$app->controller instanceof CrelishFrontendController) {
      if (isset(\Yii::$app->controller->data)) {
        return \Yii::$app->controller->data;
      }
      
      if (isset(\Yii::$app->controller->entryPoint)) {
        $ctype = \Yii::$app->controller->entryPoint['ctype'];
        $slug = \Yii::$app->controller->entryPoint['slug'];
        
        // Try to load the content
        $entryDataJoint = new CrelishDataManager($ctype, ['filter' => ['slug' => ['strict', $slug]]]);
        if (!empty($entryDataJoint->getProvider()->models[0])) {
          return $entryDataJoint->getProvider()->models[0];
        }
      }
    }
    
    return null;
  }
  
  /**
   * Checks if the current page is the homepage
   * 
   * @return bool True if current page is homepage, false otherwise
   */
  public static function isHomePage()
  {
    $currentSlug = self::getCurrentSlug();
    $entryPointSlug = isset(\Yii::$app->params['crelish']['entryPoint']['slug']) 
      ? \Yii::$app->params['crelish']['entryPoint']['slug'] 
      : 'home';
      
    return $currentSlug === $entryPointSlug;
  }
}
