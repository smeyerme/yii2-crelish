<?php

/**
 * Company analytics follows the admin's dark mode. Crelish switches themes with
 * html[data-theme="dark"] and its own colour variables; the dashboard is built from
 * Bootstrap cards and tables and styled for html[data-bs-theme="dark"], which crelish
 * never sets, so in dark mode it stayed white with dark text on a dark page.
 *
 * Checked on the source, since the look itself needs a browser.
 *
 * Run with:  php tests/CompanyAnalyticsDarkModeTest.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$view = file_get_contents($root . '/views/company-analytics/index.twig');
$controller = file_get_contents($root . '/controllers/CompanyAnalyticsController.php');
preg_match('/function getDashboardCss\(\).*?<<<CSS(.*?)\R\s*CSS;/s', $controller, $m);
$css = $m[1] ?? '';

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

check($css !== '', 'dashboard CSS found');
check(!str_contains($css, '[data-bs-theme'), 'CSS keys dark mode on crelish\'s data-theme, not data-bs-theme');
check(str_contains($css, '[data-theme="dark"] .company-analytics-dashboard'), 'dark rules scoped to the dashboard');
foreach (['--bs-body-bg: var(--color-bg-main)', '--bs-body-color: var(--color-text-dark)', '--bs-secondary-color: var(--color-text-muted)', '--bs-border-color: var(--color-border)'] as $mapping) {
  check(str_contains($css, $mapping), "Bootstrap colour mapped to crelish's: $mapping");
}
check(!preg_match('/\bbg-light\b/', $view), 'no fixed light card headers');
check(str_contains($view, "getAttribute('data-theme')") && str_contains($view, "'data-bs-theme'"), 'dashboard mirrors data-theme to data-bs-theme');
check(str_contains($view, 'MutationObserver'), 'and follows a theme switch on the page');
check(str_contains($view, 'Chart.defaults.color'), 'chart labels follow the theme');

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
