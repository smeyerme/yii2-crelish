<?php

/**
 * Browser confirmation, web side: the switch, the route of the endpoint and
 * the script a page carries.
 *
 * Run with:  php tests/PageStateTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\Analytics\BrowserConfirmation;
use giantbits\crelish\components\Analytics\PageStateUrlRule;

function on(array $settings = []): array
{
    return ['analytics' => ['browserConfirmation' => array_merge(['enabled' => true], $settings)]];
}

function route(string $path, array $params, string $method = 'POST'): array|false
{
    shortLinkApp($params, ['REQUEST_URI' => $path, 'REQUEST_METHOD' => $method]);

    return (new PageStateUrlRule())->parseRequest(Yii::$app->urlManager, Yii::$app->request);
}

$route = PageStateUrlRule::ROUTE;

echo "The switch\n";
shortLinkApp();
check('is off unless a site turns it on', false, BrowserConfirmation::isEnabled());
check('an off site puts no script into its pages', null, BrowserConfirmation::script(17));
shortLinkApp(on());
check('is on when configured', true, BrowserConfirmation::isEnabled());
check('the endpoint has a neutral default name', 'page/state', BrowserConfirmation::path());
shortLinkApp(on(['path' => '/site/hello/']));
check('a site can rename it', 'site/hello', BrowserConfirmation::path());

echo "\nThe route\n";
check('the endpoint is ours', [$route, []], route('/page/state', on()));
check('with a trailing slash too', [$route, []], route('/page/state/', on()));
check('not when the site has it off', false, route('/page/state', []));
check('pages are not ours', false, route('/de/news', on()));
check('nor longer paths', false, route('/page/state/x', on()));
check('nor a language-prefixed one', false, route('/de/page/state', on()));
check('a renamed endpoint is ours', [$route, []], route('/site/hello', on(['path' => 'site/hello'])));
check('and the default no longer is', false, route('/page/state', on(['path' => 'site/hello'])));

$catchAll = ['components' => ['urlManager' => ['rules' => ['<slug:.+>' => 'site/page']]]];
shortLinkApp(on(), ['REQUEST_URI' => '/page/state', 'REQUEST_METHOD' => 'POST'], $catchAll);
PageStateUrlRule::register(Yii::$app->urlManager);
check('registered, it wins over a catch-all rule', $route, Yii::$app->urlManager->parseRequest(Yii::$app->request)[0]);
shortLinkApp([], ['REQUEST_URI' => '/page/state', 'REQUEST_METHOD' => 'POST'], $catchAll);
PageStateUrlRule::register(Yii::$app->urlManager);
check('an off site keeps its own routing', 'site/page', Yii::$app->urlManager->parseRequest(Yii::$app->request)[0]);

echo "\nThe script\n";
shortLinkApp(on());
$script = (string)BrowserConfirmation::script(4711);
check('posts to the endpoint', true, str_contains($script, '"/page/state"') && str_contains($script, '"POST"'));
check('carries the page view', true, str_contains($script, '"v=4711"'));
check('names nothing a blocker list would match', 0, preg_match('/analytic|track|beacon|collect|pixel|stat[^e]/i', $script));
check('a page view that was not recorded gets no script', null, BrowserConfirmation::script(null));
check('nor one without an id', null, BrowserConfirmation::script(0));
shortLinkApp(on(['path' => 'site/hello']));
check('a renamed endpoint is used', true, str_contains((string)BrowserConfirmation::script(1), '"/site/hello"'));

shortLinkDone();
