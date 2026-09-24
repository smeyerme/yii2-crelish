<?php

/**
 * Short link URL parsing: prefix route, scan marker, case, short host,
 * and that the rule is registered ahead of catch-all page rules.
 *
 * Run with:  php tests/ShortLinkUrlRuleTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\shortlinks\ShortLinkUrlRule;

function parse(string $path, string $host = 'forum-holzbau.test', array $params = []): array|false
{
    shortLinkApp($params, ['HTTP_HOST' => $host, 'SERVER_NAME' => $host, 'REQUEST_URI' => $path]);

    return (new ShortLinkUrlRule())->parseRequest(Yii::$app->urlManager, Yii::$app->request);
}

$route = ShortLinkUrlRule::ROUTE;
$home = ShortLinkUrlRule::HOME_ROUTE;

echo "Prefix route\n";
check('/go/ihf26 is a click', [$route, ['code' => 'ihf26', 'scan' => 0]], parse('/go/ihf26'));
check('/go/ihf26/q is a scan', [$route, ['code' => 'ihf26', 'scan' => 1]], parse('/go/ihf26/q'));
check('uppercase scan URL', [$route, ['code' => 'ihf26', 'scan' => 1]], parse('/GO/IHF26/Q'));
check('uppercase scan URL with trailing slash', [$route, ['code' => 'ihf26', 'scan' => 1]], parse('/GO/IHF26/Q/'));
check('trailing slash on a click', [$route, ['code' => 'ihf26', 'scan' => 0]], parse('/go/ihf26/'));
check('prefix alone is not ours', false, parse('/go'));
check('prefix with slash is not ours', false, parse('/go/'));
check('one-character code is not ours', false, parse('/go/a'));
check('unknown suffix is not ours', false, parse('/go/ihf26/x'));
check('underscore is not ours', false, parse('/go/ihf_26'));
check('pages are not ours', false, parse('/de/news'));
check('prefix must be a whole segment', false, parse('/gold/abc'));

echo "\nCustom prefix\n";
check('custom prefix matches', [$route, ['code' => 'abc', 'scan' => 0]], parse('/kampagne/abc', params: ['shortLinks' => ['prefix' => 'kampagne']]));
check('default prefix no longer matches', false, parse('/go/abc', params: ['shortLinks' => ['prefix' => 'kampagne']]));

echo "\nShort host\n";
$short = ['shortLinks' => ['shortHost' => 'fhb.link']];
check('code on the short host', [$route, ['code' => 'ihf26', 'scan' => 0]], parse('/ihf26', 'fhb.link', $short));
check('scan on the short host', [$route, ['code' => 'ihf26', 'scan' => 1]], parse('/ihf26/q', 'fhb.link', $short));
check('uppercase short host', [$route, ['code' => 'ihf26', 'scan' => 1]], parse('/IHF26/Q', 'FHB.LINK', $short));
check('short host root goes home', [$home, []], parse('/', 'fhb.link', $short));
check('other short host paths go home', [$home, []], parse('/some/deep/path', 'fhb.link', $short));
check('main host still uses the prefix', [$route, ['code' => 'ihf26', 'scan' => 0]], parse('/go/ihf26', 'forum-holzbau.test', $short));

echo "\nRegistration\n";
$catchAll = ['components' => ['urlManager' => ['rules' => ['<slug:.+>' => 'site/page']]]];

shortLinkApp([], ['REQUEST_URI' => '/go/ihf26'], $catchAll);
ShortLinkUrlRule::register(Yii::$app->urlManager);
check('registered rule wins over a catch-all rule', $route, Yii::$app->urlManager->parseRequest(Yii::$app->request)[0]);

shortLinkApp(['shortLinks' => ['enabled' => false]], ['REQUEST_URI' => '/go/ihf26'], $catchAll);
ShortLinkUrlRule::register(Yii::$app->urlManager);
check('disabled feature registers nothing', 'site/page', Yii::$app->urlManager->parseRequest(Yii::$app->request)[0]);

shortLinkDone();
