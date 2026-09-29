<?php

namespace giantbits\crelish\commands;

use giantbits\crelish\components\CrelishI18nEventHandler;
use giantbits\crelish\components\CrelishMessageSource;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Maintenance for crelish translations.
 *
 * Crelish's own translations are the base for the `crelish` category; the
 * project's messages/<lang>/crelish.php only needs project-specific overrides.
 * Older projects may still hold machine translations (written by the missing
 * translation handler) that shadow the curated package strings.
 */
class TranslationsController extends Controller
{
  /**
   * @var bool actually remove the shadowing keys (default: dry run)
   */
  public $apply = false;

  /**
   * @var string|null crelish's own messages directory; defaults to the configured message source
   */
  public $packageBasePath = null;

  /**
   * @var string|null project messages directory; defaults to the configured message source (@app/messages)
   */
  public $messagesPath = null;

  public function options($actionID): array
  {
    return array_merge(parent::options($actionID), ['apply']);
  }

  /**
   * Remove project translations that shadow crelish's own translations.
   *
   * Dry run by default; pass --apply to rewrite the project file.
   *
   * @param string $language language code, e.g. de
   * @return int
   */
  public function actionPrune(string $language = 'de'): int
  {
    $source = Yii::$app->i18n->translations['crelish*'] ?? null;
    $sourceConfig = [];
    if ($source instanceof CrelishMessageSource) {
      $sourceConfig = ['packageBasePath' => $source->packageBasePath, 'basePath' => $source->basePath];
    } elseif (is_array($source)) {
      $sourceConfig = $source;
    }
    $packageBase = $this->packageBasePath
      ?? $sourceConfig['packageBasePath']
      ?? (new CrelishMessageSource())->packageBasePath;
    $projectBase = $this->messagesPath ?? $sourceConfig['basePath'] ?? '@app/messages';

    $packageFile = Yii::getAlias($packageBase) . "/$language/crelish.php";
    $projectFile = Yii::getAlias($projectBase) . "/$language/crelish.php";

    if (!is_file($projectFile)) {
      $this->stdout("No project translation file for '$language' ($projectFile). Nothing to do.\n");
      return ExitCode::OK;
    }

    $package = is_file($packageFile) ? (array)include $packageFile : [];
    $project = (array)include $projectFile;

    $shadowing = [];
    foreach ($project as $key => $value) {
      $packageValue = $package[$key] ?? null;
      if ($packageValue !== null && $packageValue !== '') {
        $shadowing[$key] = $packageValue;
      }
    }

    foreach ($shadowing as $key => $packageValue) {
      $this->stdout("$key: " . (string)$project[$key] . " → $packageValue\n");
    }

    $count = count($shadowing);
    $this->stdout("$count project key(s) also defined by crelish for '$language'.\n");

    if ($count === 0) {
      return ExitCode::OK;
    }

    if (!$this->apply) {
      $this->stdout("Dry run, nothing changed. Re-run with --apply to remove them from $projectFile.\n");
      return ExitCode::OK;
    }

    $remaining = array_diff_key($project, $shadowing);
    CrelishI18nEventHandler::writeTranslationFile($projectFile, $remaining);
    $this->stdout("Removed $count key(s) from $projectFile.\n");

    return ExitCode::OK;
  }
}
