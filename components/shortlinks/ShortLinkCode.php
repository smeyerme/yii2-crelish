<?php

namespace giantbits\crelish\components\shortlinks;

use RuntimeException;

/**
 * Generates and checks short link codes.
 *
 * The alphabet leaves out characters that are easily confused when a code is
 * typed from paper (0/o, 1/l/i).
 */
final class ShortLinkCode
{
  public const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';
  public const LENGTH = 6;
  public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

  public static function normalize(?string $code): string
  {
    return strtolower(trim((string)$code));
  }

  public static function random(): string
  {
    $max = strlen(self::ALPHABET) - 1;
    $code = '';

    for ($i = 0; $i < self::LENGTH; $i++) {
      $code .= self::ALPHABET[random_int(0, $max)];
    }

    return $code;
  }

  /**
   * @param callable(string): bool $exists returns true when a code is already taken
   */
  public static function generate(callable $exists, int $maxAttempts = 20): string
  {
    $reserved = ShortLinkConfig::reservedCodes();

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
      $code = self::random();

      if (!in_array($code, $reserved, true) && !$exists($code)) {
        return $code;
      }
    }

    throw new RuntimeException('Could not generate a unique short link code.');
  }

  /**
   * @return string|null an error message, or null when the (normalised) code is acceptable
   */
  public static function formatError(string $code): ?string
  {
    if (strlen($code) < 2 || !preg_match(self::PATTERN, $code)) {
      return 'Use 2 to 64 characters: a-z, 0-9 and hyphens, not at the start or end.';
    }

    if (in_array($code, ShortLinkConfig::reservedCodes(), true)) {
      return 'This code is reserved.';
    }

    return null;
  }
}
