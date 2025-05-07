<?php

namespace giantbits\crelish\widgets;

use Yii;
use yii\helpers\Html;
use yii\helpers\Json;

/**
 * Widget that shows page views over time
 */
class PageViewsWidget extends CrelishDashboardWidget
{
    /**
     * @var string Period filter
     */
    public $period = 'month';
    
    /**
     * @var bool Whether to exclude bot traffic
     */
    public $excludeBots = true;
    
    /**
     * @var bool Whether to show only unique visitors
     */
    public $uniqueVisitors = false;
    
    /**
     * Initialize the widget
     */
    public function init()
    {
        parent::init();
        
        if (empty($this->title)) {
            $this->title = Yii::t('crelish', 'Page Views Over Time');
        }
        
        $this->description = Yii::t('crelish', 'Shows page view statistics over time');
        
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
  /**
   * Render widget content
   * @return string
   */
  protected function renderContent()
  {
    // Get view data for both total views and unique visitors
    $totalViewData = Yii::$app->crelishAnalytics->getPageViewStats(
      $this->period,
      $this->excludeBots,
      false // Total views
    );

    $uniqueVisitorData = Yii::$app->crelishAnalytics->getPageViewStats(
      $this->period,
      $this->excludeBots,
      true // Unique visitors
    );

    // Organize data by date for comparison
    $organizedData = [];

    // Process total views data
    foreach ($totalViewData as $item) {
      if (!isset($organizedData[$item['date']])) {
        $organizedData[$item['date']] = [
          'date' => $item['date'],
          'totalViews' => 0,
          'uniqueVisitors' => 0
        ];
      }
      $organizedData[$item['date']]['totalViews'] = (int)$item['views'];
    }

    // Process unique visitors data
    foreach ($uniqueVisitorData as $item) {
      if (!isset($organizedData[$item['date']])) {
        $organizedData[$item['date']] = [
          'date' => $item['date'],
          'totalViews' => 0,
          'uniqueVisitors' => 0
        ];
      }
      $organizedData[$item['date']]['uniqueVisitors'] = (int)$item['views'];
    }

    // Sort by date
    ksort($organizedData);

    // Prepare data for chart
    $labels = [];
    $totalViewValues = [];
    $uniqueVisitorValues = [];

    foreach ($organizedData as $date => $data) {
      $labels[] = $date;
      $totalViewValues[] = $data['totalViews'];
      $uniqueVisitorValues[] = $data['uniqueVisitors'];
    }

    // Create chart data
    $chartData = [
      'labels' => $labels,
      'datasets' => [
        [
          'label' => Yii::t('crelish', 'Total Page Views'),
          'data' => $totalViewValues,
          'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
          'borderColor' => 'rgba(54, 162, 235, 1)',
          'borderWidth' => 1,
          'tension' => 0.1
        ],
        [
          'label' => Yii::t('crelish', 'Unique Visitors'),
          'data' => $uniqueVisitorValues,
          'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
          'borderColor' => 'rgba(255, 99, 132, 1)',
          'borderWidth' => 1,
          'tension' => 0.1
        ]
      ]
    ];

    // Create chart container
    $chartContainerId = $this->id . '-chart';
    $chartDataJson = Json::encode($chartData);

    $html = Html::tag('div', Html::tag('canvas', '', ['id' => $chartContainerId]),
      ['class' => 'chart-container', 'style' => 'height: 300px;']);

    // Add chart initialization JavaScript
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
    var gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
    
    // Create chart data with theme colors
    var chartData = {$chartDataJson};
    
    // Update colors based on theme
    if (isDarkMode) {
        // Dark mode colors
        if (chartData.datasets[0]) {
            chartData.datasets[0].backgroundColor = 'rgba(77, 137, 249, 0.2)';
            chartData.datasets[0].borderColor = 'rgba(77, 137, 249, 1)';
        }
        if (chartData.datasets[1]) {
            chartData.datasets[1].backgroundColor = 'rgba(255, 107, 129, 0.2)';
            chartData.datasets[1].borderColor = 'rgba(255, 107, 129, 1)';
        }
    }
    
    // Create new chart
    window.dashboardCharts['{$chartContainerId}'] = new Chart(ctx, {
        type: 'line',
        data: chartData,
        options: {
            maintainAspectRatio: false,
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: gridColor
                    },
                    ticks: {
                        color: fontColor
                    }
                },
                x: {
                    grid: {
                        color: gridColor
                    },
                    ticks: {
                        color: fontColor
                    }
                }
            },
            plugins: {
                legend: {
                    labels: {
                        color: fontColor,
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

    // Calculate summary statistics
    $totalViewsSum = array_sum($totalViewValues);
    $uniqueVisitorsSum = array_sum($uniqueVisitorValues);

    // Calculate average views per day
    $days = count($labels);
    $avgTotalViews = $days > 0 ? round($totalViewsSum / $days) : 0;
    $avgUniqueVisitors = $days > 0 ? round($uniqueVisitorsSum / $days) : 0;

    // Add summary statistics
    $html .= '<div class="row mt-3">';

    // Total views stats
    $html .= '<div class="col-md-3">';
    $html .= '<div class="summary-stat">';
    $html .= Html::tag('h4', Yii::t('crelish', 'Total Views'));
    $html .= Html::tag('div', Yii::$app->formatter->asInteger($totalViewsSum), ['class' => 'stat-value']);
    $html .= '</div>';
    $html .= '</div>';

    // Average views per day
    $html .= '<div class="col-md-3">';
    $html .= '<div class="summary-stat">';
    $html .= Html::tag('h4', Yii::t('crelish', 'Average Views Per Day'));
    $html .= Html::tag('div', Yii::$app->formatter->asInteger($avgTotalViews), ['class' => 'stat-value']);
    $html .= '</div>';
    $html .= '</div>';

    // Unique visitors stats
    $html .= '<div class="col-md-3">';
    $html .= '<div class="summary-stat">';
    $html .= Html::tag('h4', Yii::t('crelish', 'Unique Visitors'));
    $html .= Html::tag('div', Yii::$app->formatter->asInteger($uniqueVisitorsSum), ['class' => 'stat-value']);
    $html .= '</div>';
    $html .= '</div>';

    // Average unique visitors per day
    $html .= '<div class="col-md-3">';
    $html .= '<div class="summary-stat">';
    $html .= Html::tag('h4', Yii::t('crelish', 'Average Unique Visitors Per Day'));
    $html .= Html::tag('div', Yii::$app->formatter->asInteger($avgUniqueVisitors), ['class' => 'stat-value']);
    $html .= '</div>';
    $html .= '</div>';

    $html .= '</div>'; // end row

    return $html;
  }
    
    /**
     * Get view data from database
     * @return array
     */
    protected function getViewData()
    {
        // Use analytics component to get data
        return Yii::$app->crelishAnalytics->getPageViewStats(
            $this->period, 
            $this->excludeBots, 
            $this->uniqueVisitors
        );
    }
}