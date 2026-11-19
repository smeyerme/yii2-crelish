<?php

/**
 * The Twig registerJs filter minifies in production; every snippet still ends a
 * statement, so snippets Yii joins into one block do not run into each other.
 *
 * Run with:  php tests/RegisterJsMinifyTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\extensions\RegisterJsExtension;

check('a snippet gets its semicolon', "$('#f').on('submit',x);", RegisterJsExtension::terminated("$('#f').on('submit',x)"));
check('one that has it keeps one', 'a();', RegisterJsExtension::terminated('a();'));
check('trailing space is dropped first', 'a();', RegisterJsExtension::terminated("a()\n  "));
check('an empty snippet stays empty', '', RegisterJsExtension::terminated(''));

echo "\nThrough the filter (production: minified)\n";
shortLinkApp([]);
$filter = new RegisterJsExtension();
$filter->registerJsFilter("$('#search-form').on('submit', function (e) {\n  e.preventDefault();\n  return false;\n});\n");
$filter->registerJsFilter("(function(){ window.second = 1; })();\n");
$js = Yii::$app->view->js[\yii\web\View::POS_END] ?? [];
$joined = implode("\n", $js);
check('the minifier ran', true, !str_contains($joined, "\n  e.preventDefault"));
check('the first snippet ends its statement before the second starts', true, (bool)preg_match('/\}\);\s*\(function\(\)/', $joined));

shortLinkDone();
