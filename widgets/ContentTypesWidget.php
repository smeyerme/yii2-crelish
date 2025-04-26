<?php

namespace giantbits\crelish\widgets;

use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\db\Query;

/**
 * Widget that shows content type statistics
 */
class ContentTypesWidget extends CrelishDashboardWidget
{
    /**
     * @var bool Whether to include unpublished content
     */
    public $includeUnpublished = false;
    
    /**
     * Initialize the widget
     */
    public function init()
    {
        parent::init();
        
        if (empty($this->title)) {
            $this->title = Yii::t('crelish', 'Content Types');
        }
        
        $this->description = Yii::t('crelish', 'Shows statistics about content types');
        
        // Add include unpublished filter
        $this->filters['includeUnpublished'] = [
            'type' => 'checkbox',
            'label' => Yii::t('crelish', 'Include Unpublished Content'),
            'value' => $this->includeUnpublished
        ];
    }
    
    /**
     * Render widget content
     * @return string
     */
    protected function renderContent()
    {
        // Get content type data
        $contentTypes = $this->getContentTypesData();
        
        if (empty($contentTypes)) {
            return Html::tag('div', 
                Yii::t('crelish', 'No content type data available'), 
                ['class' => 'alert alert-info']
            );
        }
        
        // Create chart
        $chartContainerId = $this->id . '-chart';
        
        $html = Html::tag('div', Html::tag('canvas', '', ['id' => $chartContainerId]), 
            ['class' => 'chart-container', 'style' => 'height: 200px;']);
        
        // Prepare chart data
        $labels = [];
        $counts = [];
        
        foreach ($contentTypes as $type) {
            $labels[] = ucfirst($type['type']);
            $counts[] = (int)$type['count'];
        }
        
        $chartData = [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => Yii::t('crelish', 'Content Items'),
                    'data' => $counts,
                    'backgroundColor' => [
                        '#4CAF50', '#2196F3', '#FFC107', '#9C27B0', 
                        '#F44336', '#00BCD4', '#3F51B5', '#FF9800', 
                        '#009688', '#673AB7', '#E91E63', '#CDDC39'
                    ],
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
    
    // Detect dark mode based on data-theme attribute
    var isDarkMode = document.documentElement.getAttribute('data-theme') === 'dark';
    
    // Set colors based on theme
    var fontColor = isDarkMode ? '#fff' : '#666';
    
    // Create new chart
    window.dashboardCharts['{$chartContainerId}'] = new Chart(ctx, {
        type: 'pie',
        data: {$chartDataJson},
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        color: fontColor,
                        padding: 10,
                        boxWidth: 12
                    }
                },
                tooltip: {
                    backgroundColor: isDarkMode ? '#151e2d' : '#fff',
                    titleColor: isDarkMode ? '#fff' : '#000',
                    bodyColor: isDarkMode ? '#fff' : '#000',
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
        
        // Add table with content type data
        $html .= '<div class="table-responsive mt-3">';
        $html .= '<table class="table table-striped">';
        $html .= '<thead><tr>';
        $html .= Html::tag('th', Yii::t('crelish', 'Content Type'));
        $html .= Html::tag('th', Yii::t('crelish', 'Count'));
        $html .= Html::tag('th', Yii::t('crelish', 'Actions'));
        $html .= '</tr></thead>';
        
        $html .= '<tbody>';
        
        foreach ($contentTypes as $type) {
            $html .= '<tr>';
            $html .= Html::tag('td', ucfirst($type['type']));
            $html .= Html::tag('td', $type['count']);
            
            // Actions
            $html .= '<td>';
            
            // View content button
            $url = Url::to(['/crelish/content/index', 'type' => $type['type']]);
            $html .= Html::a(
                '<i class="fa fa-eye"></i> ' . Yii::t('crelish', 'View'),
                $url,
                ['class' => 'btn btn-sm btn-outline-primary', 'target' => '_blank']
            );
            
            $html .= '</td>';
            
            $html .= '</tr>';
        }
        
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get content types data
     * @return array
     */
    protected function getContentTypesData()
    {
        $contentTypes = [];
        
        // Get content types from config
        $contentTypesDir = Yii::getAlias('@app/workspace/types');
        
        if (is_dir($contentTypesDir)) {
            $files = scandir($contentTypesDir);
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
                    $type = pathinfo($file, PATHINFO_FILENAME);
                    
                    // Count items of this type
                    $query = new Query();
                    $query->from('workspace_' . $type);
                    
                    // Filter by published status if needed
                    if (!$this->includeUnpublished) {
                        $query->where(['published' => 1]);
                    }
                    
                    $count = $query->count();
                    
                    $contentTypes[] = [
                        'type' => $type,
                        'count' => $count
                    ];
                }
            }
        }
        
        // Sort by count, descending
        usort($contentTypes, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return $contentTypes;
    }
}