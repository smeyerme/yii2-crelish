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
    // Handle home page special case
    $isHomePage = ($slug === \Yii::$app->params['crelish']['entryPoint']['slug']);
    
    $url = $isHomePage ? '' : '/' . $slug;

    unset($params['pathRequested']);

    if (isset(\Yii::$app->params['crelish']['langprefix']) && \Yii::$app->params['crelish']['langprefix']) {
      if (empty($langCode)) {
        $langCode = \Yii::$app->language;
        if (preg_match('/([a-z]{2})-[A-Z]{2}/', $langCode, $sub)) {
          $langCode = $sub[1];
        }
      }
      
      if ($isHomePage) {
        // For homepage with language prefix, just return the language
        $url = '/' . $langCode;
      } else {
        // For other pages with language prefix
        $url = '/' . $langCode . $url;
      }
    } else if ($isHomePage) {
      // When no language prefix but it's homepage, return root
      $url = '/';
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

    // If it's empty, we're on the homepage
    if (empty($slug)) {
      $slug = \Yii::$app->params['crelish']['entryPoint']['slug'];
    }

    // For homepage, make sure to set pathRequested to maintain consistency
    if (empty($pathInfo) && !isset($params['pathRequested'])) {
      $params['pathRequested'] = \Yii::$app->params['crelish']['entryPoint']['slug'];
    }
    
    // Generate URL with the new language using urlFromSlug
    return self::urlFromSlug($slug, $params, $langCode, $scheme);
  }

  public static function currentUrl($params = [])
  {
    $slug = \Yii::$app->controller->entryPoint['slug'];
    $isHomePage = ($slug === \Yii::$app->params['crelish']['entryPoint']['slug']);
    
    if ($isHomePage && isset(\Yii::$app->params['crelish']['langprefix']) && \Yii::$app->params['crelish']['langprefix']) {
      // For homepage with language prefix
      $langCode = \Yii::$app->language;
      if (preg_match('/([a-z]{2})-[A-Z]{2}/', $langCode, $sub)) {
        $langCode = $sub[1];
      }
      return Url::to(array_merge(['/' . $langCode], $params));
    } else if ($isHomePage) {
      // For homepage without language prefix
      return Url::to(array_merge(['/'], $params));
    }
    
    // For non-homepage
    return Url::to(array_merge(['/' . $slug], $params));
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
   * Track a content element view for analytics
   * 
   * This can be called from any template to track element views,
   * including list views, detail views, and widget-rendered elements.
   * 
   * Usage in templates:
   * {{ chelper.trackElementView(element.uuid, element.ctype, 'list') }}
   * {{ chelper.trackElementView(element.uuid, element.ctype, 'detail') }}
   * 
   * @param string $elementUuid The UUID of the element to track
   * @param string $elementType The type of the element (e.g., 'news', 'job', 'boardgame')
   * @param string $viewType Optional. The type of view ('list', 'detail', etc.)
   * @return bool|string Returns the element UUID if tracking was successful, false otherwise
   */
  public static function trackElementView($elementUuid, $elementType, $viewType = null)
  {
    // Skip if analytics component isn't available or no page UUID
    if (!isset(\Yii::$app->crelishAnalytics) || 
        !isset(\Yii::$app->controller->entryPoint['uuid'])) {
      return false;
    }
    
    // Skip tracking if we don't have necessary element data
    if (empty($elementUuid) || empty($elementType)) {
      return false;
    }
    
    $trackingType = $elementType;
    
    // Track the element view
    $result = \Yii::$app->crelishAnalytics->trackElementView(
      $elementUuid,
      $trackingType,
      \Yii::$app->controller->entryPoint['uuid'],
      $viewType ?? null
    );
    
    // Return the element UUID if tracking was successful (useful for chaining in templates)
    // $result ? $elementUuid : false
    return;
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
  
  /**
   * Get a tracked download URL for an asset
   * 
   * This helper generates a URL that will track the download of the asset
   * using the DownloadAction and analytics_element_views table.
   * 
   * @param string $uuid The UUID of the asset
   * @param array $options Configuration options:
   *   - inline: bool - Whether to display the file inline rather than download it
   *   - filename: string - Custom filename to use for the download
   * @return string URL for tracked download
   */
  public static function getTrackedDownloadUrl($uuid, $options = []): string
  {
    if (empty($uuid)) {
      return '';
    }
    
    $params = ['uuid' => $uuid];
    
    // Add inline parameter if specified
    if (isset($options['inline']) && $options['inline']) {
      $params['inline'] = 1;
    }
    
    // Generate URL to download action
    return Yii::$app->urlManager->createUrl(array_merge(['/crelish/asset/download'], $params));
  }
  
  /**
   * Generate responsive image HTML using asset UUID
   * 
   * This helper intelligently generates appropriate HTML for responsive images
   * based on the intended usage context. It automatically utilizes the Asset model's
   * stored attributes to prevent layout shift and provide proper alt text.
   * 
   * @param string $uuid The UUID of the asset
   * @param array $options Configuration options:
   *   - preset: string - Name of a predefined preset (hero, card, thumbnail, content)
   *   - sizes: string - The sizes attribute for the img tag
   *   - widths: array - Custom widths to generate
   *   - format: string - Image format (webp, jpg) - defaults to webp
   *   - quality: int - Image quality (0-100) - defaults to 75
   *   - alt: string - Custom alt text for the image (overrides asset description/filename)
   *   - class: string - CSS classes for the img tag
   *   - loading: string - Loading attribute (lazy, eager) - defaults to lazy
   *   - width: int - Custom width attribute (overrides asset width)
   *   - height: int - Custom height attribute (overrides asset height)
   * @return string HTML for responsive image
   */
  public static function responsiveImage($uuid, $options = []): string
  {
    // Try to get the asset
    $asset = \app\workspace\models\Asset::findOne($uuid);
    if (!$asset) {
      return '';
    }
    
    // First determine alt text from asset data
    $altText = '';
    if (!empty($asset->description)) {
      $altText = $asset->description;
    } elseif (!empty($asset->fileName)) {
      $altText = pathinfo($asset->fileName, PATHINFO_FILENAME);
    }
    
    // Default options
    $defaults = [
      'preset' => 'default',
      'sizes' => '100vw',
      'widths' => [480, 768, 1024],
      'format' => 'webp',
      'quality' => 75,
      'alt' => $altText,
      'class' => 'img-fluid',
      'loading' => 'lazy',
      'width' => $asset->width ?? null,
      'height' => $asset->height ?? null
    ];
    
    // Merge options with defaults
    $options = array_merge($defaults, $options);
    
    // Configure presets for common use cases
    $presets = [
      'hero' => [
        'widths' => [480, 768, 1024, 1600, 2000],
        'sizes' => '(max-width: 767px) 100vw, (max-width: 1199px) 100vw, 100vw',
        'loading' => 'eager',
      ],
      'card' => [
        'widths' => [300, 600, 900],
        'sizes' => '(max-width: 767px) 100vw, (max-width: 991px) 50vw, (max-width: 1199px) 33.333vw, 25vw',
      ],
      'thumbnail' => [
        'widths' => [120, 240],
        'sizes' => '120px',
      ],
      'content' => [
        'widths' => [400, 800, 1200],
        'sizes' => '(max-width: 767px) 100vw, (max-width: 991px) 75vw, 50vw',
      ],
      'default' => [
        'widths' => [480, 768, 1024],
        'sizes' => '100vw',
      ]
    ];
    
    // Apply preset if specified and exists
    if (isset($presets[$options['preset']])) {
      $options = array_merge($options, $presets[$options['preset']]);
    }
    
    // Get original dimensions for aspect ratio calculation
    $originalWidth = $asset->width ?? 0;
    $originalHeight = $asset->height ?? 0;
    
    // Calculate dimensions for the responsive image to maintain aspect ratio
    if ($originalWidth > 0 && $originalHeight > 0) {
      $aspectRatio = $originalWidth / $originalHeight;
      
      // If custom width specified but no height, calculate height to maintain aspect ratio
      if ($options['width'] && !$options['height']) {
        $options['height'] = round($options['width'] / $aspectRatio);
      }
      // If custom height specified but no width, calculate width to maintain aspect ratio
      elseif ($options['height'] && !$options['width']) {
        $options['width'] = round($options['height'] * $aspectRatio);
      }
    }
    
    // Path to the original image
    $imagePath = self::getAssetUrl($asset->pathName, $asset->fileName);
    
    // Base URL with format and quality
    $baseUrl = '/crelish/asset/glide?path=' . ltrim($imagePath, '/') . '&q=' . $options['quality'] . '&fm=' . $options['format'];
    
    // Generate the highest resolution version for src
    $mainWidth = max($options['widths']);
    $mainUrl = $baseUrl . '&w=' . $mainWidth;
    
    // Generate srcset
    $srcset = [];
    foreach ($options['widths'] as $width) {
      $url = $baseUrl . '&w=' . $width;
      $srcset[] = $url . ' ' . $width . 'w';
    }
    
    // Build HTML attributes for the img tag
    $attrs = [
      'src' => $mainUrl,
      'alt' => $options['alt'],
      'class' => $options['class'],
      'loading' => $options['loading'],
      'srcset' => implode(', ', $srcset),
      'sizes' => $options['sizes'],
      'width' => $options['width'],
      'height' => $options['height']
    ];
    
    // Build the HTML string
    $html = '<img';
    foreach ($attrs as $name => $value) {
      if ($value !== null && $value !== '') {
        $html .= ' ' . $name . '="' . htmlspecialchars($value) . '"';
      }
    }
    $html .= '>';
    
    return $html;
  }
}
