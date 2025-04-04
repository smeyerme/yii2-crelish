<?php
/**
 * Created by PhpStorm.
 * User: myrst
 * Date: 09.04.2018
 * Time: 16:01
 */

namespace giantbits\crelish\components;


use app\workspace\models\Asset;
use app\workspace\models\Download;
use app\workspace\models\News;
use app\workspace\models\Product;
use app\workspace\models\Reference;
use Cocur\Slugify\Slugify;
use OzdemirBurak\Iris\Color\Hex;
use MatthiasMullie\Minify\CSS;
use Random\RandomException;
use Yii;
use yii\helpers\Url;
use yii\web\JsExpression;
use yii\web\UploadedFile;

class CrelishBaseHelper
{
  public static function urlFromSlug($slug, $params = [], $langCode = null, $scheme = false): string
  {
    $url = '/' . $slug;

    unset($params['pathRequested']);

    if (isset(\Yii::$app->params['crelish']['langprefix']) && \Yii::$app->params['crelish']['langprefix']) {
      if (empty($langCode)) {
        $langCode = \Yii::$app->language;
        if (preg_match('/([a-z]{2})-[A-Z]{2}/', $langCode, $sub)) {
          $langCode = $sub[1];
        }
      }
      $url = '/' . $langCode . $url;
    }

    return Url::to(array_merge([$url], $params), $scheme);
  }

  /**
   * Generates a URL for the current page in a different language
   *
   * @param string $langCode The language code to generate the URL for (e.g., 'en', 'de', 'fr', 'it')
   * @param array $params Additional URL parameters to include
   * @param bool $scheme Whether to include the schema/host info in the URL
   * @return string The URL for the current page in the requested language
   */
  public static function getLanguageUrl($langCode, $params = [], $scheme = false): string
  {
    // Get the current request path
    $pathInfo = \Yii::$app->request->getPathInfo();

    // If we have a language prefix enabled, we need to remove the current language prefix
    if (isset(\Yii::$app->params['crelish']['langprefix']) && \Yii::$app->params['crelish']['langprefix']) {
      $pathParts = explode('/', $pathInfo, 2);

      // Check if the first part is a language code (exactly 2 characters)
      if (isset($pathParts[0]) && strlen($pathParts[0]) === 2) {
        // Remove the language part to get the slug
        $pathInfo = isset($pathParts[1]) ? $pathParts[1] : '';
      }
    }

    // Now we have the slug without language prefix
    $slug = $pathInfo;

    // If it's empty, use the entry point slug
    if (empty($slug)) {
      $slug = \Yii::$app->params['crelish']['entryPoint']['slug'];
    }

    // Generate URL with the new language using urlFromSlug
    return self::urlFromSlug($slug, $params, $langCode, $scheme);
  }

  public static function currentUrl($params = [])
  {
    return Url::to(array_merge(['/' . \Yii::$app->controller->entryPoint['slug']], $params));
  }

  public static function currentCrelishUrl($params = [])
  {
    return Url::current($params);
  }

  public static function getAccountData($company = null): object
  {
    return new class {
    };
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

  public static function serialKey($mask = 'AAAA-AAAA')
  {
    /**
     * MASK
     * 9 = 0-9 NUMBERS
     * x = LOWERCASE LETTERS
     * X = UPPERCASE LETTERS
     * a = ALPHANUMERIC LOWERCASE LETTERS AND 0-9 NUMBERS
     * A = ALPHANUMERIC UPPERCASE LETTERS AND 0-9 NUMBERS
     * # = USE ALL OF THE ABOVE
     * STATIC CHARACTERS = JUST TYPE THEM
     *
     * @see https://techterms.com/definition/activation_key
     * NOTE: An activation key may also be called a product key, software key, license key, registration code, or serial number.
     */
    $serialKey = '';
    for ($i = 0; $i < strlen($mask); ++$i) {
      switch ($mask[$i]) {
        case '9':
          $serialKey .= rand(0, 9);
          break; // 0-9
        case 'x':
          $serialKey .= chr(rand(97, 122));
          break; // a-z
        case 'X':
          $serialKey .= chr(rand(65, 90));
          break; // A-Z
        case 'a':
          if (rand(1, 2) == 1) {
            $serialKey .= chr(rand(97, 122));
          } else {
            $serialKey .= rand(0, 9);
          }
          break; // a-z0-9
        case 'A':
          if (rand(1, 2) == 1) {
            $serialKey .= chr(rand(65, 90));
          } else {
            $serialKey .= rand(0, 9);
          }
          break; // A-Z0-9
        case '#':
          $random = rand(1, 5);
          if ($random == 1) {
            $serialKey .= rand(0, 9); // 0-9
          } else if ($random == 2) {
            $serialKey .= chr(rand(65, 90)); // A-Z
          } else if ($random == 3) {
            $serialKey .= chr(rand(97, 122)); // a-z
          } else if ($random == 4) {
            if (rand(1, 2) == 1) {
              $serialKey .= chr(rand(97, 122));
            } else {
              $serialKey .= rand(0, 9);
            } // a-z0-9
          } else if ($random == 5) {
            if (rand(1, 2) == 1) {
              $serialKey .= chr(rand(65, 90));
            } else {
              $serialKey .= rand(0, 9);
            } // A-Z0-9
          }
          break;
        default:
          $serialKey .= $mask[$i];
          break; // use that what was typed in
      }
    }
    return $serialKey;
  }

  public static function sanitizeFileName($dangerousFilename, $platform = 'Unix')
  {
    if (in_array(strtolower($platform), ['unix', 'linux'])) {
      // our list of "dangerous characters", add/remove
      // characters if necessary
      $dangerousCharacters = [" ", '"', "'", "&", "/", "\\", "?", "#"];
    } else {
      // no OS matched? return the original filename then...
      return $dangerousFilename;
    }

    // every forbidden character is replace by an underscore
    return preg_replace('/[^a-zA-Z0-9\-\._]/', '-', $dangerousFilename);
  }

  public static function lightenColor($hexcolor, $percent)
  {

    $hex = new Hex($hexcolor);

    return $hex->lighten($percent);

    if (strlen($hexcolor) < 6) {
      $hexcolor = $hexcolor[0] . $hexcolor[0] . $hexcolor[1] . $hexcolor[1] . $hexcolor[2] . $hexcolor[2];
    }
    $hexcolor = array_map('hexdec', str_split(str_pad(str_replace('#', '', $hexcolor), 6, '0'), 2));

    foreach ($hexcolor as $i => $color) {
      $from = $percent < 0 ? 0 : $color;
      $to = $percent < 0 ? $color : 255;
      $pvalue = ceil(($to - $from) * $percent);
      $hexcolor[$i] = str_pad(dechex($color + $pvalue), 2, '0', STR_PAD_LEFT);
    }

    return '#' . implode($hexcolor);
  }

  public static function darkenColor($hexcolor, $percent)
  {
    $hex = new Hex($hexcolor);
    return $hex->darken($percent);

    return $hex->shade($percent);
  }

  public static function jsExpression($js)
  {
    return new JsExpression($js);
  }

  public static function registerCustomCss($css, $overwrites = [])
  {

    foreach ($overwrites as $key => $cssVar) {
      $css = str_replace('var(pvar-' . $key . '--)', $cssVar, $css);
    }

    $minifier = new CSS();
    $minifier->add($css);

    \Yii::$app->view->registerCss($minifier->minify());
  }

  public static function getAssetUrl($path, $file) {
    $cleanPath = str_starts_with($path, '/') ? $path : '/' . $path;
    return $cleanPath . (str_ends_with($cleanPath, '/') ? $file : '/' . $file);
  }

  public static function getAssetUrlById($uuid) {
    $asset = Asset::findOne($uuid);

    if(!$asset) {
      return null;
    }

    return self::getAssetUrl($asset->pathName, $asset->fileName);
  }

  /**
   * @throws RandomException
   */
  public static function uplaodAsset($file): array|bool
  {

    $slugify = new Slugify();
    $dir = Yii::getAlias('@webroot/uploads');
    $prefix = bin2hex(random_bytes(6));
    $uploadedFile = UploadedFile::getInstanceByName($file);

    if ($uploadedFile) {
      $cleanFileName = $slugify->slugify($uploadedFile->baseName);
      $finalName = $prefix . '_' . $cleanFileName . '.' . $uploadedFile->extension;

      $uploadedFile->saveAs($dir . DIRECTORY_SEPARATOR . $finalName);

      return [
        'type' => $uploadedFile->type,
        'filename' => $finalName,
        'size' => $uploadedFile->size,
      ];
    }

    return false;
  }
}
