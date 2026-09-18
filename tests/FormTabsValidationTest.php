<?php

/**
 * The client-side half of the tabbed form's error handling.
 *
 * The server already opens the first tab carrying an error — but only when the
 * request reaches it. Yii validates in the browser first, so a required field
 * left empty on a hidden pane blocks the submit without a page load: the error
 * sits on a pane nobody can see, the first tab stays active, and the form
 * appears to ignore the save button. This script is what closes that gap.
 *
 * Run with:  php tests/FormTabsValidationTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use giantbits\crelish\components\CrelishFormTabs;

$failures = 0;

function check(string $what, bool $ok): void
{
  global $failures;
  echo($ok ? "  ok    " : "  FAIL  "), $what, "\n";
  if (!$ok) {
    $failures++;
  }
}

$script = CrelishFormTabs::validationScript('crelish-content-form');

echo "validationScript()\n";
check('bindet Yiis afterValidate', str_contains($script, 'afterValidate'));
check('findet das Formular über seine id', str_contains($script, '"crelish-content-form"'));
check('sucht Fehler in den Panes', str_contains($script, 'has-error'));
check('erkennt auch die Bootstrap-5-Klasse', str_contains($script, 'is-invalid'));
check('spricht die Tabs über data-crelish-tab an', str_contains($script, 'data-crelish-tab'));
check('steigt ohne jQuery aus statt zu werfen', str_contains($script, 'window.jQuery'));

// Eine Formular-id kommt aus den Settings des Aufrufers. Wird sie roh
// interpoliert, kann ein Apostroph das Skript beenden — deshalb JSON-kodiert.
$evil = CrelishFormTabs::validationScript("evil');alert(1);//");
echo "\nEscaping\n";
check('bricht nicht aus dem String aus', !str_contains($evil, "evil');alert(1);//"));
check('kodiert die id als JSON', str_contains($evil, json_encode("evil');alert(1);//")));

// Das Skript läuft auf jeder getabbten Seite; ein Syntaxfehler legte das
// ganze Backend-Formular lahm.
echo "\nSyntax\n";
$tmp = sys_get_temp_dir() . '/crelish-tabs-validation.js';
file_put_contents($tmp, $script);
$node = trim((string)shell_exec('command -v node'));
if ($node === '') {
  echo "  SKIP  node nicht verfügbar, Syntaxprüfung ausgelassen\n";
} else {
  exec(escapeshellcmd($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
  check('ist gültiges JavaScript', $code === 0);
  if ($code !== 0) {
    echo '        ', implode("\n        ", $out), "\n";
  }
}
@unlink($tmp);

echo "\n", $failures === 0 ? "alle Prüfungen bestanden\n" : "$failures Prüfung(en) fehlgeschlagen\n";
exit($failures === 0 ? 0 : 1);
