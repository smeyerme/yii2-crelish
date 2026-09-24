<?php

namespace giantbits\crelish\components\shortlinks;

use yii\db\Expression;
use yii\db\Query;

/**
 * Short link numbers for the admin.
 *
 * Days up to the last nightly aggregation come from analytics_element_daily;
 * later days come from raw events, filtered for bots the same way the nightly
 * job does. Using the last aggregated date as the boundary avoids both gaps
 * (job not run yet) and double counting.
 */
final class ShortLinkStats
{
  public const TYPES = ['scan', 'click', 'fallback'];

  public function lastAggregatedDate(): ?string
  {
    $date = (new Query())->from('{{%analytics_element_daily}}')->max('date');

    return $date ? substr((string)$date, 0, 10) : null;
  }

  /**
   * @return array{totals: array<string,int>, uniqueSessions: int, days: array<string, array<string,int>>}
   */
  public function forLink(string $uuid, string $startDate, string $endDate): array
  {
    $totals = array_fill_keys(self::TYPES, 0);
    $days = [];
    $unique = 0;

    for ($date = $startDate; $date <= $endDate; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
      $days[$date] = array_fill_keys(self::TYPES, 0);
    }

    foreach ($this->rows([$uuid], $startDate, $endDate) as $row) {
      if (!isset($totals[$row['type']], $days[$row['date']])) {
        continue;
      }

      $days[$row['date']][$row['type']] += (int)$row['views'];
      $totals[$row['type']] += (int)$row['views'];
      $unique += (int)$row['sessions'];
    }

    return ['totals' => $totals, 'uniqueSessions' => $unique, 'days' => $days];
  }

  /**
   * @param string[] $uuids
   * @return array<string, array{scan:int, click:int, fallback:int, last:?string}> `last` is
   *   'Y-m-d H:i:s' when it comes from a raw event, or 'Y-m-d' when it falls back to the
   *   aggregated table because the nightly job has already pruned the raw rows.
   */
  public function summaries(array $uuids, int $days = 30): array
  {
    $result = [];

    foreach ($uuids as $uuid) {
      $result[$uuid] = ['scan' => 0, 'click' => 0, 'fallback' => 0, 'last' => null];
    }

    if ($uuids === []) {
      return $result;
    }

    $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

    foreach ($this->rows($uuids, $startDate, date('Y-m-d')) as $row) {
      if (isset($result[$row['uuid']][$row['type']])) {
        $result[$row['uuid']][$row['type']] += (int)$row['views'];
      }
    }

    $last = (new Query())
      ->select(['uuid' => 'ev.element_uuid', 'last' => new Expression('MAX(ev.created_at)')])
      ->from(['ev' => '{{%analytics_element_views}}'])
      ->innerJoin(['s' => '{{%analytics_sessions}}'], 's.session_id = ev.session_id')
      ->where(['ev.element_type' => 'shortlink', 'ev.element_uuid' => $uuids, 's.is_bot' => 0])
      ->groupBy('ev.element_uuid')
      ->all();

    foreach ($last as $row) {
      $result[$row['uuid']]['last'] = $row['last'];
    }

    // The nightly job prunes raw events past retention, so a link whose latest hit is
    // older than that would otherwise report no last hit even though the aggregated
    // table still has its traffic. Fall back to the aggregated day (day precision) for
    // any uuid that has no raw (non-bot) hit; a raw timestamp always takes precedence.
    $missing = [];
    foreach ($uuids as $uuid) {
      if ($result[$uuid]['last'] === null) {
        $missing[] = $uuid;
      }
    }

    if ($missing !== []) {
      $lastAggregated = (new Query())
        ->select(['uuid' => 'element_uuid', 'last' => new Expression('MAX(date)')])
        ->from('{{%analytics_element_daily}}')
        ->where(['element_type' => 'shortlink', 'element_uuid' => $missing])
        ->groupBy('element_uuid')
        ->all();

      foreach ($lastAggregated as $row) {
        $result[$row['uuid']]['last'] = substr((string)$row['last'], 0, 10);
      }
    }

    return $result;
  }

  /**
   * @return list<array{uuid: string, date: string, type: string, views: int|string, sessions: int|string}>
   */
  private function rows(array $uuids, string $startDate, string $endDate): array
  {
    $lastAggregated = $this->lastAggregatedDate();
    $rows = [];

    if ($lastAggregated !== null) {
      $aggregatedEnd = min($endDate, $lastAggregated);

      if ($startDate <= $aggregatedEnd) {
        $rows = (new Query())
          ->select([
            'uuid' => 'element_uuid',
            'date' => 'date',
            'type' => 'event_type',
            'views' => new Expression('SUM(total_views)'),
            'sessions' => new Expression('SUM(unique_sessions)'),
          ])
          ->from('{{%analytics_element_daily}}')
          ->where(['element_type' => 'shortlink', 'element_uuid' => $uuids])
          ->andWhere(['between', 'date', $startDate, $aggregatedEnd])
          ->groupBy(['element_uuid', 'date', 'event_type'])
          ->all();
      }
    }

    $rawStart = $lastAggregated !== null
      ? max($startDate, date('Y-m-d', strtotime($lastAggregated . ' +1 day')))
      : $startDate;

    if ($rawStart <= $endDate) {
      $day = new Expression('DATE(ev.created_at)');
      $raw = (new Query())
        ->select([
          'uuid' => 'ev.element_uuid',
          'date' => $day,
          'type' => 'ev.type',
          'views' => new Expression('COUNT(*)'),
          'sessions' => new Expression('COUNT(DISTINCT ev.session_id)'),
        ])
        ->from(['ev' => '{{%analytics_element_views}}'])
        ->innerJoin(['s' => '{{%analytics_sessions}}'], 's.session_id = ev.session_id')
        ->where(['ev.element_type' => 'shortlink', 'ev.element_uuid' => $uuids, 's.is_bot' => 0])
        ->andWhere(['>=', 'ev.created_at', $rawStart . ' 00:00:00'])
        ->andWhere(['<', 'ev.created_at', date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'])
        ->groupBy(['ev.element_uuid', $day, 'ev.type'])
        ->all();

      $rows = array_merge($rows, $raw);
    }

    return $rows;
  }
}
