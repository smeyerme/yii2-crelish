<?php

namespace giantbits\crelish\components;

use app\workspace\models\Asset;
use Cocur\Slugify\Slugify;
use MatthiasMullie\Minify\CSS;
use Yii;
use yii\helpers\Url;
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
        $options['height'] = round($options['width'] / $aspectRatio);
      } elseif ($options['height'] && !$options['width']) {
        $options['width'] = round($options['height'] * $aspectRatio);
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