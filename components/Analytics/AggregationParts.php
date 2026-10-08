<?php

namespace giantbits\crelish\components\Analytics;

/**
 * Which aggregates a daily/monthly/backfill run computes (--only).
 *
 * pages    analytics_page_daily (and page_monthly in `monthly`)
 * elements analytics_element_daily (and element_monthly in `monthly`)
 * visits   analytics_visits_daily (daily only; monthly visits are sums of days)
 */
final class AggregationParts
{
    public const PAGES = 'pages';
    public const ELEMENTS = 'elements';
    public const VISITS = 'visits';
    public const ALL = [self::PAGES, self::ELEMENTS, self::VISITS];

    /**
     * @param string|null $only Comma list from --only; null or '' selects every part
     * @param bool $pagesOnly The --pagesOnly flag from 0.24.2, an alias for --only=pages
     * @return string[] Selected parts in the order of ALL
     * @throws \InvalidArgumentException For an unknown part, or --pagesOnly next to a different --only
     */
    public static function resolve(?string $only, bool $pagesOnly = false): array
    {
        $only = strtolower(trim((string)$only));

        if ($pagesOnly) {
            if ($only !== '' && $only !== self::PAGES) {
                throw new \InvalidArgumentException("--pagesOnly cannot be combined with --only={$only}");
            }

            return [self::PAGES];
        }

        if ($only === '') {
            return self::ALL;
        }

        $names = array_filter(array_map('trim', explode(',', $only)), static fn(string $name) => $name !== '');
        $unknown = array_diff($names, self::ALL);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown part(s) for --only: ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', self::ALL)
            );
        }

        return array_values(array_intersect(self::ALL, $names));
    }
}
