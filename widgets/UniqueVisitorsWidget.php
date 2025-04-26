<?php

namespace giantbits\crelish\widgets;

use Yii;
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
    public $period = 'month';
    
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
        $returningPercentage = $totalVisitors > 0 ? round(($returningVisitors / $totalVisitors) * 100) : 0;
        
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
        $query = (new Query())
            ->select(['session_id', 'user_id', 'ip_address', 'created_at', 'total_pages'])
            ->from('analytics_sessions');
            
        // Apply common filters
        if ($this->excludeBots) {
            $query->where(['is_bot' => 0]);
        }
        
        if ($this->period !== 'all') {
            $query->andWhere(['>=', 'created_at', $this->getPeriodStartDate($this->period)]);
        }
        
        // Group by either user ID (if logged in) or session ID
        $query->orderBy(['created_at' => SORT_DESC]);
        
        return $query->all();
    }
    
    /**
     * Count new visitors (visitors with only one session)
     * @param array $visitorsData
     * @return int
     */
    protected function countNewVisitors($visitorsData)
    {
        // Group visitors by user ID or IP address
        $visitorGroups = [];
        
        foreach ($visitorsData as $visitor) {
            $key = !empty($visitor['user_id']) ? 
                'user_' . $visitor['user_id'] : 
                'ip_' . $visitor['ip_address'];
                
            if (!isset($visitorGroups[$key])) {
                $visitorGroups[$key] = [];
            }
            
            $visitorGroups[$key][] = $visitor;
        }
        
        // Count visitors with only one session
        $newVisitors = 0;
        
        foreach ($visitorGroups as $sessions) {
            if (count($sessions) === 1) {
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
        // Group visitors by user ID or IP address
        $visitorGroups = [];
        
        foreach ($visitorsData as $visitor) {
            $key = !empty($visitor['user_id']) ? 
                'user_' . $visitor['user_id'] : 
                'ip_' . $visitor['ip_address'];
                
            if (!isset($visitorGroups[$key])) {
                $visitorGroups[$key] = [
                    'sessions' => 0,
                    'pages' => 0
                ];
            }
            
            $visitorGroups[$key]['sessions']++;
            $visitorGroups[$key]['pages'] += (int)$visitor['total_pages'];
        }
        
        // Count frequency
        $frequency = [];
        
        foreach ($visitorGroups as $visitor) {
            $sessionCount = $visitor['sessions'];
            
            // Group by ranges for higher numbers
            if ($sessionCount >= 10) {
                $sessionCount = '10+';
            }
            
            if (!isset($frequency[$sessionCount])) {
                $frequency[$sessionCount] = 0;
            }
            
            $frequency[$sessionCount]++;
        }
        
        // Sort by key
        ksort($frequency);
        
        return $frequency;
    }
}