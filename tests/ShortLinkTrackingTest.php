<?php

/**
 * trackEvent: element events that do not come from a page view still need a
 * session row, or the nightly job deletes them as orphans.
 *
 * Run with:  php tests/ShortLinkTrackingTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use yii\db\Query;

const LINK = 'c0000000-0000-4000-8000-000000000001';

function sessions(): array
{
    return (new Query())->from('analytics_sessions')->all();
}

function views(): array
{
    return (new Query())->from('analytics_element_views')->orderBy('id')->all();
}

echo "New session\n";
shortLinkApp([], ['REQUEST_URI' => '/go/ihf26/q']);
check('trackEvent reports success', true, Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'scan'));
$session = sessions()[0] ?? [];
check('a session row is created', 1, count(sessions()));
check('session starts at the short link', LINK, $session['first_page_uuid'] ?? null);
check('session first_url is the short URL', 'https://forum-holzbau.test/go/ihf26/q', $session['first_url'] ?? null);
check('a redirect is not a page', 0, (int)($session['total_pages'] ?? -1));
check('iPhone Safari is not a bot', 0, (int)($session['is_bot'] ?? -1));
$view = views()[0] ?? [];
check('element type', 'shortlink', $view['element_type'] ?? null);
check('event type', 'scan', $view['type'] ?? null);
check('page_uuid is the link itself (NOT NULL column)', LINK, $view['page_uuid'] ?? null);
check('event belongs to the session', $session['session_id'] ?? 'x', $view['session_id'] ?? 'y');

echo "\nSame session again\n";
Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'click');
check('no second session row', 1, count(sessions()));
check('two events recorded', 2, count(views()));
check('total_pages still untouched', 0, (int)sessions()[0]['total_pages']);

echo "\nExisting page-view session, then a bot request\n";
shortLinkApp([], ['HTTP_USER_AGENT' => 'curl/8.4.0']);
$sessionId = Yii::$app->crelishAnalytics->getSessionId();
Yii::$app->db->createCommand()->insert('analytics_sessions', ['session_id' => $sessionId, 'is_bot' => 0, 'total_pages' => 3])->execute();
Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'click');
check('bot flag is upgraded', 1, (int)sessions()[0]['is_bot']);
check('page count is preserved', 3, (int)sessions()[0]['total_pages']);

echo "\nExclusions\n";
shortLinkApp([], [], ['components' => ['crelishAnalytics' => ['excludeIps' => ['203.0.113.7']]]]);
check('excluded IP is not tracked', false, Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'scan'));
check('excluded IP writes nothing', [0, 0], [count(sessions()), count(views())]);

shortLinkApp([], [], ['components' => ['crelishAnalytics' => ['enabled' => false]]]);
check('disabled analytics is not tracked', false, Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'scan'));

echo "\nOversized values\n";
$longAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' . str_repeat(' Extension/1.0', 30);
shortLinkApp([], ['HTTP_USER_AGENT' => $longAgent, 'REQUEST_URI' => '/go/ihf26?' . str_repeat('x', 400)]);
check('long values are still tracked', true, Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'click'));
check('user_agent is cut to 255', 255, mb_strlen((string)sessions()[0]['user_agent']));
check('first_url is cut to 255', 255, mb_strlen((string)sessions()[0]['first_url']));

shortLinkDone();
