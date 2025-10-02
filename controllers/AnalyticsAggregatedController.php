<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use Yii;
use yii\web\Response;
use yii\db\Query;
use yii\db\Expression;

/**
 * Analytics Aggregated Controller
 *
 * Provides insights and charts based on aggregated analytics data.
 * Uses pre-aggregated tables for faster performance:
 * - analytics_element_daily / analytics_element_monthly
 * - analytics_page_daily / analytics_page_monthly
 */
class AnalyticsAggregatedController extends CrelishBaseController
{
  /**
   * @inheritdoc
   */
  public function init()
  {
    parent::init();
    Yii::$app->view->title = 'Analytics Insights';

    // Make sure Chart.js is loaded
    $this->view->registerJsFile('https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js', ['position' => \yii\web\View::POS_HEAD]);

    // Register dashboard-specific CSS
    $this->view->registerCss($this->getDashboardCss());
  }

  /**
   * Override the setupHeaderBar method for dashboard-specific components
   */
  protected function setupHeaderBar()
  {
    // Default left components for all actions
    $this->view->params['headerBarLeft'] = ['toggle-sidebar'];

    // Default right components (empty by default)
    $this->view->params['headerBarRight'] = [];

    // Set specific components based on action
    $action = $this->action ? $this->action->id : null;

    switch ($action) {
      case 'index':
        $this->view->params['headerBarLeft'][] = ['title', Yii::t('crelish', 'Analytics Insights')];
        break;

      case 'elements':
        $this->view->params['headerBarLeft'][] = ['title', Yii::t('crelish', 'Element Performance')];
        break;

      case 'pages':
        $this->view->params['headerBarLeft'][] = ['title', Yii::t('crelish', 'Page Performance')];
        break;

      default:
        break;
    }
  }

  /**
   * Dashboard index - overview of aggregated analytics
   */
  public function actionIndex()
  {
    $period = Yii::$app->request->get('period', 'month');

    return $this->render('index.twig', [
      'period' => $period
    ]);
  }

  /**
   * Page performance detail view
   */
  public function actionPageDetail()
  {
    $pageUuid = Yii::$app->request->get('page_uuid');
    $period = Yii::$app->request->get('period', 'month');

    if (!$pageUuid) {
      Yii::$app->session->setFlash('error', Yii::t('crelish', 'Page UUID is required'));
      return $this->redirect(['index']);
    }

    return $this->render('page-performance.twig', [
      'pageUuid' => $pageUuid,
      'period' => $period
    ]);
  }

  /**
   * Element performance detail view
   */
  public function actionElementDetail()
  {
    $elementUuid = Yii::$app->request->get('element_uuid');
    $period = Yii::$app->request->get('period', 'month');

    if (!$elementUuid) {
      Yii::$app->session->setFlash('error', Yii::t('crelish', 'Element UUID is required'));
      return $this->redirect(['index']);
    }

    return $this->render('element-performance.twig', [
      'elementUuid' => $elementUuid,
      'period' => $period
    ]);
  }

  /**
   * Get overview stats (KPIs) from aggregated data
   */
  public function actionOverviewStats()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period = Yii::$app->request->get('period', 'month');
    list($startDate, $endDate) = $this->getPeriodDates($period);

    // Get page view stats from daily aggregates
    $pageStats = (new Query())
      ->select([
        'total_views' => 'SUM(total_views)',
        'total_sessions' => 'SUM(unique_sessions)',
        'total_users' => 'SUM(unique_users)',
        'unique_pages' => 'COUNT(DISTINCT page_uuid)'
      ])
      ->from('{{%analytics_page_daily}}')
      ->where(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->one();

    // Get element view stats from daily aggregates
    $elementStats = (new Query())
      ->select([
        'total_views' => 'SUM(total_views)',
        'total_sessions' => 'SUM(unique_sessions)',
        'unique_elements' => 'COUNT(DISTINCT element_uuid)'
      ])
      ->from('{{%analytics_element_daily}}')
      ->where(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->one();

    // Get event type breakdown
    $eventTypeStats = (new Query())
      ->select([
        'event_type',
        'total_views' => 'SUM(total_views)'
      ])
      ->from('{{%analytics_element_daily}}')
      ->where(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->groupBy(['event_type'])
      ->all();

    return [
      'pageStats' => $pageStats,
      'elementStats' => $elementStats,
      'eventTypeStats' => $eventTypeStats
    ];
  }

  /**
   * Get page views trend over time from aggregated data
   */
  public function actionPageViewsTrend()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period = Yii::$app->request->get('period', 'month');
    $granularity = Yii::$app->request->get('granularity', 'daily');
    list($startDate, $endDate) = $this->getPeriodDates($period);

    if ($granularity === 'monthly' || $period === 'year') {
      // Use monthly aggregates for better performance
      $data = (new Query())
        ->select([
          'period' => new Expression("CONCAT(year, '-', LPAD(month, 2, '0'))"),
          'total_views' => 'SUM(total_views)',
          'unique_sessions' => 'SUM(unique_sessions)',
          'unique_users' => 'SUM(unique_users)'
        ])
        ->from('{{%analytics_page_monthly}}')
        ->where(['>=', new Expression("CONCAT(year, '-', LPAD(month, 2, '0'), '-01')"), $startDate])
        ->andWhere(['<=', new Expression("CONCAT(year, '-', LPAD(month, 2, '0'), '-01')"), $endDate])
        ->groupBy(['year', 'month'])
        ->orderBy(['year' => SORT_ASC, 'month' => SORT_ASC])
        ->all();
    } else {
      // Use daily aggregates
      $data = (new Query())
        ->select([
          'period' => 'date',
          'total_views' => 'SUM(total_views)',
          'unique_sessions' => 'SUM(unique_sessions)',
          'unique_users' => 'SUM(unique_users)'
        ])
        ->from('{{%analytics_page_daily}}')
        ->where(['>=', 'date', $startDate])
        ->andWhere(['<=', 'date', $endDate])
        ->groupBy(['date'])
        ->orderBy(['date' => SORT_ASC])
        ->all();
    }

    return $data;
  }

  /**
   * Get top pages from aggregated data
   */
  public function actionTopPages()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period = Yii::$app->request->get('period', 'month');
    $limit = Yii::$app->request->get('limit', 10);
    list($startDate, $endDate) = $this->getPeriodDates($period);

    // Use monthly or daily aggregates based on period
    $useMonthly = in_array($period, ['year', 'all']);

    if ($useMonthly) {
      $pages = (new Query())
        ->select([
          'page_uuid',
          'page_url' => 'MIN(page_url)',
          'total_views' => 'SUM(total_views)',
          'unique_sessions' => 'SUM(unique_sessions)',
          'unique_users' => 'SUM(unique_users)'
        ])
        ->from('{{%analytics_page_monthly}}')
        ->where(['>=', new Expression("CONCAT(year, '-', LPAD(month, 2, '0'), '-01')"), $startDate])
        ->andWhere(['<=', new Expression("CONCAT(year, '-', LPAD(month, 2, '0'), '-01')"), $endDate])
        ->groupBy(['page_uuid'])
        ->orderBy(['total_views' => SORT_DESC])
        ->limit($limit)
        ->all();
    } else {
      $pages = (new Query())
        ->select([
          'page_uuid',
          'page_url' => 'MIN(page_url)',
          'total_views' => 'SUM(total_views)',
          'unique_sessions' => 'SUM(unique_sessions)',
          'unique_users' => 'SUM(unique_users)'
        ])
        ->from('{{%analytics_page_daily}}')
        ->where(['>=', 'date', $startDate])
        ->andWhere(['<=', 'date', $endDate])
        ->groupBy(['page_uuid'])
        ->orderBy(['total_views' => SORT_DESC])
        ->limit($limit)
        ->all();
    }

    // Enrich with page titles
    foreach ($pages as &$page) {
      $page['title'] = $this->getPageTitle($page['page_uuid']) ?? $page['page_url'];
      $page['avg_time_per_session'] = $page['unique_sessions'] > 0
        ? round($page['total_views'] / $page['unique_sessions'], 2)
        : 0;
    }

    return $pages;
  }

  /**
   * Get top elements from aggregated data
   */
  public function actionTopElements()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period = Yii::$app->request->get('period', 'month');
    $limit = Yii::$app->request->get('limit', 10);
    $eventType = Yii::$app->request->get('event_type'); // filter by event type
    $elementType = Yii::$app->request->get('element_type'); // filter by element type
    list($startDate, $endDate) = $this->getPeriodDates($period);

    // Use monthly or daily aggregates based on period
    $useMonthly = in_array($period, ['year', 'all']);

    $query = (new Query())
      ->select([
        'element_uuid',
        'element_type',
        'event_type',
        'total_views' => 'SUM(total_views)',
        'unique_sessions' => 'SUM(unique_sessions)',
        'unique_users' => 'SUM(unique_users)'
      ]);

    if ($useMonthly) {
      $query->from('{{%analytics_element_monthly}}')
        ->where(['>=', new Expression("CONCAT(year, '-', LPAD(month, 2, '0'), '-01')"), $startDate])
        ->andWhere(['<=', new Expression("CONCAT(year, '-', LPAD(month, 2, '0'), '-01')"), $endDate]);
    } else {
      $query->from('{{%analytics_element_daily}}')
        ->where(['>=', 'date', $startDate])
        ->andWhere(['<=', 'date', $endDate]);
    }

    if ($eventType) {
      $query->andWhere(['event_type' => $eventType]);
    }

    if ($elementType) {
      $query->andWhere(['element_type' => $elementType]);
    }

    $elements = $query
      ->groupBy(['element_uuid', 'element_type', 'event_type'])
      ->orderBy(['total_views' => SORT_DESC])
      ->limit($limit)
      ->all();

    // Enrich with element titles
    foreach ($elements as &$element) {
      $element['title'] = $this->getElementTitle($element['element_uuid'], $element['element_type'])
        ?? ucfirst($element['element_type']) . ': ' . $element['element_uuid'];
    }

    return $elements;
  }

  /**
   * Get element type distribution
   */
  public function actionElementTypeDistribution()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period = Yii::$app->request->get('period', 'month');
    list($startDate, $endDate) = $this->getPeriodDates($period);

    $distribution = (new Query())
      ->select([
        'element_type',
        'total_views' => 'SUM(total_views)',
        'unique_sessions' => 'SUM(unique_sessions)',
        'unique_elements' => 'COUNT(DISTINCT element_uuid)'
      ])
      ->from('{{%analytics_element_daily}}')
      ->where(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->groupBy(['element_type'])
      ->orderBy(['total_views' => SORT_DESC])
      ->all();

    return $distribution;
  }

  /**
   * Get event type distribution
   */
  public function actionEventTypeDistribution()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period = Yii::$app->request->get('period', 'month');
    list($startDate, $endDate) = $this->getPeriodDates($period);

    $distribution = (new Query())
      ->select([
        'event_type',
        'total_views' => 'SUM(total_views)',
        'unique_sessions' => 'SUM(unique_sessions)'
      ])
      ->from('{{%analytics_element_daily}}')
      ->where(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->groupBy(['event_type'])
      ->orderBy(['total_views' => SORT_DESC])
      ->all();

    return $distribution;
  }

  /**
   * Get page performance details
   */
  public function actionPagePerformance()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $pageUuid = Yii::$app->request->get('page_uuid');
    $period = Yii::$app->request->get('period', 'month');
    list($startDate, $endDate) = $this->getPeriodDates($period);

    if (!$pageUuid) {
      return ['error' => 'Page UUID is required'];
    }

    // Get daily trend for this page
    $trend = (new Query())
      ->select([
        'date',
        'total_views',
        'unique_sessions',
        'unique_users'
      ])
      ->from('{{%analytics_page_daily}}')
      ->where(['page_uuid' => $pageUuid])
      ->andWhere(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->orderBy(['date' => SORT_ASC])
      ->all();

    // Get elements viewed on this page
    $elements = (new Query())
      ->select([
        'element_uuid',
        'element_type',
        'event_type',
        'total_views' => 'SUM(total_views)',
        'unique_sessions' => 'SUM(unique_sessions)'
      ])
      ->from('{{%analytics_element_daily}}')
      ->where(['page_uuid' => $pageUuid])
      ->andWhere(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->groupBy(['element_uuid', 'element_type', 'event_type'])
      ->orderBy(['total_views' => SORT_DESC])
      ->limit(20)
      ->all();

    // Enrich element data
    foreach ($elements as &$element) {
      $element['title'] = $this->getElementTitle($element['element_uuid'], $element['element_type'])
        ?? ucfirst($element['element_type']) . ': ' . $element['element_uuid'];
    }

    return [
      'trend' => $trend,
      'elements' => $elements
    ];
  }

  /**
   * Get element performance details
   */
  public function actionElementPerformance()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $elementUuid = Yii::$app->request->get('element_uuid');
    $period = Yii::$app->request->get('period', 'month');
    list($startDate, $endDate) = $this->getPeriodDates($period);

    if (!$elementUuid) {
      return ['error' => 'Element UUID is required'];
    }

    // Get daily trend for this element
    $trend = (new Query())
      ->select([
        'date',
        'event_type',
        'total_views',
        'unique_sessions',
        'unique_users'
      ])
      ->from('{{%analytics_element_daily}}')
      ->where(['element_uuid' => $elementUuid])
      ->andWhere(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->orderBy(['date' => SORT_ASC, 'event_type' => SORT_ASC])
      ->all();

    // Get pages where this element appears
    $pages = (new Query())
      ->select([
        'page_uuid',
        'event_type',
        'total_views' => 'SUM(total_views)',
        'unique_sessions' => 'SUM(unique_sessions)'
      ])
      ->from('{{%analytics_element_daily}}')
      ->where(['element_uuid' => $elementUuid])
      ->andWhere(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->andWhere(['not', ['page_uuid' => null]])
      ->groupBy(['page_uuid', 'event_type'])
      ->orderBy(['total_views' => SORT_DESC])
      ->limit(20)
      ->all();

    // Enrich page data
    foreach ($pages as &$page) {
      $page['title'] = $this->getPageTitle($page['page_uuid']) ?? 'Unknown Page';
    }

    return [
      'trend' => $trend,
      'pages' => $pages
    ];
  }

  /**
   * Compare two time periods
   */
  public function actionComparePeriods()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period1 = Yii::$app->request->get('period1', 'month');
    $period2 = Yii::$app->request->get('period2', 'previous_month');

    list($start1, $end1) = $this->getPeriodDates($period1);
    list($start2, $end2) = $this->getPeriodDates($period2);

    // Get stats for both periods
    $stats1 = $this->getPeriodStats($start1, $end1);
    $stats2 = $this->getPeriodStats($start2, $end2);

    // Calculate changes
    $comparison = [
      'period1' => [
        'label' => $this->getPeriodLabel($period1),
        'stats' => $stats1
      ],
      'period2' => [
        'label' => $this->getPeriodLabel($period2),
        'stats' => $stats2
      ],
      'changes' => [
        'page_views' => $this->calculatePercentageChange($stats2['page_views'], $stats1['page_views']),
        'unique_sessions' => $this->calculatePercentageChange($stats2['unique_sessions'], $stats1['unique_sessions']),
        'element_views' => $this->calculatePercentageChange($stats2['element_views'], $stats1['element_views'])
      ]
    ];

    return $comparison;
  }

  /**
   * Export aggregated data to CSV
   */
  public function actionExport()
  {
    $period = Yii::$app->request->get('period', 'month');
    $type = Yii::$app->request->get('type', 'pages');
    list($startDate, $endDate) = $this->getPeriodDates($period);

    $filename = 'analytics_aggregated_' . $type . '_' . date('Y-m-d') . '.csv';

    $query = (new Query());

    if ($type === 'pages') {
      $query->select([
          'date',
          'page_uuid',
          'page_url',
          'total_views',
          'unique_sessions',
          'unique_users'
        ])
        ->from('{{%analytics_page_daily}}')
        ->where(['>=', 'date', $startDate])
        ->andWhere(['<=', 'date', $endDate])
        ->orderBy(['date' => SORT_ASC, 'total_views' => SORT_DESC]);
    } else {
      $query->select([
          'date',
          'element_uuid',
          'element_type',
          'event_type',
          'total_views',
          'unique_sessions',
          'unique_users'
        ])
        ->from('{{%analytics_element_daily}}')
        ->where(['>=', 'date', $startDate])
        ->andWhere(['<=', 'date', $endDate])
        ->orderBy(['date' => SORT_ASC, 'total_views' => SORT_DESC]);
    }

    $data = $query->all();

    // Build CSV content
    $fp = fopen('php://temp', 'r+');

    if (!empty($data)) {
      fputcsv($fp, array_keys($data[0]));

      foreach ($data as $row) {
        fputcsv($fp, $row);
      }
    }

    rewind($fp);
    $csvContent = stream_get_contents($fp);
    fclose($fp);

    // Set response headers
    Yii::$app->response->format = Response::FORMAT_RAW;
    Yii::$app->response->headers->set('Content-Type', 'text/csv');
    Yii::$app->response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $csvContent;
  }

  /**
   * Get period dates (start and end)
   * @param string $period
   * @return array [startDate, endDate]
   */
  private function getPeriodDates($period)
  {
    switch ($period) {
      case 'today':
        return [date('Y-m-d'), date('Y-m-d')];
      case 'yesterday':
        return [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))];
      case 'week':
        return [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')];
      case 'month':
        return [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')];
      case 'previous_month':
        return [date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('-31 days'))];
      case 'quarter':
        return [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')];
      case 'year':
        return [date('Y-m-d', strtotime('-365 days')), date('Y-m-d')];
      case 'all':
        return ['2000-01-01', date('Y-m-d')];
      default:
        return [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')];
    }
  }

  /**
   * Get period label for display
   * @param string $period
   * @return string
   */
  private function getPeriodLabel($period)
  {
    $labels = [
      'today' => Yii::t('crelish', 'Today'),
      'yesterday' => Yii::t('crelish', 'Yesterday'),
      'week' => Yii::t('crelish', 'Last 7 Days'),
      'month' => Yii::t('crelish', 'Last 30 Days'),
      'previous_month' => Yii::t('crelish', 'Previous Month'),
      'quarter' => Yii::t('crelish', 'Last 90 Days'),
      'year' => Yii::t('crelish', 'Last Year'),
      'all' => Yii::t('crelish', 'All Time')
    ];

    return $labels[$period] ?? $labels['month'];
  }

  /**
   * Get stats for a specific period
   * @param string $startDate
   * @param string $endDate
   * @return array
   */
  private function getPeriodStats($startDate, $endDate)
  {
    $pageStats = (new Query())
      ->select([
        'page_views' => 'SUM(total_views)',
        'unique_sessions' => 'SUM(unique_sessions)'
      ])
      ->from('{{%analytics_page_daily}}')
      ->where(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->one();

    $elementStats = (new Query())
      ->select([
        'element_views' => 'SUM(total_views)'
      ])
      ->from('{{%analytics_element_daily}}')
      ->where(['>=', 'date', $startDate])
      ->andWhere(['<=', 'date', $endDate])
      ->one();

    return [
      'page_views' => (int)($pageStats['page_views'] ?? 0),
      'unique_sessions' => (int)($pageStats['unique_sessions'] ?? 0),
      'element_views' => (int)($elementStats['element_views'] ?? 0)
    ];
  }

  /**
   * Calculate percentage change between two values
   * @param float $old
   * @param float $new
   * @return float
   */
  private function calculatePercentageChange($old, $new)
  {
    if ($old == 0) {
      return $new > 0 ? 100 : 0;
    }

    return round((($new - $old) / $old) * 100, 2);
  }

  /**
   * Get page title from database
   * @param string $pageUuid
   * @return string|null
   */
  private function getPageTitle($pageUuid)
  {
    try {
      // Try to find the page in analytics_page_views to get page_type
      $pageView = (new Query())
        ->select(['page_type'])
        ->from('{{%analytics_page_views}}')
        ->where(['page_uuid' => $pageUuid])
        ->limit(1)
        ->one();

      if (!$pageView || !isset($pageView['page_type'])) {
        return null;
      }

      $modelClass = 'app\\workspace\\models\\' . ucfirst($pageView['page_type']);
      if (!class_exists($modelClass)) {
        return null;
      }

      $pageModel = call_user_func($modelClass . '::find')
        ->where(['uuid' => $pageUuid])
        ->one();

      if ($pageModel && isset($pageModel['systitle'])) {
        return $pageModel['systitle'];
      }
    } catch (\Exception $e) {
      Yii::warning('Failed to load page title: ' . $e->getMessage());
    }

    return null;
  }

  /**
   * Get element title from database
   * @param string $elementUuid
   * @param string $elementType
   * @return string|null
   */
  private function getElementTitle($elementUuid, $elementType)
  {
    try {
      $modelClass = 'app\\workspace\\models\\' . ucfirst($elementType);
      if (!class_exists($modelClass)) {
        return null;
      }

      $elementModel = call_user_func($modelClass . '::find')
        ->where(['uuid' => $elementUuid])
        ->one();

      if ($elementModel && isset($elementModel['systitle'])) {
        return $elementModel['systitle'];
      }

      // Try 'title' field as fallback
      if ($elementModel && isset($elementModel['title'])) {
        return $elementModel['title'];
      }
    } catch (\Exception $e) {
      Yii::warning('Failed to load element title: ' . $e->getMessage());
    }

    return null;
  }

  /**
   * Get CSS for dashboard
   * @return string
   */
  private function getDashboardCss()
  {
    return <<<CSS
/* Analytics Insights specific styles */
.insights-card {
    position: relative;
    margin-bottom: 1.5rem;
}

.kpi-card {
    text-align: center;
    padding: 1.5rem;
}

.kpi-value {
    font-size: 2.5rem;
    font-weight: bold;
    color: var(--bs-primary);
}

.kpi-label {
    font-size: 0.9rem;
    color: var(--bs-secondary);
    margin-top: 0.5rem;
}

.kpi-change {
    font-size: 0.85rem;
    margin-top: 0.5rem;
}

.kpi-change.positive {
    color: var(--bs-success);
}

.kpi-change.negative {
    color: var(--bs-danger);
}

.chart-container {
    position: relative;
    width: 100%;
    min-height: 300px;
}

.insights-table {
    font-size: 0.9rem;
}

.insights-table th {
    font-weight: 600;
    border-bottom: 2px solid var(--bs-border-color);
}

.badge-metric {
    font-size: 0.8rem;
    padding: 0.25rem 0.5rem;
}

/* Dark mode compatibility */
html[data-bs-theme="dark"] .kpi-value {
    color: var(--bs-info);
}

html[data-bs-theme="dark"] .insights-table {
    color: var(--bs-body-color);
}
CSS;
  }
}