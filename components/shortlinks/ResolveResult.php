<?php

namespace giantbits\crelish\components\shortlinks;

/**
 * Where a short link hit goes, and why.
 */
final class ResolveResult
{
  public const REASON_OK = 'ok';
  public const REASON_INACTIVE = 'inactive';
  public const REASON_BROKEN = 'broken';

  public function __construct(
    public readonly string $url,
    public readonly string $reason,
  ) {
  }

  public function isFallback(): bool
  {
    return $this->reason !== self::REASON_OK;
  }
}
