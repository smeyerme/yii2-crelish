<?php

namespace giantbits\crelish\components;

use app\workspace\models\Asset;
use Cocur\Slugify\Slugify;
use MatthiasMullie\Minify\CSS;
use Yii;
use yii\helpers\Url;
use yii\helpers\VarDumper;
use yii\web\JsExpression;
use yii\web\UploadedFile;

/**
 * Base helper class for Crelish CMS
 * 
 * Provides utility methods for URL generation, color manipulation,
 * file handling, and other common operations.
 */
class CrelishBaseHelper
{
  /**
   * @var array Cache for language code extraction
   */
  private static $langCodeCache = [];
  
  /**
   * @var Slugify Cached slugify instance
   */
  private static $slugify;
  
  /**
   * Generate URL from slug with language prefix support
   * 
   * @param string $slug The URL slug
   * @param array $params Additional URL parameters
   * @param string|null $langCode Language code to use
   * @param bool|string $scheme URL scheme
   * @return string Generated URL
   */
  public static function urlFromSlug($slug, $params = [], $langCode = null, $scheme = false): string
  {
    // Handle home page special case
    $entryPoint = Yii::$app->params['crelish']['entryPoint'] ?? [];
    $isHomePage = ($slug === ($entryPoint['slug'] ?? ''));
    
    $url = $isHomePage ? '' : '/' . $slug;

    unset($params['pathRequested']);

    if (isset(Yii::$app->params['crelish']['langprefix']) && Yii::$app->params['crelish']['langprefix']) {
      if (empty($langCode)) {
        $langCode = self::extractLanguageCode(Yii::$app->language);
      }
      
      $url = $isHomePage ? '/' . $langCode : '/' . $langCode . $url;
    } elseif ($isHomePage) {
      $url = '/';
    }

    return Url::to(array_merge([$url], $params), $scheme);
  }

  /**
   * Generates a URL for the current page in a different language
   *
   * @param string $langCode The language code (e.g., 'en', 'de', 'fr')
   * @param array $params Additional URL parameters
   * @param bool|string $scheme Whether to include the schema/host info
   * @return string The URL for the current page in the requested language
   */
  public static function getLanguageUrl($langCode, $params = [], $scheme = false): string
  {
    // Get the current request path
    $pathInfo = Yii::$app->request->getPathInfo();
    
    // If we have a language prefix enabled, we need to remove the current language prefix
    if (isset(Yii::$app->params['crelish']['langprefix']) && Yii::$app->params['crelish']['langprefix']) {
      $pathParts = explode('/', $pathInfo, 2);

      // Check if the first part is a language code (exactly 2 characters)
      if (isset($pathParts[0]) && strlen($pathParts[0]) === 2) {
        // Remove the language part to get the slug
        $pathInfo = $pathParts[1] ?? '';
      }
    }

    // Now we have the slug without language prefix
    $slug = $pathInfo;
    $entryPointSlug = Yii::$app->params['crelish']['entryPoint']['slug'] ?? '';

    // If it's empty, we're on the homepage
    if (empty($slug)) {
      $slug = $entryPointSlug;
    }

    // For homepage, make sure to set pathRequested to maintain consistency
    if (empty($pathInfo) && !isset($params['pathRequested'])) {
      $params['pathRequested'] = $entryPointSlug;
    }
    
    // Generate URL with the new language using urlFromSlug
    return self::urlFromSlug($slug, $params, $langCode, $scheme);
  }

  /**
   * Get current page URL with optional parameters
   * 
   * @param array $params Additional URL parameters
   * @return string Current page URL
   */
  public static function currentUrl($params = [])
  {
    $slug = Yii::$app->controller->entryPoint['slug'] ?? '';
    $langCode = null;
    
    // Determine the current language code
    if (isset(Yii::$app->params['crelish']['langprefix']) && Yii::$app->params['crelish']['langprefix']) {
      $langCode = self::extractLanguageCode(Yii::$app->language);
    }
    
    // Use urlFromSlug to generate the URL with proper language handling
    return self::urlFromSlug($slug, $params, $langCode);
  }

  /**
   * Get current Crelish URL (wrapper for Yii's current URL)
   * 
   * @param array $params URL parameters
   * @return string Current URL
   */
  public static function currentCrelishUrl($params = [])
  {
    return Url::current($params);
  }

  /**
   * Get account data placeholder
   * 
   * @param mixed $company Company identifier
   * @return object Empty object
   * @deprecated This method returns an empty object and should be implemented properly
   */
  public static function getAccountData($company = null): object
  {
    return new class {};
  }

  /**
   * Generate a GUID v4
   * 
   * @param bool $trim Whether to trim braces
   * @return string Generated GUID
   */
  public static function GUIDv4($trim = true): string
  {
    // Prefer crypto-secure methods
    if (function_exists('random_bytes')) {
      $data = random_bytes(16);
      $data[6] = chr(ord($data[6]) & 0x0f | 0x40);    // set version to 0100
      $data[8] = chr(ord($data[8]) & 0x3f | 0x80);    // set bits 6-7 to 10
      $guid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
      return $trim ? $guid : '{' . $guid . '}';
    }
    
    // Windows
    if (function_exists('com_create_guid')) {
      return $trim ? trim(com_create_guid(), '{}') : com_create_guid();
    }

    // OSX/Linux with OpenSSL
    if (function_exists('openssl_random_pseudo_bytes')) {
      $data = openssl_random_pseudo_bytes(16);
      $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
      $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
      $guid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
      return $trim ? $guid : '{' . $guid . '}';
    }

    // Fallback (less secure)
    $charid = strtolower(md5(uniqid(mt_rand(), true)));
    $hyphen = '-';
    $lbrace = $trim ? '' : '{';
    $rbrace = $trim ? '' : '}';
    return $lbrace .
      substr($charid, 0, 8) . $hyphen .
      substr($charid, 8, 4) . $hyphen .
      substr($charid, 12, 4) . $hyphen .
      substr($charid, 16, 4) . $hyphen .
      substr($charid, 20, 12) .
      $rbrace;
  }

  /**
   * Generate a serial key based on a mask pattern
   * 
   * MASK:
   * - 9 = 0-9 NUMBERS
   * - x = LOWERCASE LETTERS
   * - X = UPPERCASE LETTERS
   * - a = ALPHANUMERIC LOWERCASE LETTERS AND 0-9 NUMBERS
   * - A = ALPHANUMERIC UPPERCASE LETTERS AND 0-9 NUMBERS
   * - # = USE ALL OF THE ABOVE
   * - STATIC CHARACTERS = JUST TYPE THEM
   * 
   * @param string $mask The mask pattern (e.g., 'AAAA-AAAA')
   * @return string Generated serial key
   */
  public static function serialKey($mask = 'AAAA-AAAA'): string
  {
    $serialKey = '';
    $maskLength = strlen($mask);
    
    for ($i = 0; $i < $maskLength; ++$i) {
      switch ($mask[$i]) {
        case '9':
          $serialKey .= mt_rand(0, 9);
          break;
        case 'x':
          $serialKey .= chr(mt_rand(97, 122));
          break;
        case 'X':
          $serialKey .= chr(mt_rand(65, 90));
          break;
        case 'a':
          $serialKey .= mt_rand(0, 1) ? chr(mt_rand(97, 122)) : mt_rand(0, 9);
          break;
        case 'A':
          $serialKey .= mt_rand(0, 1) ? chr(mt_rand(65, 90)) : mt_rand(0, 9);
          break;
        case '#':
          $random = mt_rand(1, 3);
          if ($random === 1) {
            $serialKey .= mt_rand(0, 9);
          } elseif ($random === 2) {
            $serialKey .= chr(mt_rand(65, 90));
          } else {
            $serialKey .= chr(mt_rand(97, 122));
          }
          break;
        default:
          $serialKey .= $mask[$i];
          break;
      }
    }
    
    return $serialKey;
  }

  /**
   * Sanitize a filename for safe filesystem usage
   * 
   * @param string $dangerousFilename The filename to sanitize
   * @param string $platform Target platform (Unix/Linux)
   * @return string Sanitized filename
   */
  public static function sanitizeFileName($dangerousFilename, $platform = 'Unix'): string
  {
    if (!in_array(strtolower($platform), ['unix', 'linux'])) {
      return $dangerousFilename;
    }
    
    // Replace dangerous characters with hyphens
    return preg_replace('/[^a-zA-Z0-9\-\._]/', '-', $dangerousFilename);
  }

  /**
   * Lightens a hex color by the specified percentage
   * 
   * @param string $hexcolor Hex color code (e.g. "#FF9E1B" or "F9B")
   * @param float $percent Percentage to lighten (0-1)
   * @return string Lightened hex color
   */
  public static function lightenColor($hexcolor, $percent): string
  {
    $rgb = self::hexToRgb($hexcolor);
    
    // Lighten each component
    $r = (int)min(255, $rgb['r'] + ($percent * (255 - $rgb['r'])));
    $g = (int)min(255, $rgb['g'] + ($percent * (255 - $rgb['g'])));
    $b = (int)min(255, $rgb['b'] + ($percent * (255 - $rgb['b'])));
    
    return sprintf("#%02x%02x%02x", $r, $g, $b);
  }

  /**
   * Darkens a hex color by the specified percentage
   * 
   * @param string $hexcolor Hex color code (e.g. "#FF9E1B" or "F9B")
   * @param float $percent Percentage to darken (0-1)
   * @return string Darkened hex color
   */
  public static function darkenColor($hexcolor, $percent): string
  {
    $rgb = self::hexToRgb($hexcolor);
    
    // Darken each component
    $r = (int)max(0, $rgb['r'] - ($percent * $rgb['r']));
    $g = (int)max(0, $rgb['g'] - ($percent * $rgb['g']));
    $b = (int)max(0, $rgb['b'] - ($percent * $rgb['b']));
    
    return sprintf("#%02x%02x%02x", $r, $g, $b);
  }

  /**
   * Create a JavaScript expression for Yii
   * 
   * @param string $js JavaScript code
   * @return JsExpression JavaScript expression object
   */
  public static function jsExpression($js): JsExpression
  {
    return new JsExpression($js);
  }

  /**
   * Register custom CSS with variable replacements
   * 
   * @param string $css CSS code
   * @param array $overwrites Variable replacements
   */
  public static function registerCustomCss($css, $overwrites = []): void
  {
    foreach ($overwrites as $key => $cssVar) {
      $css = str_replace('var(pvar-' . $key . '--)', $cssVar, $css);
    }

    $minifier = new CSS();
    $minifier->add($css);

    Yii::$app->view->registerCss($minifier->minify());
  }

  /**
   * Get asset URL from path and filename
   * 
   * @param string $path Asset path
   * @param string $file Asset filename
   * @return string Complete asset URL
   */
  public static function getAssetUrl($path, $file): string
  {
    $cleanPath = str_starts_with($path, '/') ? $path : '/' . $path;
    return $cleanPath . (str_ends_with($cleanPath, '/') ? '' : '/') . $file;
  }

  /**
   * Get asset URL by UUID
   * 
   * @param string $uuid Asset UUID
   * @return string|null Asset URL or null if not found
   */
  public static function getAssetUrlById($uuid): ?string
  {
    $asset = Asset::findOne($uuid);
    
    if (!$asset) {
      return null;
    }
    
    return self::getAssetUrl($asset->pathName, $asset->fileName);
  }
  
  /**
   * Track a content element view for analytics
   *
   * Usage in templates:
   * {{ chelper.trackElementView(element.uuid, element.ctype, 'list') }}
   *
   * @param string $elementUuid The UUID of the element to track
   * @param string $elementType The type of the element
   * @param string|null $viewType Optional view type ('list', 'detail', etc.)
   * @return void
   */
  public static function trackElementView($elementUuid, $elementType, $viewType = null): void
  {
    // Skip if analytics component isn't available or no page UUID
    if (!isset(Yii::$app->crelishAnalytics) ||
        !isset(Yii::$app->controller->entryPoint['uuid']) ||
        empty($elementUuid) ||
        empty($elementType)) {
      return;
    }

    // Track the element view
    Yii::$app->crelishAnalytics->trackElementView(
      $elementUuid,
      $elementType,
      Yii::$app->controller->entryPoint['uuid'],
      $viewType
    );
  }

  /**
   * Generate a secure token for click tracking
   *
   * This token is used to verify that click tracking requests are legitimate
   * and prevents unauthorized tracking spam.
   *
   * Usage in templates:
   * <a href="https://example.com"
   *    ping="{{ chelper.getClickTrackingUrl(element.uuid, element.ctype) }}">
   *   Link Text
   * </a>
   *
   * @param string $uuid Element UUID
   * @return string Secure token
   */
  public static function generateClickToken($uuid): string
  {
    $timestamp = time();
    $secret = Yii::$app->security->passwordHashStrategy;
    $hash = substr(hash_hmac('sha256', $uuid . $timestamp, $secret), 0, 16);
    $timeBase36 = str_pad(base_convert($timestamp, 10, 36), 10, '0', STR_PAD_LEFT);

    return $hash . $timeBase36;
  }

  /**
   * Generate a complete click tracking URL for use with HTML ping attribute
   *
   * Usage in templates:
   * <a href="https://example.com"
   *    ping="{{ chelper.getClickTrackingUrl(element.uuid, element.ctype) }}">
   *   Link Text
   * </a>
   *
   * Or for multiple pings:
   * <a href="https://example.com"
   *    ping="{{ chelper.getClickTrackingUrl(element.uuid, element.ctype) }} https://other-tracker.com/ping">
   *   Link Text
   * </a>
   *
   * @param string $uuid Element UUID
   * @param string $type Element type (e.g., 'ad', 'link', 'banner')
   * @param string|null $pageUuid Optional page UUID (defaults to current page)
   * @return string Complete tracking URL
   */
  public static function getClickTrackingUrl($uuid, $type = 'link', $pageUuid = null): string
  {
    // Generate secure token
    $token = self::generateClickToken($uuid);

    // Get page UUID
    if (empty($pageUuid)) {
      $pageUuid = Yii::$app->controller->entryPoint['uuid'] ?? null;
    }

    // Build tracking URL
    $params = [
      'uuid' => $uuid,
      'type' => $type,
      'token' => $token,
    ];

    if (!empty($pageUuid)) {
      $params['page'] = $pageUuid;
    }

    return Url::to(array_merge(['/crelish/track/click'], $params), true);
  }

  /**
   * Upload an asset file
   * 
   * @param string $file File input name
   * @return array|false File information or false on failure
   * @throws \Random\RandomException
   */
  public static function uploadAsset($file)
  {
    $uploadedFile = UploadedFile::getInstanceByName($file);
    
    if (!$uploadedFile) {
      return false;
    }
    
    $slugify = self::getSlugify();
    $dir = Yii::getAlias('@webroot/uploads');
    $prefix = bin2hex(random_bytes(6));
    
    $cleanFileName = $slugify->slugify($uploadedFile->baseName);
    $finalName = $prefix . '_' . $cleanFileName . '.' . $uploadedFile->extension;
    
    if ($uploadedFile->saveAs($dir . DIRECTORY_SEPARATOR . $finalName)) {
      return [
        'type' => $uploadedFile->type,
        'filename' => $finalName,
        'size' => $uploadedFile->size,
      ];
    }
    
    return false;
  }

  public static function dump($data, $level = 10, $formated = true) {
    if(!YII_DEBUG) return;

    // Generate unique ID for this dump instance
    $dumpId = 'dump_' . uniqid();
    
    // Convert data to formatted HTML
    $output = self::renderDumpHtml($data, $level, $dumpId);
    
    // Output the dump with styles and scripts
    echo $output;
  }
  
  /**
   * Dump and die - outputs formatted dump and terminates execution
   * 
   * @param mixed $data Data to dump
   * @param int $level Maximum depth level
   * @param bool $formated Whether to format the output (legacy parameter, always true now)
   */
  public static function dd($data, $level = 10, $formated = true) {
    if(!YII_DEBUG) return;

    // Clean all existing output buffers
    while (ob_get_level()) {
      ob_end_clean();
    }
    
    // Start fresh output buffering
    ob_start();
    
    // Generate unique ID for this dump instance
    $dumpId = 'dump_' . uniqid();
    
    // Add a special die indicator to the dump
    $output = self::renderDumpHtml($data, $level, $dumpId, 0, true);
    
    // Clear any accidental output and send only our dump
    ob_clean();
    echo $output;
    
    // Terminate execution
    die();
  }
  
  /**
   * Renders data as interactive HTML dump
   */
  private static function renderDumpHtml($data, $maxLevel = 10, $dumpId = null, $currentLevel = 0) {
    if ($currentLevel === 0) {
      // Root level - include styles and scripts
      $html = self::getDumpStyles($dumpId);
      $html .= self::getDumpScript($dumpId);
      $html .= '<div class="crelish-dump" id="' . $dumpId . '">';
      $html .= self::renderDumpValue($data, $maxLevel, $currentLevel);
      $html .= '</div>';
      return $html;
    }
    
    return self::renderDumpValue($data, $maxLevel, $currentLevel);
  }
  
  /**
   * Renders a single value in the dump
   */
  private static function renderDumpValue($data, $maxLevel, $currentLevel) {
    $type = gettype($data);
    
    if ($currentLevel >= $maxLevel) {
      return '<span class="dump-max-depth">*MAX DEPTH*</span>';
    }
    
    switch ($type) {
      case 'NULL':
        return '<span class="dump-null">null</span>';
        
      case 'boolean':
        return '<span class="dump-bool">' . ($data ? 'true' : 'false') . '</span>';
        
      case 'integer':
        return '<span class="dump-int">' . $data . '</span>';
        
      case 'double':
        return '<span class="dump-float">' . $data . '</span>';
        
      case 'string':
        $escaped = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        $length = mb_strlen($data);
        if ($length > 80) {
          $preview = htmlspecialchars(mb_substr($data, 0, 80), ENT_QUOTES, 'UTF-8');
          $fullId = 'str_' . uniqid();
          return '<span class="dump-string dump-expandable" data-full-id="' . $fullId . '">'
            . '<span class="dump-string-preview">"' . $preview . '..."</span>'
            . '<span class="dump-string-full" id="' . $fullId . '" style="display:none;">"' . $escaped . '"</span>'
            . '<span class="dump-meta">(' . $length . ')</span>'
            . '</span>';
        }
        return '<span class="dump-string">"' . $escaped . '"</span><span class="dump-meta">(' . $length . ')</span>';
        
      case 'array':
        $count = count($data);
        if ($count === 0) {
          return '<span class="dump-array">array(0) []</span>';
        }
        
        $isAssoc = array_keys($data) !== range(0, $count - 1);
        $collapsed = $currentLevel > 0;
        $html = '<div class="dump-array">';
        $html .= '<span class="dump-toggle' . ($collapsed ? ' dump-collapsed' : '') . '" data-toggle="array">';
        $html .= '<span class="dump-arrow">' . ($collapsed ? '▶' : '▼') . '</span> ';
        $html .= 'array(' . $count . ')';
        $html .= '</span>';
        $html .= '<div class="dump-content"' . ($collapsed ? ' style="display:none;"' : '') . '>';
        
        foreach ($data as $key => $value) {
          $html .= '<div class="dump-item">';
          if ($isAssoc) {
            $html .= '<span class="dump-key">' . htmlspecialchars($key) . '</span>';
            $html .= '<span class="dump-arrow-right">⇒</span> ';
          } else {
            $html .= '<span class="dump-index">[' . $key . ']</span> ';
          }
          $html .= self::renderDumpValue($value, $maxLevel, $currentLevel + 1);
          $html .= '</div>';
        }
        
        $html .= '</div></div>';
        return $html;
        
      case 'object':
        $className = get_class($data);
        $shortName = (new \ReflectionClass($data))->getShortName();
        $properties = [];
        
        // Get all properties using reflection
        $reflection = new \ReflectionClass($data);
        foreach ($reflection->getProperties() as $prop) {
          $prop->setAccessible(true);
          $name = $prop->getName();
          $modifiers = [];
          if ($prop->isPrivate()) $modifiers[] = 'private';
          if ($prop->isProtected()) $modifiers[] = 'protected';
          if ($prop->isPublic()) $modifiers[] = 'public';
          if ($prop->isStatic()) $modifiers[] = 'static';
          
          $properties[] = [
            'name' => $name,
            'value' => $prop->getValue($prop->isStatic() ? null : $data),
            'modifiers' => implode(' ', $modifiers)
          ];
        }
        
        $collapsed = $currentLevel > 0;
        $html = '<div class="dump-object">';
        $html .= '<span class="dump-toggle' . ($collapsed ? ' dump-collapsed' : '') . '" data-toggle="object">';
        $html .= '<span class="dump-arrow">' . ($collapsed ? '▶' : '▼') . '</span> ';
        $html .= '<span class="dump-classname" title="' . htmlspecialchars($className) . '">' . htmlspecialchars($shortName) . '</span>';
        $html .= '<span class="dump-meta">{' . count($properties) . '}</span>';
        $html .= '</span>';
        $html .= '<div class="dump-content"' . ($collapsed ? ' style="display:none;"' : '') . '>';
        
        foreach ($properties as $prop) {
          $html .= '<div class="dump-item">';
          $html .= '<span class="dump-modifier">' . $prop['modifiers'] . '</span> ';
          $html .= '<span class="dump-property">$' . htmlspecialchars($prop['name']) . '</span>';
          $html .= '<span class="dump-arrow-right">⇒</span> ';
          $html .= self::renderDumpValue($prop['value'], $maxLevel, $currentLevel + 1);
          $html .= '</div>';
        }
        
        $html .= '</div></div>';
        return $html;
        
      case 'resource':
        $resourceType = get_resource_type($data);
        return '<span class="dump-resource">resource(' . $resourceType . ')</span>';
        
      default:
        return '<span class="dump-unknown">' . $type . '</span>';
    }
  }
  
  /**
   * Returns CSS styles for the dump
   */
  private static function getDumpStyles($dumpId) {
    return <<<CSS
<style>
#$dumpId.crelish-dump {
  font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace;
  font-size: 13px;
  line-height: 1.6;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  padding: 3px;
  border-radius: 8px;
  margin: 10px 0;
  box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

#$dumpId.crelish-dump > * {
  background: #1a1a2e;
  border-radius: 6px;
  padding: 15px;
  color: #e8e8e8;
}

#$dumpId .dump-toggle {
  cursor: pointer;
  user-select: none;
  display: inline-flex;
  align-items: center;
  padding: 2px 6px;
  border-radius: 4px;
  transition: background 0.2s;
}

#$dumpId .dump-toggle:hover {
  background: rgba(255,255,255,0.05);
}

#$dumpId .dump-arrow {
  display: inline-block;
  width: 12px;
  color: #9ca3af;
  transition: transform 0.2s;
  font-size: 11px;
}

#$dumpId .dump-collapsed .dump-arrow {
  transform: rotate(0deg);
}

#$dumpId .dump-content {
  margin-left: 20px;
  padding-left: 10px;
  border-left: 1px solid rgba(255,255,255,0.1);
  margin-top: 4px;
}

#$dumpId .dump-item {
  margin: 4px 0;
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
}

#$dumpId .dump-key {
  color: #f472b6;
  font-weight: 600;
  margin-right: 4px;
}

#$dumpId .dump-index {
  color: #60a5fa;
  margin-right: 4px;
}

#$dumpId .dump-property {
  color: #a78bfa;
  margin-right: 4px;
}

#$dumpId .dump-arrow-right {
  color: #6b7280;
  margin: 0 4px;
  font-size: 11px;
}

#$dumpId .dump-string {
  color: #86efac;
}

#$dumpId .dump-string-preview {
  color: #86efac;
}

#$dumpId .dump-string-full {
  color: #86efac;
}

#$dumpId .dump-string.dump-expandable {
  cursor: pointer;
  border-bottom: 1px dashed #4ade80;
}

#$dumpId .dump-string.dump-expandable:hover {
  background: rgba(134, 239, 172, 0.1);
  padding: 1px 3px;
  border-radius: 3px;
}

#$dumpId .dump-int {
  color: #fbbf24;
  font-weight: 600;
}

#$dumpId .dump-float {
  color: #fb923c;
  font-weight: 600;
}

#$dumpId .dump-bool {
  color: #c084fc;
  font-weight: 600;
  font-style: italic;
}

#$dumpId .dump-null {
  color: #ef4444;
  font-weight: 600;
  font-style: italic;
}

#$dumpId .dump-array {
  color: #60a5fa;
}

#$dumpId .dump-object {
  color: #fbbf24;
}

#$dumpId .dump-classname {
  color: #fbbf24;
  font-weight: 600;
  background: rgba(251, 191, 36, 0.1);
  padding: 2px 6px;
  border-radius: 3px;
  border: 1px solid rgba(251, 191, 36, 0.3);
}

#$dumpId .dump-modifier {
  color: #6b7280;
  font-size: 11px;
  background: rgba(107, 114, 128, 0.1);
  padding: 1px 4px;
  border-radius: 2px;
}

#$dumpId .dump-meta {
  color: #6b7280;
  font-size: 11px;
  margin-left: 4px;
}

#$dumpId .dump-resource {
  color: #f97316;
  font-style: italic;
}

#$dumpId .dump-unknown {
  color: #dc2626;
}

#$dumpId .dump-max-depth {
  color: #6b7280;
  font-style: italic;
  background: rgba(107, 114, 128, 0.1);
  padding: 2px 6px;
  border-radius: 3px;
}

/* Animations */
@keyframes dump-highlight {
  0% { background: rgba(99, 102, 241, 0.3); }
  100% { background: transparent; }
}

#$dumpId .dump-content.dump-expanding {
  animation: dump-highlight 0.3s ease-out;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  #$dumpId.crelish-dump {
    font-size: 12px;
    margin: 5px 0;
  }
  
  #$dumpId .dump-content {
    margin-left: 15px;
    padding-left: 8px;
  }
}

/* Keyboard navigation highlight */
#$dumpId .dump-toggle:focus {
  outline: 2px solid #60a5fa;
  outline-offset: 1px;
}

/* Copy button for strings */
#$dumpId .dump-copy {
  display: inline-block;
  margin-left: 8px;
  padding: 2px 6px;
  background: rgba(99, 102, 241, 0.2);
  border: 1px solid rgba(99, 102, 241, 0.4);
  border-radius: 3px;
  color: #a5b4fc;
  font-size: 10px;
  cursor: pointer;
  transition: all 0.2s;
}

#$dumpId .dump-copy:hover {
  background: rgba(99, 102, 241, 0.3);
  color: #c7d2fe;
}

#$dumpId .dump-copy.copied {
  background: rgba(134, 239, 172, 0.2);
  border-color: rgba(134, 239, 172, 0.4);
  color: #86efac;
}
</style>
CSS;
  }
  
  /**
   * Returns JavaScript for interactive features
   */
  private static function getDumpScript($dumpId) {
    return <<<SCRIPT
<script>
(function() {
  // Wait for DOM to be ready
  function initDump() {
    const dumpEl = document.getElementById('$dumpId');
    if (!dumpEl) return;
    
    // Remove any existing listeners to prevent duplicates
    const newDump = dumpEl.cloneNode(true);
    dumpEl.parentNode.replaceChild(newDump, dumpEl);
    const dumpElement = document.getElementById('$dumpId');
    
    // Handle toggle clicks using event delegation
    dumpElement.addEventListener('click', function(e) {
      // Check if we clicked on a toggle or its child
      let toggle = e.target.closest('.dump-toggle');
      if (toggle) {
        e.preventDefault();
        e.stopPropagation();
        
        const content = toggle.nextElementSibling;
        const arrow = toggle.querySelector('.dump-arrow');
        const isCollapsed = toggle.classList.contains('dump-collapsed');
        
        if (isCollapsed) {
          toggle.classList.remove('dump-collapsed');
          if (content) {
            content.style.display = 'block';
            content.classList.add('dump-expanding');
            setTimeout(() => {
              if (content) content.classList.remove('dump-expanding');
            }, 300);
          }
          if (arrow) arrow.textContent = '▼';
        } else {
          toggle.classList.add('dump-collapsed');
          if (content) content.style.display = 'none';
          if (arrow) arrow.textContent = '▶';
        }
        return false;
      }
      
      // Handle expandable strings
      const expandable = e.target.closest('.dump-string.dump-expandable');
      if (expandable) {
        e.preventDefault();
        const preview = expandable.querySelector('.dump-string-preview');
        const full = expandable.querySelector('.dump-string-full');
        
        if (full && preview) {
          if (full.style.display === 'none') {
            preview.style.display = 'none';
            full.style.display = 'inline';
          } else {
            preview.style.display = 'inline';
            full.style.display = 'none';
          }
        }
        return false;
      }
    });
    
    // Keyboard navigation
    dumpElement.addEventListener('keydown', function(e) {
      const toggle = e.target;
      if (toggle.classList.contains('dump-toggle') && (e.key === 'Enter' || e.key === ' ')) {
        e.preventDefault();
        toggle.click();
      }
    });
    
    // Add tabindex to toggles for keyboard navigation
    dumpElement.querySelectorAll('.dump-toggle').forEach(toggle => {
      toggle.setAttribute('tabindex', '0');
    });
    
    // Global keyboard shortcuts for this dump
    const keyHandler = function(e) {
      if (e.altKey && e.shiftKey) {
        if (e.key === 'C') {
          // Collapse all in this dump
          dumpElement.querySelectorAll('.dump-toggle:not(.dump-collapsed)').forEach(toggle => {
            const content = toggle.nextElementSibling;
            const arrow = toggle.querySelector('.dump-arrow');
            toggle.classList.add('dump-collapsed');
            if (content) content.style.display = 'none';
            if (arrow) arrow.textContent = '▶';
          });
          e.preventDefault();
        } else if (e.key === 'E') {
          // Expand all in this dump
          dumpElement.querySelectorAll('.dump-toggle.dump-collapsed').forEach(toggle => {
            const content = toggle.nextElementSibling;
            const arrow = toggle.querySelector('.dump-arrow');
            toggle.classList.remove('dump-collapsed');
            if (content) content.style.display = 'block';
            if (arrow) arrow.textContent = '▼';
          });
          e.preventDefault();
        }
      }
    };
    
    // Store handler reference for cleanup
    dumpElement._keyHandler = keyHandler;
    document.addEventListener('keydown', keyHandler);
  }
  
  // Initialize immediately if DOM is ready, otherwise wait
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDump);
  } else {
    initDump();
  }
})();
</script>
SCRIPT;
  }
  
  /**
   * Get a tracked download URL for an asset
   * 
   * @param string $uuid The UUID of the asset
   * @param array $options Configuration options:
   *   - inline: bool - Whether to display inline rather than download
   *   - filename: string - Custom filename for the download
   * @return string URL for tracked download
   */
  public static function getTrackedDownloadUrl($uuid, $options = []): string
  {
    if (empty($uuid)) {
      return '';
    }
    
    $params = ['uuid' => $uuid];
    
    if (!empty($options['inline'])) {
      $params['inline'] = 1;
    }
    
    return Yii::$app->urlManager->createUrl(array_merge(['/crelish/asset/download'], $params));
  }
  
  /**
   * Generate responsive image HTML using asset UUID
   *
   * @param string $uuid The UUID of the asset
   * @param array $options Configuration options (see method documentation)
   * @return string HTML for responsive image
   */
  public static function responsiveImage($uuid, $options = []): string
  {
    // Try to get the asset
    $asset = Asset::findOne($uuid);
    if (!$asset) {
      return '';
    }

    // Determine alt text from asset data
    $altText = $asset->description ?: pathinfo($asset->fileName, PATHINFO_FILENAME);

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

    // Check if asset is an SVG - SVGs don't need responsive processing
    $extension = strtolower(pathinfo($asset->fileName, PATHINFO_EXTENSION));
    $isSvg = $extension === 'svg' || ($asset->type ?? '') === 'image/svg+xml';

    if ($isSvg) {
      return self::buildSvgImageHtml($asset, $options);
    }

    // Configure presets for common use cases
    $presets = self::getImagePresets();

    // Apply preset if specified and exists
    if (isset($presets[$options['preset']])) {
      $options = array_merge($options, $presets[$options['preset']]);
    }

    // Calculate dimensions for aspect ratio if needed
    $options = self::calculateImageDimensions($options, $asset);

    // Generate responsive image HTML
    return self::buildResponsiveImageHtml($asset, $options);
  }

  /**
   * Build simple image HTML for SVG files
   *
   * SVGs are vector graphics and don't need responsive image processing.
   * They scale infinitely without quality loss.
   *
   * @param Asset $asset Asset model
   * @param array $options Image options
   * @return string HTML string
   */
  private static function buildSvgImageHtml(Asset $asset, array $options): string
  {
    $imagePath = self::getAssetUrl($asset->pathName, $asset->fileName);

    $attrs = [
      'src' => $imagePath,
      'alt' => htmlspecialchars($options['alt'], ENT_QUOTES, 'UTF-8'),
      'class' => $options['class'],
      'loading' => $options['loading'],
    ];

    // Add width/height if specified (helps prevent layout shift)
    if (!empty($options['width'])) {
      $attrs['width'] = $options['width'];
    }
    if (!empty($options['height'])) {
      $attrs['height'] = $options['height'];
    }

    // Build HTML
    $html = '<img';
    foreach ($attrs as $name => $value) {
      if ($value !== null && $value !== '') {
        $html .= ' ' . $name . '="' . $value . '"';
      }
    }
    $html .= '>';

    return $html;
  }

  /**
   * Calculate WCAG 2.1 contrast ratio between two colors
   * 
   * @param string $color1 Hex color code (e.g. "#FF9E1B")
   * @param string $color2 Hex color code (e.g. "#FFFFFF")
   * @return float Contrast ratio (1-21)
   */
  public static function calculateContrastRatio($color1, $color2): float
  {
    // Convert hex colors to RGB
    $rgb1 = self::hexToRgb($color1);
    $rgb2 = self::hexToRgb($color2);

    // Calculate luminance values
    $luminance1 = self::calculateLuminance($rgb1);
    $luminance2 = self::calculateLuminance($rgb2);

    // Calculate contrast ratio
    $lighter = max($luminance1, $luminance2);
    $darker = min($luminance1, $luminance2);

    return ($lighter + 0.05) / ($darker + 0.05);
  }

  /**
   * Convert hex color to RGB array
   * 
   * @param string $hex Hex color code
   * @return array RGB values
   */
  public static function hexToRgb($hex): array
  {
    $hex = ltrim($hex, '#');
    
    // Expand shorthand form
    if (strlen($hex) === 3) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    return [
      'r' => hexdec(substr($hex, 0, 2)),
      'g' => hexdec(substr($hex, 2, 2)),
      'b' => hexdec(substr($hex, 4, 2))
    ];
  }

  /**
   * Calculate relative luminance using WCAG formula
   * 
   * @param array $rgb RGB color values
   * @return float Relative luminance
   */
  public static function calculateLuminance($rgb): float
  {
    $components = [];
    
    foreach (['r', 'g', 'b'] as $key) {
      $c = $rgb[$key] / 255;
      $components[$key] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    }

    return 0.2126 * $components['r'] + 0.7152 * $components['g'] + 0.0722 * $components['b'];
  }

  /**
   * Adjust color to meet minimum contrast requirements
   * 
   * @param string $textColor Text color hex code
   * @param string $backgroundColor Background color hex code
   * @param float $minContrast Minimum contrast ratio (default 4.5 for WCAG AA)
   * @return string Adjusted color hex code
   */
  public static function adjustColorForContrast($textColor, $backgroundColor = '#FFFFFF', $minContrast = 4.5): string
  {
    $currentContrast = self::calculateContrastRatio($textColor, $backgroundColor);
    
    if ($currentContrast >= $minContrast) {
      return $textColor;
    }
    
    $adjustStep = 0.05; // 5% adjustment per step
    $maxAttempts = 20;  // Prevent infinite loops
    $attempts = 0;

    // Determine if we should darken or lighten based on background
    $bgLuminance = self::calculateLuminance(self::hexToRgb($backgroundColor));
    $shouldDarken = $bgLuminance > 0.5;

    while ($currentContrast < $minContrast && $attempts < $maxAttempts) {
      $textColor = $shouldDarken 
        ? self::darkenColor($textColor, $adjustStep)
        : self::lightenColor($textColor, $adjustStep);
        
      $currentContrast = self::calculateContrastRatio($textColor, $backgroundColor);
      $attempts++;
    }

    return $textColor;
  }
  
  /**
   * Extract language code from full locale string
   * 
   * @param string $language Full language string (e.g., 'de-DE')
   * @return string Short language code (e.g., 'de')
   */
  private static function extractLanguageCode($language): string
  {
    if (isset(self::$langCodeCache[$language])) {
      return self::$langCodeCache[$language];
    }
    
    $langCode = $language;
    if (preg_match('/([a-z]{2})-[A-Z]{2}/', $language, $matches)) {
      $langCode = $matches[1];
    }
    
    self::$langCodeCache[$language] = $langCode;
    return $langCode;
  }
  
  /**
   * Get or create Slugify instance
   * 
   * @return Slugify
   */
  private static function getSlugify(): Slugify
  {
    if (self::$slugify === null) {
      self::$slugify = new Slugify();
    }
    return self::$slugify;
  }
  
  /**
   * Get image presets configuration
   * 
   * @return array Preset configurations
   */
  private static function getImagePresets(): array
  {
    return [
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
  }
  
  /**
   * Calculate image dimensions maintaining aspect ratio
   * 
   * @param array $options Image options
   * @param Asset $asset Asset model
   * @return array Updated options with calculated dimensions
   */
  private static function calculateImageDimensions(array $options, Asset $asset): array
  {
    $originalWidth = $asset->width ?? 0;
    $originalHeight = $asset->height ?? 0;
    
    if ($originalWidth > 0 && $originalHeight > 0) {
      $aspectRatio = $originalWidth / $originalHeight;
      
      // Calculate missing dimension
      if ($options['width'] && !$options['height']) {
        $options['height'] = round($options['width'] / $aspectRatio, 0, PHP_ROUND_HALF_UP);
      } elseif ($options['height'] && !$options['width']) {
        $options['width'] = round($options['height'] * $aspectRatio, 0, PHP_ROUND_HALF_UP);
      }
    }
    
    return $options;
  }
  
  /**
   * Build responsive image HTML
   * 
   * @param Asset $asset Asset model
   * @param array $options Image options
   * @return string HTML string
   */
  private static function buildResponsiveImageHtml(Asset $asset, array $options): string
  {
    // Path to the original image
    $imagePath = self::getAssetUrl($asset->pathName, $asset->fileName);
    
    // Base URL with format and quality
    $baseUrl = '/crelish/asset/glide?path=' . ltrim($imagePath, '/') . 
               '&q=' . $options['quality'] . 
               '&fm=' . $options['format'];
    
    // Generate srcset
    $srcset = [];
    foreach ($options['widths'] as $width) {
      $srcset[] = $baseUrl . '&w=' . $width . ' ' . $width . 'w';
    }
    
    // Build HTML attributes
    $attrs = [
      'src' => $baseUrl . '&w=' . max($options['widths']),
      'alt' => htmlspecialchars($options['alt'], ENT_QUOTES, 'UTF-8'),
      'class' => $options['class'],
      'loading' => $options['loading'],
      'srcset' => implode(', ', $srcset),
      'sizes' => $options['sizes'],
      'width' => $options['width'],
      'height' => $options['height']
    ];
    
    // Build HTML
    $html = '<img';
    foreach ($attrs as $name => $value) {
      if ($value !== null && $value !== '') {
        $html .= ' ' . $name . '="' . $value . '"';
      }
    }
    $html .= '>';
    
    return $html;
  }
}