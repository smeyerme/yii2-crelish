<?php

/**
 * The analytics screens follow the admin's dark mode. Crelish switches themes with
 * html[data-theme="dark"] and its own colour variables (crelish-modern.css). The screens
 * are built from Bootstrap cards and tables, and their dark rules were written for
 * html[data-bs-theme="dark"], which crelish never sets: in dark mode they stayed white
 * with dark text on a dark page.
 *
 * Checked on the source, since the look itself needs a browser.
 *
 * Run with:  php tests/AnalyticsDarkModeTest.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$read = fn(string $path) => (string)@file_get_contents($root . '/' . $path);

$failures = 0;
$passes = 0;
function check(bool $cond, string $label): void
{
  global $failures, $passes;
  if ($cond) {
    $passes++;
    echo "  ok   $label\n";
  } else {
    $failures++;
    echo "  FAIL $label\n";
  }
}

// One shared class maps Bootstrap's colours to crelish's in dark mode
$css = $read('assets/css/crelish-modern.css');
check(str_contains($css, '[data-theme="dark"] .crelish-bs-dark'), 'shared dark mapping in crelish-modern.css');
foreach (['--bs-body-bg: var(--color-bg-main)', '--bs-body-color: var(--color-text-dark)', '--bs-secondary-color: var(--color-text-muted)', '--bs-border-color: var(--color-border)', '--bs-tertiary-bg: var(--color-bg-light)'] as $mapping) {
  check(str_contains($css, $mapping), "maps $mapping");
}

// Every analytics screen wears it on its outermost element
$views = [
  'views/analytics/dashboard.twig',
  'views/analytics/sessions.twig',
  'views/analytics/index.php',
  'views/analytics-aggregated/index.twig',
  'views/analytics-aggregated/page-performance.twig',
  'views/analytics-aggregated/element-performance.twig',
  'views/company-analytics/index.twig',
];
foreach ($views as $view) {
  $source = $read($view);
  preg_match('/<div class="([^"]*)"/', $source, $m);
  check(in_array('crelish-bs-dark', explode(' ', $m[1] ?? ''), true), "$view: outer element uses crelish-bs-dark");
  check(!preg_match('/\bbg-light\b/', $source), "$view: no fixed light backgrounds");
}

// Dark rules key on crelish's attribute; charts get readable defaults
$controllers = ['AnalyticsController', 'AnalyticsAggregatedController', 'CompanyAnalyticsController'];
foreach ($controllers as $controller) {
  $source = $read("controllers/$controller.php");
  check(!str_contains($source, '[data-bs-theme'), "$controller: no data-bs-theme selectors");
  check(str_contains($source, 'CrelishAnalyticsTheme::registerChartJs($this->view)'), "$controller: Chart.js loaded with theme defaults");
  check(!str_contains($source, 'chart.min.js'), "$controller: Chart.js not loaded on its own");
}

$helper = $read('helpers/CrelishAnalyticsTheme.php');
check(str_contains($helper, "getAttribute('data-theme')") && str_contains($helper, 'Chart.defaults.color') && str_contains($helper, 'MutationObserver'), 'chart defaults follow data-theme, also after a switch');

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
