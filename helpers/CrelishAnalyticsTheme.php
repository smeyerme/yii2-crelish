<?php

namespace giantbits\crelish\helpers;

use yii\web\View;

/**
 * Dark mode for the analytics screens.
 *
 * Crelish switches themes with html[data-theme] (see the layout and crelish-modern.js).
 * The cards and tables follow through the .crelish-bs-dark class in crelish-modern.css;
 * the charts follow through Chart.js defaults, set here.
 */
class CrelishAnalyticsTheme
{
  public const CHART_JS = 'https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js';

  /**
   * Loads Chart.js in the head and sets its default label and grid colours from the
   * admin theme. Charts drawn after a theme switch use the new colours.
   */
  public static function registerChartJs(View $view): void
  {
    $view->registerJsFile(self::CHART_JS, ['position' => View::POS_HEAD]);
    $view->registerJs(<<<JS
(function () {
    function apply() {
        if (!window.Chart) { return; }
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        Chart.defaults.color = dark ? '#bac6d6' : '#666';
        Chart.defaults.borderColor = dark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
    }
    apply();
    new MutationObserver(apply).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();
JS, View::POS_HEAD, 'crelish-analytics-chart-theme');
  }
}
