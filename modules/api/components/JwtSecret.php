<?php

namespace giantbits\crelish\modules\api\components;

use Yii;

/**
 * The JWT signing key: params['jwtSecretKey'].
 *
 * JWT authentication (issuing and accepting tokens) is disabled unless the key
 * is a string of at least MIN_LENGTH characters and not one of the
 * placeholders that shipped with Crelish; a known or guessable key would let
 * anyone forge tokens.
 */
final class JwtSecret
{
  public const MIN_LENGTH = 32;

  private const PLACEHOLDERS = [
    'your-secret-key-here',
    'your-secret-key-change-this-in-production',
  ];

  public static function isEnabled(): bool
  {
    return self::key() !== null;
  }

  public static function key(): ?string
  {
    $key = Yii::$app->params['jwtSecretKey'] ?? null;

    if (!is_string($key) || strlen($key) < self::MIN_LENGTH || in_array($key, self::PLACEHOLDERS, true)) {
      return null;
    }

    return $key;
  }

  /**
   * The key for JWT::encode()/decode().
   *
   * @throws \RuntimeException when JWT authentication is disabled (callers already catch \Exception)
   */
  public static function requireKey(): string
  {
    return self::key() ?? throw new \RuntimeException('JWT authentication is disabled: params[jwtSecretKey] must be a non-default secret of at least ' . self::MIN_LENGTH . ' characters.');
  }
}
