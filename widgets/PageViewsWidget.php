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
        
        // Add unique visitors filter
        $this->filters['uniqueVisitors'] = [
            'type' => 'checkbox',
            'label' => Yii::t('crelish', 'Show Unique Visitors Only'),
            'value' => $this->uniqueVisitors
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
        // Get view data
        $viewData = $this->getViewData();
        
        // Convert data to JSON for chart
        $labels = [];
        $values = [];
        
        foreach ($viewData as $item) {
            $labels[] = $item['date'];
            $values[] = (int)$item['views'];
        }
        
        $chartLabel = $this->uniqueVisitors ? 
            Yii::t('crelish', 'Unique Visitors') : 
            Yii::t('crelish', 'Page Views');
            
        $chartData = [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $chartLabel,
                    'data' => $values,
                    'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                    'borderColor' => 'rgba(54, 162, 235, 1)',
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
    var backgroundColor = isDarkMode ? 'rgba(77, 137, 249, 0.2)' : 'rgba(54, 162, 235, 0.2)';
    var borderColor = isDarkMode ? 'rgba(77, 137, 249, 1)' : 'rgba(54, 162, 235, 1)';
    
    // Create chart data with theme colors
    var chartData = {$chartDataJson};
    // Update dataset colors
    if (chartData && chartData.datasets && chartData.datasets[0]) {
        chartData.datasets[0].backgroundColor = backgroundColor;
        chartData.datasets[0].borderColor = borderColor;
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
        
        // Add summary statistics
        $totalViews = 0;
        foreach ($viewData as $item) {
            $totalViews += (int)$item['views'];
        }
        
        // Calculate average views per day
        $avgViews = count($viewData) > 0 ? round($totalViews / count($viewData)) : 0;
            
        $viewTypeText = $this->uniqueVisitors ? 
            Yii::t('crelish', 'Unique Visitors') : 
            Yii::t('crelish', 'Total Views');
            
        $avgViewTypeText = $this->uniqueVisitors ? 
            Yii::t('crelish', 'Average Visitors Per Day') : 
            Yii::t('crelish', 'Average Views Per Day');
        
        $html .= '<div class="row mt-3">';
        $html .= '<div class="col-md-6">';
        $html .= '<div class="summary-stat">';
        $html .= Html::tag('h4', $viewTypeText);
        $html .= Html::tag('div', Yii::$app->formatter->asInteger($totalViews), ['class' => 'stat-value']);
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '<div class="col-md-6">';
        $html .= '<div class="summary-stat">';
        $html .= Html::tag('h4', $avgViewTypeText);
        $html .= Html::tag('div', Yii::$app->formatter->asInteger($avgViews), ['class' => 'stat-value']);
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
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