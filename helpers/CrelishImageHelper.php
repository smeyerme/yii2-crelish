<?php

namespace giantbits\crelish\helpers;

use Yii;
use yii\base\Exception;
use yii\helpers\FileHelper;

class CrelishImageHelper
{
  /**
   * Gets image dimensions if file is a valid image
   *
   * @param string $filePath Full path to the file
   * @return array|false Returns array with dimensions or false if not an image
   * @throws Exception If file doesn't exist or can't be read
   */
  public static function getImageDimensions($filePath): bool|array
  {
    // Check if file exists and is readable
    if (!file_exists($filePath)) {
      throw new Exception("File does not exist: $filePath");
    }

    if (!is_readable($filePath)) {
      throw new Exception("File is not readable: $filePath");
    }

    // Get MIME type
    $mimeType = FileHelper::getMimeType($filePath);
    if (!$mimeType || !str_starts_with($mimeType, 'image/')) {
      return false;
    }

    // Get image info
    $imageInfo = getimagesize($filePath);
    if ($imageInfo === false) {
      return false;
    }

    return [
      'width' => $imageInfo[0],
      'height' => $imageInfo[1],
      'mime' => $imageInfo['mime'],
      'aspectRatio' => $imageInfo[0] / $imageInfo[1],
    ];
  }

  /**
   * Checks if file is a valid image
   *
   * @param string $filePath Full path to the file
   * @return bool
   */
  public static function isImage($filePath): bool
  {
    try {
      return self::getImageDimensions($filePath) !== false;
    } catch (Exception $e) {
      return false;
    }
  }
}