<?php

namespace giantbits\crelish\plugins\checkboxlist;

use yii\base\Component;
use yii\helpers\Json;

/**
 * Content processor for checkboxList fields.
 *
 * Symmetric counterpart to CrelishDbStorage's save-side JSON encoding:
 * a checkboxList submission is Json::encode()d to a string for DB
 * storage. Without this processor's processJson() decode hook, that
 * string flows back unchanged into the form widget on reload, and
 * Yii's Html::checkboxList can't match any item key against the raw
 * JSON string — leaving every option unchecked even though the data
 * is correct in the DB.
 */
class CheckboxListContentProcessor extends Component
{
  public static function processData($key, $value, &$processedData): void
  {
    $processedData[$key] = self::toArray($value);
  }

  public static function processJson($ctype, $key, $value, &$finalArr): void
  {
    $finalArr[$key] = self::toArray($value);
  }

  private static function toArray($value): array
  {
    if (is_array($value)) {
      return $value;
    }
    if ($value === null || $value === '') {
      return [];
    }
    if (is_string($value)) {
      $trimmed = trim($value);
      if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
        try {
          $decoded = Json::decode($trimmed);
          if (is_array($decoded)) {
            return $decoded;
          }
        } catch (\Throwable $e) {
          // Fall through — corrupt JSON, treat as scalar selection.
        }
      }
      // Single-key scalar selection (legacy data or non-JSON write path).
      return [$value];
    }
    return [];
  }
}
