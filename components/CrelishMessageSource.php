<?php

namespace giantbits\crelish\components;

use yii\i18n\PhpMessageSource;

/**
 * Message source for the `crelish*` category.
 *
 * Crelish ships curated translations in its own messages directory. Those are
 * the BASE; the project's messages (basePath, usually @app/messages) are merged
 * on top so projects can override single strings. Project entries with an
 * empty value ('' or null) never shadow the package value.
 *
 * Language fallback (e.g. de-CH -> de) is handled by PhpMessageSource itself:
 * the package files are read through a second PhpMessageSource instance that
 * points at $packageBasePath, so both sources resolve languages identically.
 */
class CrelishMessageSource extends PhpMessageSource
{
  /**
   * @var string directory holding crelish's own <lang>/<category>.php files
   */
  public $packageBasePath = '@giantbits/crelish/messages';

  private ?PhpMessageSource $packageSource = null;

  protected function loadMessages($category, $language)
  {
    $project = parent::loadMessages($category, $language);
    $package = $this->getPackageSource()->loadPackageMessages($category, $language);

    foreach ($project as $key => $value) {
      if ($value !== '' && $value !== null) {
        $package[$key] = $value;
      } elseif (!array_key_exists($key, $package)) {
        $package[$key] = $value;
      }
    }

    return $package;
  }

  private function getPackageSource(): CrelishPackageMessageSource
  {
    if ($this->packageSource === null) {
      $this->packageSource = new CrelishPackageMessageSource([
        'basePath' => $this->packageBasePath,
        'sourceLanguage' => $this->sourceLanguage,
        'fileMap' => $this->fileMap,
      ]);
    }

    return $this->packageSource;
  }
}

/**
 * Plain PhpMessageSource that exposes its (protected) loader, including
 * Yii's language fallback, to CrelishMessageSource.
 *
 * @internal
 */
class CrelishPackageMessageSource extends PhpMessageSource
{
  public function loadPackageMessages(string $category, string $language): array
  {
    return $this->loadMessages($category, $language);
  }
}
