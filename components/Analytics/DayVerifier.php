<?php

namespace giantbits\crelish\components\Analytics;

use yii\db\Connection;

/**
 * Raw and stored counts of one day, filtered exactly as the aggregation filters.
 */
final class DayVerifier
{
    public function __construct(private Connection $db, private bool $withVisits)
    {
    }

    /**
     * @return array{raw: array<string, int>, stored: array<string, int>}
     */
    public function counts(string $date): array
    {
        [$start, $end] = AnalyticsRetention::dayRange($date);
        $range = [':start' => $start, ':end' => $end];
        $day = [':date' => $date];
        $pages = "FROM {{%analytics_page_views}} WHERE created_at >= :start AND created_at < :end AND is_bot = 0";
        $elements = "FROM {{%analytics_element_views}} ev
            INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
            WHERE ev.created_at >= :start AND ev.created_at < :end AND s.is_bot = 0";

        $raw = [
            'page_views' => $this->int("SELECT COUNT(*) {$pages}", $range),
            'element_views' => $this->int("SELECT COUNT(*) {$elements}", $range),
        ];
        $stored = [
            'page_views' => $this->int('SELECT SUM(total_views) FROM {{%analytics_page_daily}} WHERE date = :date', $day),
            'element_views' => $this->int('SELECT SUM(total_views) FROM {{%analytics_element_daily}} WHERE date = :date', $day),
        ];

        if ($this->withVisits) {
            $site = 'SELECT unique_sessions FROM ' . VisitsAggregator::TABLE
                . " WHERE date = :date AND source = :source AND owner_uuid = '' AND event_type = ''";
            $raw['page_visits'] = $this->int("SELECT COUNT(DISTINCT session_id) {$pages}", $range);
            $raw['element_visits'] = $this->int("SELECT COUNT(DISTINCT ev.session_id) {$elements}", $range);
            $stored['page_visits'] = $this->int($site, $day + [':source' => VisitsAggregator::SOURCE_PAGES]);
            $stored['element_visits'] = $this->int($site, $day + [':source' => VisitsAggregator::SOURCE_ELEMENTS]);
        }

        return ['raw' => $raw, 'stored' => $stored];
    }

    private function int(string $sql, array $params): int
    {
        return (int)$this->db->createCommand($sql, $params)->queryScalar();
    }
}
