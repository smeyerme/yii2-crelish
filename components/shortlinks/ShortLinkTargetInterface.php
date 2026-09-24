<?php

namespace giantbits\crelish\components\shortlinks;

/**
 * Implement on a content model whose detail URL does not follow the
 * urlFromSlug('<listing>')/<uuid>/<slug> convention.
 */
interface ShortLinkTargetInterface
{
  /**
   * @param string|null $language two-letter language code
   * @return string|null absolute or site-relative URL, null when not reachable
   */
  public function getShortLinkUrl(?string $language): ?string;
}
