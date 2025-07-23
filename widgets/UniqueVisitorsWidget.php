<?php

namespace giantbits\crelish\widgets;

use Yii;
use yii\db\Expression;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\db\Query;

/**
 * Widget that shows unique visitors statistics
 */
class UniqueVisitorsWidget extends CrelishDashboardWidget
{
  /**
   * @var string Period filter
   */
  public $period = 'week';

  /**
   * @var bool Whether to exclude bot traffic
   */
  public $excludeBots = true;

  /**
   * Initialize the widget
   */
  public function init()
  {
    parent::init();

    if (empty($this->title)) {
      $this->title = Yii::t('crelish', 'Unique Visitors');
    }

    $this->description = Yii::t('crelish', 'Shows unique visitor statistics');

    // Add period filter
    $this->filters['period'] = [
      'type' => 'select',
      'label' => Yii::t('crelish', 'Time Period'),
      'value' => $this->period,
      'options' => [
        'day' => Yii::t('crelish', 'Last 24 Hours'),
        'week' => Yii::t('crelish', 'Last 7 Days'),
        'month' => Yii::t('crelish', 'Last 30 Days'),
        'year' => Yii::t('crelish', 'Last Year'),
        'all' => Yii::t('crelish', 'All Time')
      ]
    ];

    // Add exclude bots filter
    $this->filters['excludeBots'] = [
      'type' => 'checkbox',
      'label' => Yii::t('crelish', 'Exclude Bot Traffic'),
      'value' => $this->excludeBots
    ];
  }

  /**
   * Render widget content
   * @return string
   */
  protected function renderContent()
  {
    // Get visitors data
    $visitorsData = $this->getVisitorsData();

    if (empty($visitorsData)) {
      return Html::tag('div',
        Yii::t('crelish', 'No visitor data available for the selected period'),
        ['class' => 'alert alert-info']
      );
    }

    // Start building HTML
    $html = '';

    // Add summary statistics
    $totalVisitors = count($visitorsData);
    $newVisitors = $this->countNewVisitors($visitorsData);
    $returningVisitors = $totalVisitors - $newVisitors;

    // Create donut chart
    $chartContainerId = $this->id . '-chart';

    $html .= '<div class="row">';
    // Chart column
    $html .= '<div class="col-md-5">';
    $html .= Html::tag('div', Html::tag('canvas', '', ['id' => $chartContainerId]),
      ['class' => 'chart-container', 'style' => 'height: 200px;']);
    $html .= '</div>';

    // Stats column
    $html .= '<div class="col-md-7">';

    // Total unique visitors
    $html .= '<div class="summary-stat">';
    $html .= Html::tag('h4', Yii::t('crelish', 'Total Unique Visitors'));
    $html .= Html::tag('div', Yii::$app->formatter->asInteger($totalVisitors), ['class' => 'stat-value']);
    $html .= '</div>';

    // New vs. returning breakdown
    $html .= '<div class="row">';

    // New visitors
    $html .= '<div class="col-6">';
    $html .= '<div class="summary-stat">';
    $html .= Html::tag('h4', Yii::t('crelish', 'New Visitors'));
    $html .= Html::tag('div', Yii::$app->formatter->asInteger($newVisitors), ['class' => 'stat-value']);
    $html .= '</div>';
    $html .= '</div>';

    // Returning visitors
    $html .= '<div class="col-6">';
    $html .= '<div class="summary-stat">';
    $html .= Html::tag('h4', Yii::t('crelish', 'Returning Visitors'));
    $html .= Html::tag('div', Yii::$app->formatter->asInteger($returningVisitors), ['class' => 'stat-value']);
    $html .= '</div>';
    $html .= '</div>';

    $html .= '</div>'; // end row
    $html .= '</div>'; // end stats column
    $html .= '</div>'; // end row

    // Add chart initialization JavaScript
    $chartData = [
      'labels' => [
        Yii::t('crelish', 'New Visitors'),
        Yii::t('crelish', 'Returning Visitors')
      ],
      'datasets' => [
        [
          'data' => [$newVisitors, $returningVisitors],
          'backgroundColor' => ['#4CAF50', '#2196F3'],
          'borderWidth' => 0
        ]
      ]
    ];

    $chartDataJson = Json::encode($chartData);

    $js = <<<JS
(function() {
    var ctx = document.getElementById('{$chartContainerId}').getContext('2d');
    
    // Destroy existing chart if exists
    if (window.dashboardCharts && window.dashboardCharts['{$chartContainerId}']) {
        window.dashboardCharts['{$chartContainerId}'].destroy();
    }
    
    // Initialize chart object storage if not exists
    if (!window.dashboardCharts) {
        window.dashboardCharts = {};
    }
    
    // Detect dark mode based on various theme attributes
    var isDarkMode = document.documentElement.getAttribute('data-theme') === 'dark' || 
                     document.documentElement.getAttribute('data-bs-theme') === 'dark' ||
                     document.body.getAttribute('data-bs-theme') === 'dark' ||
                     document.body.classList.contains('dark-mode') ||
                     document.documentElement.classList.contains('dark-mode');
    
    // Set colors based on theme
    var fontColor = isDarkMode ? '#e1e1e1' : '#555';
    var chartColors = isDarkMode ? 
        ['rgba(77, 207, 89, 0.9)', 'rgba(77, 137, 249, 0.9)'] : 
        ['#4CAF50', '#2196F3'];
    
    // Create chart data with theme colors
    var chartData = {$chartDataJson};
    // Update dataset colors
    if (chartData && chartData.datasets && chartData.datasets[0]) {
        chartData.datasets[0].backgroundColor = chartColors;
    }
    
    // Create new chart
    window.dashboardCharts['{$chartContainerId}'] = new Chart(ctx, {
        type: 'doughnut',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: fontColor,
                        padding: 10,
                        boxWidth: 12,
                        font: {
                            weight: 'bold'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: isDarkMode ? '#1e2430' : '#fff',
                    titleColor: isDarkMode ? '#fff' : '#000',
                    bodyColor: isDarkMode ? '#e1e1e1' : '#333',
                    borderColor: isDarkMode ? 'rgba(255, 255, 255, 0.2)' : 'rgba(0, 0, 0, 0.1)',
                    borderWidth: 1,
                    padding: 10
                }
            }
        }
    });
})();
JS;

    $html .= Html::script($js);

    // Add visitor frequency breakdown
    $visitorFrequency = $this->getVisitorFrequency($visitorsData);

    if (!empty($visitorFrequency)) {
      $html .= '<div class="mt-4">';
      $html .= Html::tag('h5', Yii::t('crelish', 'Visitor Frequency'));

      $html .= '<div class="table-responsive">';
      $html .= '<table class="table table-striped">';
      $html .= '<thead><tr>';
      $html .= Html::tag('th', Yii::t('crelish', 'Visit Count'));
      $html .= Html::tag('th', Yii::t('crelish', 'Visitors'));
      $html .= Html::tag('th', Yii::t('crelish', 'Percentage'));
      $html .= '</tr></thead>';

      $html .= '<tbody>';

      foreach ($visitorFrequency as $count => $visitors) {
        $html .= '<tr>';

        // Visit count label
        $label = $count == 1 ?
          Yii::t('crelish', '1 visit') :
          Yii::t('crelish', '{count} visits', ['count' => $count]);

        $html .= Html::tag('td', $label);

        // Visitor count
        $html .= Html::tag('td', $visitors);

        // Percentage
        $percentage = round(($visitors / $totalVisitors) * 100);
        $html .= Html::tag('td', $percentage . '%');

        $html .= '</tr>';
      }

      $html .= '</tbody>';
      $html .= '</table>';
      $html .= '</div>';
      $html .= '</div>';
    }

    return $html;
  }

  /**
   * Get visitors data
   * @return array
   */
  protected function getVisitorsData()
  {
    // Get the period start date using the analytics component
    $startDate = Yii::$app->crelishAnalytics->getPeriodStartDate($this->period);

    // First, get a list of unique visitors with their first session time
    $subQuery = (new Query())
      ->select([
        'visitor_key' => 'COALESCE(user_id, ip_address)',
        'first_session' => 'MIN(created_at)',
        'latest_session' => 'MAX(created_at)'
      ])
      ->from('analytics_sessions')
      ->where($this->excludeBots ? ['is_bot' => 0] : [])
      ->andFilterWhere(['>=', 'created_at', $this->period !== 'all' ? $startDate : null])
      ->groupBy(['visitor_key']);

    // Now get all unique visitor data with consolidated information
    $query = (new Query())
      ->select([
        'visitor_key' => 'vs.visitor_key',
        'is_new' => new Expression('CASE WHEN DATEDIFF(vs.latest_session, vs.first_session) = 0 THEN 1 ELSE 0 END'),
        'visit_days' => new Expression('DATEDIFF(vs.latest_session, vs.first_session) + 1'),
        'total_pages' => '(SELECT SUM(total_pages) FROM analytics_sessions WHERE ' .
          'COALESCE(user_id, ip_address) = vs.visitor_key AND ' .
          ($this->excludeBots ? 'is_bot = 0 AND ' : '') .
          ($this->period !== 'all' ? 'created_at >= :startDate' : '1=1') . ')',
        'first_date' => 'vs.first_session',
        'latest_date' => 'vs.latest_session'
      ])
      ->from(['vs' => $subQuery]);

    // Bind parameters if needed
    if ($this->period !== 'all') {
      $query->params([':startDate' => $startDate]);
    }

    return $query->all();
  }

  protected function getPeriodStartDate($period)
  {
    switch ($period) {
      case 'day':
        return date('Y-m-d 00:00:00');
      case 'week':
        return date('Y-m-d 00:00:00', strtotime('-7 days'));
      case 'year':
        return date('Y-m-d 00:00:00', strtotime('-365 days'));
      default:
        return date('Y-m-d 00:00:00', strtotime('-30 days'));
    }
  }

  /**
   * Count new visitors (visitors with only one session)
   * @param array $visitorsData
   * @return int
   */
  protected function countNewVisitors($visitorsData)
  {
    // New visitors are those who have is_new = 1
    $newVisitors = 0;

    foreach ($visitorsData as $visitor) {
      if ($visitor['is_new'] == 1) {
        $newVisitors++;
      }
    }

    return $newVisitors;
  }

  /**
   * Calculate visitor frequency
   * @param array $visitorsData
   * @return array
   */
  protected function getVisitorFrequency($visitorsData)
  {
    // Count frequency based on visit_days
    $frequency = [];

    foreach ($visitorsData as $visitor) {
      // If visit_days is very large, it might indicate a bot or crawler
      // Cap it at a reasonable maximum
      $visitCount = min((int)$visitor['visit_days'], 10);

      // Group by ranges for higher numbers
      if ($visitCount >= 10) {
        $visitCount = '10+';
      }

      if (!isset($frequency[$visitCount])) {
        $frequency[$visitCount] = 0;
      }

      $frequency[$visitCount]++;
    }

    // Sort by key
    ksort($frequency);

    return $frequency;
  }
}