<?php

namespace giantbits\crelish\components;

/**
 * Implement on a content model whose frontend URL does not follow the
 * urlFromSlug('<listing>')/<uuid>/<slug> convention. Used by menus and
 * short links.
 */
interface UrlTargetInterface
{
  /**
   * @param string|null $language two-letter language code
   * @return string|null absolute or site-relative URL, null when not reachable
   */
  public function getTargetUrl(?string $language): ?string;
}
