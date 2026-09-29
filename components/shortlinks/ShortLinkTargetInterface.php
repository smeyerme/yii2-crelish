<?php

namespace giantbits\crelish\components\shortlinks;

/**
 * @deprecated since 0.23.0, implement {@see \giantbits\crelish\components\UrlTargetInterface} instead.
 * Still honoured by ContentUrlResolver after UrlTargetInterface.
 */
interface ShortLinkTargetInterface
{
  /**
   * @param string|null $language two-letter language code
   * @return string|null absolute or site-relative URL, null when not reachable
   */
  public function getShortLinkUrl(?string $language): ?string;
}
