<?php

namespace giantbits\crelish\components\Analytics;

use Yii;
use yii\db\Connection;
use yii\db\Query;

/**
 * Visits for the statistics: distinct sessions per day, summed over days.
 * Never sums across owners or event types; only the any-event row ('') of
 * the requested owner is read.
 */
final class VisitsReader
{
    private Connection $db;
    private ?bool $available = null;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Yii::$app->db;
    }

    public function available(): bool
    {
        return $this->available ??= VisitsAggregator::tableExists($this->db);
    }

    /**
     * @return array{available: bool, since: ?string, visits: ?int, users: ?int}
     *         since: first recorded day when the period starts before it, else null;
     *         visits/users: null when the period ends before the first recorded day
     */
    public function summary(string $source, string $owner, string $start, string $end): array
    {
        if (!$this->available()) {
            return ['available' => false, 'since' => null, 'visits' => null, 'users' => null];
        }

        $row = $this->query($source, $owner, $start, $end)
            ->select(['visits' => 'SUM(unique_sessions)', 'users' => 'SUM(unique_users)'])
            ->one($this->db);
        $first = (new Query())->from(VisitsAggregator::TABLE)->min('date', $this->db);
        $first = ($first === null || $first === false) ? null : (string)$first;
        // A period that ends before the first recorded day has no figure, not zero
        $recorded = $first === null || $first <= $end;

        return [
            'available' => true,
            'since' => ($first !== null && $first > $start) ? $first : null,
            'visits' => $recorded ? (int)($row['visits'] ?? 0) : null,
            'users' => $recorded ? (int)($row['users'] ?? 0) : null,
        ];
    }

    /**
     * @return array<string, array{visits: int, users: int}> keyed by Y-m-d
     */
    public function byDay(string $source, string $owner, string $start, string $end): array
    {
        return $this->grouped('date', $source, $owner, $start, $end);
    }

    /**
     * @return array<string, array{visits: int, users: int}> keyed by Y-m
     */
    public function byMonth(string $source, string $owner, string $start, string $end): array
    {
        return $this->grouped('SUBSTR(date, 1, 7)', $source, $owner, $start, $end);
    }

    private function grouped(string $key, string $source, string $owner, string $start, string $end): array
    {
        if (!$this->available()) {
            return [];
        }

        $out = [];
        $rows = $this->query($source, $owner, $start, $end)
            ->select(['period' => $key, 'visits' => 'SUM(unique_sessions)', 'users' => 'SUM(unique_users)'])
            ->groupBy([$key])
            ->orderBy([$key => SORT_ASC])
            ->all($this->db);

        foreach ($rows as $row) {
            $out[(string)$row['period']] = ['visits' => (int)$row['visits'], 'users' => (int)$row['users']];
        }

        return $out;
    }

    private function query(string $source, string $owner, string $start, string $end): Query
    {
        return (new Query())
            ->from(VisitsAggregator::TABLE)
            ->where(['source' => $source, 'owner_uuid' => $owner, 'event_type' => ''])
            ->andWhere(['>=', 'date', $start])
            ->andWhere(['<=', 'date', $end]);
    }
}
