<?php

namespace giantbits\crelish\widgets;

use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\db\Query;

/**
 * Widget that shows performance of content by type
 */
class ContentPerformanceWidget extends CrelishDashboardWidget
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
     * @var int Limit for results
     */
    public $limit = 10;
    
    /**
     * Initialize the widget
     */
    public function init()
    {
        parent::init();
        
        if (empty($this->title)) {
            $this->title = Yii::t('crelish', 'Content Performance');
        }
        
        $this->description = Yii::t('crelish', 'Performance metrics for content by type');
        
        // Add content type filter
        $this->filters['contentType'] = [
            'type' => 'select',
            'label' => Yii::t('crelish', 'Content Type'),
            'value' => $this->contentType,
            'options' => $this->getContentTypeOptions()
        ];
        
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
     * Get content type options for filter
     * @return array
     */
    protected function getContentTypeOptions()
    {
        $contentTypes = (new Query())
            ->select(['page_type'])
            ->from('analytics_page_views')
            ->groupBy(['page_type'])
            ->all();
            
        $options = ['' => Yii::t('crelish', 'All Content Types')];
        
        foreach ($contentTypes as $type) {
            $options[$type['page_type']] = ucfirst($type['page_type']);
        }
        
        return $options;
    }
    
    /**
     * Render widget content
     * @return string
     */
    protected function renderContent()
    {
        // Get performance data based on filters
        $performanceData = $this->getPerformanceData();
        
        if (empty($performanceData)) {
            return Html::tag('div', 
                Yii::t('crelish', 'No performance data available for the selected filters'), 
                ['class' => 'alert alert-info']
            );
        }
        
        // Prepare data for output
        $html = '';
        
        if (empty($this->contentType)) {
            // Content Type Overview
            $html .= $this->renderContentTypeOverview($performanceData);
        } else {
            // Specific Content Type View
            $html .= $this->renderContentTypeDetail($performanceData);
        }
        
        return $html;
    }
    
    /**
     * Render content type overview
     * @param array $data Performance data
     * @return string HTML content
     */
    protected function renderContentTypeOverview($data)
    {
        // Prepare chart data
        $chartLabels = [];
        $chartValues = [];
        
        foreach ($data as $item) {
            $chartLabels[] = ucfirst($item['page_type']);
            $chartValues[] = (int)$item['views'];
        }
        
        // Create chart configuration
        $chartData = [
            'labels' => $chartLabels,
            'datasets' => [
                [
                    'label' => $this->uniqueVisitors ? 
                        Yii::t('crelish', 'Unique Visitors') : 
                        Yii::t('crelish', 'Views'),
                    'data' => $chartValues,
                    'backgroundColor' => 'rgba(54, 162, 235, 0.5)',
                    'borderColor' => 'rgba(54, 162, 235, 1)',
                    'borderWidth' => 1
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
    var backgroundColor = isDarkMode ? 'rgba(77, 137, 249, 0.4)' : 'rgba(54, 162, 235, 0.5)';
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
        type: 'bar',
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
        
        // Add table with data
        $html .= '<div class="table-responsive mt-3">';
        $html .= '<table class="table table-striped">';
        $html .= '<thead><tr>';
        $html .= Html::tag('th', Yii::t('crelish', 'Content Type'));
        $html .= Html::tag('th', $this->uniqueVisitors ? 
            Yii::t('crelish', 'Unique Visitors') : 
            Yii::t('crelish', 'Views'));
        $html .= '</tr></thead>';
        
        $html .= '<tbody>';
        
        foreach ($data as $item) {
            $html .= '<tr>';
            $html .= Html::tag('td', ucfirst($item['page_type']));
            $html .= Html::tag('td', $item['views']);
            $html .= '</tr>';
        }
        
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render content type detail
     * @param array $data Performance data
     * @return string HTML content
     */
    protected function renderContentTypeDetail($data)
    {
        // Add content type header
        $html = Html::tag('h4', ucfirst($this->contentType));
        
        // Prepare chart data
        $chartLabels = [];
        $chartValues = [];
        
        // Take top 10 items for chart
        $chartData = array_slice($data, 0, 10);
        
        foreach ($chartData as $item) {
            $chartLabels[] = $item['title'];
            $chartValues[] = (int)$item['views'];
        }
        
        // Create chart configuration
        $chartConfig = [
            'labels' => $chartLabels,
            'datasets' => [
                [
                    'label' => $this->uniqueVisitors ? 
                        Yii::t('crelish', 'Unique Visitors') : 
                        Yii::t('crelish', 'Views'),
                    'data' => $chartValues,
                    'backgroundColor' => 'rgba(54, 162, 235, 0.5)',
                    'borderColor' => 'rgba(54, 162, 235, 1)',
                    'borderWidth' => 1
                ]
            ]
        ];
        
        // Create chart container
        $chartContainerId = $this->id . '-chart';
        $chartDataJson = Json::encode($chartConfig);
        
        $html .= Html::tag('div', Html::tag('canvas', '', ['id' => $chartContainerId]), 
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
    var backgroundColor = isDarkMode ? 'rgba(77, 137, 249, 0.4)' : 'rgba(54, 162, 235, 0.5)';
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
        type: 'bar',
        data: chartData,
        options: {
            maintainAspectRatio: false,
            responsive: true,
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true,
                    grid: {
                        color: gridColor
                    },
                    ticks: {
                        color: fontColor
                    }
                },
                y: {
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
        
        // Add table with data
        $html .= '<div class="table-responsive mt-3">';
        $html .= '<table class="table table-striped">';
        $html .= '<thead><tr>';
        $html .= Html::tag('th', Yii::t('crelish', 'Title'));
        $html .= Html::tag('th', $this->uniqueVisitors ? 
            Yii::t('crelish', 'Unique Visitors') : 
            Yii::t('crelish', 'Views'));
        $html .= '</tr></thead>';
        
        $html .= '<tbody>';
        
        foreach ($data as $item) {
            $html .= '<tr>';
            $html .= Html::tag('td', $item['title']);
            $html .= Html::tag('td', $item['views']);
            $html .= '</tr>';
        }
        
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get performance data
     * @return array
     */
    protected function getPerformanceData()
    {
        // Get raw data based on content type filter
        if (empty($this->contentType)) {
            // Get data for all content types
            $query = (new Query())
                ->select([
                    'page_type',
                    'views' => $this->uniqueVisitors ? 
                        'COUNT(DISTINCT CONCAT(ip_address, "-", session_id))' : 
                        'COUNT(*)'
                ])
                ->from('analytics_page_views')
                ->groupBy(['page_type'])
                ->orderBy(['views' => SORT_DESC]);
        } else {
            // Get data for specific content type
            $query = (new Query())
                ->select([
                    'page_uuid',
                    'views' => $this->uniqueVisitors ? 
                        'COUNT(DISTINCT CONCAT(ip_address, "-", session_id))' : 
                        'COUNT(*)'
                ])
                ->from('analytics_page_views')
                ->where(['page_type' => $this->contentType])
                ->groupBy(['page_uuid'])
                ->orderBy(['views' => SORT_DESC])
                ->limit($this->limit);
        }
        
        // Apply common filters
        if ($this->excludeBots) {
            $query->andWhere(['is_bot' => 0]);
        }
        
        if ($this->period !== 'all') {
            $query->andWhere(['>=', 'created_at', $this->getPeriodStartDate($this->period)]);
        }
        
        $result = $query->all();
        
        // Enrich with additional data if needed
        if (!empty($this->contentType)) {
            foreach ($result as &$item) {
                try {
                    $modelClass = 'app\workspace\models\\' . ucfirst($this->contentType);
                    if (class_exists($modelClass)) {
                        $contentModel = call_user_func($modelClass . '::find')
                            ->where(['uuid' => $item['page_uuid']])
                            ->one();
                            
                        if ($contentModel && isset($contentModel['systitle'])) {
                            $item['title'] = $contentModel['systitle'];
                        } else {
                            $item['title'] = 'Unknown: ' . $item['page_uuid'];
                        }
                    } else {
                        $item['title'] = 'Unknown: ' . $item['page_uuid'];
                    }
                } catch (\Exception $e) {
                    $item['title'] = 'Unknown: ' . $item['page_uuid'];
                }
            }
        }
        
        return $result;
    }
}