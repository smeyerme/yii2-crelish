<?php

namespace giantbits\crelish\commands;

use giantbits\crelish\components\SubPaths;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Helps to fill the "subPaths" setting: which pages have pages below them.
 */
class SubPathsController extends Controller
{
  /**
   * Lists the pages that were called with something after their name in the stored
   * visits, with how many of those views came from visitors and from real browsers.
   * Pages that show up with visitors are the candidates for the setting; pages with bot
   * views only are what the setting will turn into "not found".
   *
   *   php yii crelish/sub-paths/report
   */
  public function actionReport(): int
  {
    $db = Yii::$app->db;
    $table = $db->schema->getTableSchema('analytics_page_views');
    if ($table === null) {
      $this->stderr("No analytics_page_views table: nothing to report from.\n");

      return ExitCode::UNSPECIFIED_ERROR;
    }

    $confirmed = isset($table->columns['confirmed_at']) ? 'SUM(confirmed_at IS NOT NULL)' : '0';
    // Up to three parts of the path are enough to tell a page from an address below it
    $rows = $db->createCommand(
      "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(url, '?', 1), '/', 6) AS address,
              COUNT(*) AS views, SUM(is_bot = 0) AS visitors, $confirmed AS confirmed
       FROM analytics_page_views GROUP BY address"
    )->queryAll();

    $languagePrefix = !empty(Yii::$app->params['crelish']['langprefix']);
    $pages = [];
    foreach ($rows as $row) {
      $page = SubPaths::pageOf((string)$row['address'], $languagePrefix);
      if ($page === null || !$page['below']) {
        continue;
      }
      $pages[$page['page']] ??= ['views' => 0, 'visitors' => 0, 'confirmed' => 0];
      foreach (['views', 'visitors', 'confirmed'] as $figure) {
        $pages[$page['page']][$figure] += (int)$row[$figure];
      }
    }
    uasort($pages, static fn(array $a, array $b) => [$b['visitors'], $b['views']] <=> [$a['visitors'], $a['views']]);

    $current = SubPaths::configured(Yii::$app->params['crelish']['subPaths'] ?? null);
    $this->stdout('subPaths setting: ' . ($current === null ? 'not set (every page accepts anything after its name)' : "['" . implode("', '", $current) . "']") . "\n\n");
    $this->stdout(sprintf("%-40s %10s %10s %10s  %s\n", 'page', 'views', 'visitors', 'browsers', 'in setting'));

    foreach (array_slice($pages, 0, 60, true) as $name => $figures) {
      $this->stdout(sprintf("%-40s %10d %10d %10d  %s\n", mb_substr($name, 0, 40), $figures['views'], $figures['visitors'], $figures['confirmed'],
        $current !== null && in_array($name, $current, true) ? 'yes' : ''));
    }
    if (count($pages) > 60) {
      $this->stdout('... and ' . (count($pages) - 60) . " more\n");
    }

    $this->stdout("\n\"visitors\" are views not marked as bots; \"browsers\" are views a browser confirmed (where that is recorded).\n");

    return ExitCode::OK;
  }
}
