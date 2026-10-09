<?php

namespace giantbits\crelish\components;

/**
 * Pages below a page: /news/<id>/<title> below "news".
 *
 * Whatever follows a page's name in the path is handed to that page, and a few pages use
 * it (a job, a news article, a company). All others ignored it, so /contact/anything
 * showed the contact page again under an address of its own - a copy for search engines
 * and a way to make up endless addresses.
 *
 * A project names the pages that have pages below them:
 *
 *     'params' => ['crelish' => ['subPaths' => ['news', 'stellendetail']]]
 *
 * Every other page called with something after its name is then answered like a page
 * that does not exist, and an address ending in a slash leads to the one without it.
 * Without the setting nothing changes: crelish cannot know which pages read the rest of
 * the path, so a project has to say it before anything is refused.
 *
 * Kept free of the framework so it can be tested on its own.
 */
final class SubPaths
{
    /**
     * The setting as a list of page names, or null when the project has not set it.
     *
     * @return string[]|null
     */
    public static function configured($setting): ?array
    {
        if (!is_array($setting)) {
            return null;
        }

        $pages = [];
        foreach ($setting as $page) {
            if (is_string($page) && trim($page, " /") !== '') {
                $pages[] = mb_strtolower(trim($page, " /"));
            }
        }

        return array_values(array_unique($pages));
    }

    /**
     * What follows the page in the path, as the URL rule hands it on. An address ending
     * in a slash hands on an empty part, which is nothing.
     *
     * @return string[]
     */
    public static function segments($handedOn): array
    {
        if (!is_array($handedOn)) {
            return [];
        }

        return array_values(array_filter($handedOn, static fn($segment) => is_string($segment) && $segment !== ''));
    }

    /**
     * Whether a page may be called with these parts after its name.
     *
     * @param string[]|null $pages see configured()
     */
    public static function allowed(?array $pages, string $page, array $segments): bool
    {
        return $pages === null || $segments === [] || in_array(mb_strtolower(trim($page, '/')), $pages, true);
    }

    /**
     * The address without its closing slash, or null when it has none.
     */
    public static function withoutClosingSlash(string $pathInfo, string $queryString = ''): ?string
    {
        if ($pathInfo === '' || !str_ends_with($pathInfo, '/')) {
            return null;
        }

        return '/' . trim($pathInfo, '/') . ($queryString !== '' ? '?' . $queryString : '');
    }

    /**
     * The page an address belongs to and whether the address goes below it.
     *
     * @param bool $languagePrefix whether addresses start with a two-letter language (/de/news)
     * @return array{page: string, below: bool}|null null for the start page and for what is no address
     */
    public static function pageOf(string $url, bool $languagePrefix): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $parts = array_values(array_filter(explode('/', $path), static fn(string $part) => $part !== ''));
        if ($languagePrefix && isset($parts[0]) && preg_match('/^[a-z]{2}$/', $parts[0])) {
            array_shift($parts);
        }
        if ($parts === []) {
            return null;
        }

        return ['page' => mb_strtolower(rawurldecode($parts[0])), 'below' => count($parts) > 1];
    }
}
