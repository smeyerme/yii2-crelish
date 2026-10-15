<?php

/**
 * A two-letter path segment is a language only when the project offers it. Scanner
 * requests like /wp set Yii::$app->language to "wp"; the 404 page was then rendered in
 * "wp", so every text went to DeepL (target_lang not supported) and Yii looked for a
 * "wp" message file.
 *
 * Run with:  php tests/UrlRuleLanguageTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishBaseUrlRule;

function urlRuleLanguageTestParse(string $path, array $languages): array
{
    $app = new \yii\web\Application([
        'id' => 'url-rule-language-test',
        'basePath' => dirname(__DIR__),
        'language' => 'de-CH',
        'components' => [
            'request' => ['cookieValidationKey' => 'test', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php'],
        ],
        'params' => ['crelish' => ['langprefix' => true, 'languages' => $languages, 'entryPoint' => ['slug' => 'home']]],
    ]);
    $app->request->setPathInfo($path);

    [, $params] = (new CrelishBaseUrlRule())->parseRequest($app->urlManager, $app->request);
    return [$app->language, $params['language']];
}

echo "\nlanguages the project offers\n";
check('/en/kontakt switches to en', ['en', 'en'], urlRuleLanguageTestParse('en/kontakt', ['de', 'en']));
check('/en switches to en', ['en', 'en'], urlRuleLanguageTestParse('en', ['de', 'en']));

echo "\nany other two-letter segment\n";
check('/wp keeps the default language', ['de-CH', 'wp'], urlRuleLanguageTestParse('wp', ['de', 'en']));
check('/wp/wp-login.php keeps the default language', ['de-CH', 'wp'], urlRuleLanguageTestParse('wp/wp-login.php', ['de', 'en']));

echo "\nno languages configured\n";
check('/fr is taken as before', ['fr', 'fr'], urlRuleLanguageTestParse('fr', []));

shortLinkDone();
