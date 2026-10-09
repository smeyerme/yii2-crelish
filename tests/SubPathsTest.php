<?php

/**
 * Pages below a page: which addresses a project accepts.
 *
 * /stellen/anything used to show the whole job list again under an address of its own.
 *
 * Run with:  php tests/SubPathsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../components/SubPaths.php';

use giantbits\crelish\components\SubPaths;

$failures = 0;
function check(string $what, $expected, $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "  ok   $what\n";
        return;
    }
    $failures++;
    echo "  FAIL $what\n         expected: " . var_export($expected, true) . "\n         actual:   " . var_export($actual, true) . "\n";
}

echo "the setting\n";
check('not set', null, SubPaths::configured(null));
check('something that is no list counts as not set', null, SubPaths::configured('news'));
check('an empty list is a setting: no page has pages below it', [], SubPaths::configured([]));
check('names are tidied', ['news', 'stellendetail'], SubPaths::configured([' News ', '/stellendetail/', 'news', '', 7]));

echo "what follows the page\n";
check('nothing', [], SubPaths::segments(null));
check('an address ending in a slash hands on an empty part', [], SubPaths::segments(['']));
check('id and title', ['abc-123', 'zimmerer'], SubPaths::segments(['abc-123', 'zimmerer']));
check('a query parameter named 0 is no path', [], SubPaths::segments('x'));

echo "allowed\n";
$pages = ['news', 'stellendetail'];
check('without the setting everything is, as before', true, SubPaths::allowed(null, 'kontakt', ['anything']));
check('a page alone always is', true, SubPaths::allowed($pages, 'kontakt', []));
check('a named page with something below it', true, SubPaths::allowed($pages, 'news', ['abc-123', 'titel']));
check('the name in other case', true, SubPaths::allowed($pages, 'News', ['abc-123']));
check('any other page with something below it is not', false, SubPaths::allowed($pages, 'kontakt', ['anything']));
check('with an empty list no page is', false, SubPaths::allowed([], 'news', ['abc-123']));

echo "closing slash\n";
check('none', null, SubPaths::withoutClosingSlash('stellen'));
check('the start page has none to lose', null, SubPaths::withoutClosingSlash(''));
check('one', '/stellen', SubPaths::withoutClosingSlash('stellen/'));
check('several', '/news/abc', SubPaths::withoutClosingSlash('news/abc//'));
check('the query is kept', '/stellen?page=2', SubPaths::withoutClosingSlash('stellen/', 'page=2'));

echo "the page of an address\n";
check('a page', ['page' => 'stellen', 'below' => false], SubPaths::pageOf('https://example.com/stellen', false));
check('below a page', ['page' => 'news', 'below' => true], SubPaths::pageOf('https://example.com/news/abc-123', false));
check('a closing slash is not below', ['page' => 'stellen', 'below' => false], SubPaths::pageOf('https://example.com/stellen/', false));
check('the start page', null, SubPaths::pageOf('https://example.com/', false));
check('with language prefix: a page', ['page' => 'spiele', 'below' => false], SubPaths::pageOf('https://example.com/de/spiele', true));
check('with language prefix: below a page', ['page' => 'spiel', 'below' => true], SubPaths::pageOf('https://example.com/de/spiel/catan', true));
check('with language prefix: the start page of a language', null, SubPaths::pageOf('https://example.com/de', true));
check('without language prefix two letters are a page', ['page' => 'de', 'below' => true], SubPaths::pageOf('https://example.com/de/spiele', false));
check('umlauts in the name', ['page' => 'über-uns', 'below' => false], SubPaths::pageOf('https://example.com/%C3%BCber-uns', false));
check('the address the report groups by, cut after three parts', ['page' => 'news', 'below' => true], SubPaths::pageOf('https://example.com/news/abc/titel', false));

echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
