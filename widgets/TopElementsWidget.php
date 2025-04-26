<?php

namespace giantbits\crelish\widgets;

use Yii;
use yii\helpers\Html;
use yii\db\Query;
use yii\helpers\Url;

/**
 * Widget that shows top content elements
 */
class TopElementsWidget extends CrelishDashboardWidget
{
    /**
     * @var string Period filter
     */
    public $period = 'month';
    
    /**
     * @var string Element type filter
     */
    public $elementType = '';
    
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
            $this->title = Yii::t('crelish', 'Top Content Elements');
        }
        
        $this->description = Yii::t('crelish', 'Shows most viewed content elements');
        
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
        
        // Add element type filter
        $this->filters['elementType'] = [
            'type' => 'select',
            'label' => Yii::t('crelish', 'Element Type'),
            'value' => $this->elementType,
            'options' => $this->getElementTypeOptions()
        ];
        
        // Add limit filter
        $this->filters['limit'] = [
            'type' => 'select',
            'label' => Yii::t('crelish', 'Results Limit'),
            'value' => $this->limit,
            'options' => [
                5 => '5',
                10 => '10',
                20 => '20',
                50 => '50',
                100 => '100'
            ]
        ];
    }
    
    /**
     * Get element type options for filter
     * @return array
     */
    protected function getElementTypeOptions()
    {
        $elementTypes = (new Query())
            ->select(['element_type'])
            ->from('analytics_element_views')
            ->groupBy(['element_type'])
            ->all();
            
        $options = ['' => Yii::t('crelish', 'All Types')];
        
        foreach ($elementTypes as $type) {
            $options[$type['element_type']] = ucfirst($type['element_type']);
        }
        
        $options['download'] = Yii::t('crelish', 'Downloads');
        $options['list'] = Yii::t('crelish', 'List Views');
        $options['detail'] = Yii::t('crelish', 'Detail Views');
        
        return $options;
    }
    
    /**
     * Render widget content
     * @return string
     */
    protected function renderContent()
    {
        // Get top elements data
        $data = $this->getTopElementsData();
        
        if (empty($data)) {
            return Html::tag('div', 
                Yii::t('crelish', 'No data available for the selected filters'), 
                ['class' => 'alert alert-info']
            );
        }
        
        // Start HTML output
        $html = '<div class="table-responsive">';
        $html .= '<table class="table table-striped">';
        
        // Create table header
        $html .= '<thead><tr>';
        $html .= Html::tag('th', Yii::t('crelish', 'Element'));
        $html .= Html::tag('th', Yii::t('crelish', 'Type'));
        
        // Add file type column for downloads
        if ($this->elementType === 'download') {
            $html .= Html::tag('th', Yii::t('crelish', 'File Type'));
        }
        
        // Add view type column if showing all types
        if (empty($this->elementType)) {
            $html .= Html::tag('th', Yii::t('crelish', 'View Type'));
        }
        
        $html .= Html::tag('th', Yii::t('crelish', 'Views'));
        $html .= '</tr></thead>';
        
        // Create table body
        $html .= '<tbody>';
        
        foreach ($data as $element) {
            $html .= '<tr>';
            
            // Element title/ID
            $title = isset($element['title']) ? $element['title'] : $element['element_uuid'];
            $html .= Html::tag('td', Html::encode($title));
            
            // Element type
            $html .= Html::tag('td', Html::encode(ucfirst($element['element_type'])));
            
            // File type column for downloads
            if ($this->elementType === 'download') {
                $fileType = isset($element['file_type']) ? $element['file_type'] : 'Unknown';
                $html .= Html::tag('td', Html::encode($fileType));
            }
            
            // View type column if showing all types
            if (empty($this->elementType)) {
                $viewType = isset($element['view_type']) ? $element['view_type'] : 'view';
                $html .= Html::tag('td', Html::encode(ucfirst($viewType)));
            }
            
            // Views count
            $html .= Html::tag('td', Html::encode($element['views']));
            
            $html .= '</tr>';
        }
        
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get top elements data
     * @return array
     */
    protected function getTopElementsData()
    {
        $params = [
            'period' => $this->period,
            'limit' => $this->limit
        ];
        
        // Only add type parameter if specified
        if (!empty($this->elementType)) {
            $params['type'] = $this->elementType;
        }
        
        // Use analytics component to get data
        $data = Yii::$app->crelishAnalytics->getTopElements(
            $params['period'],
            $params['limit'],
            isset($params['type']) ? $params['type'] : null
        );
        
        // Enrich data with titles
        foreach ($data as &$element) {
            // For assets (especially downloads)
            if ($element['element_type'] === 'asset') {
                try {
                    $assetModel = \app\workspace\models\Asset::findOne($element['element_uuid']);
                    if ($assetModel) {
                        $element['title'] = $assetModel->title ?? $assetModel->fileName ?? ('Asset: ' . $element['element_uuid']);
                        $element['file_type'] = $assetModel->mime ?? 'Unknown';
                        $element['file_size'] = $assetModel->size ?? 0;
                    } else {
                        $element['title'] = 'Asset: ' . $element['element_uuid'];
                    }
                } catch (\Exception $e) {
                    $element['title'] = 'Asset: ' . $element['element_uuid'];
                }
            } else {
                // Try to get element title from database based on type
                try {
                    $modelClass = 'app\workspace\models\\' . ucfirst($element['element_type']);
                    if (class_exists($modelClass)) {
                        $elementModel = call_user_func($modelClass . '::find')
                            ->where(['uuid' => $element['element_uuid']])
                            ->one();

                        if ($elementModel && isset($elementModel['systitle'])) {
                            $element['title'] = $elementModel['systitle'];
                        } else {
                            $element['title'] = 'Element: ' . $element['element_uuid'];
                        }
                    } else {
                        $element['title'] = ucfirst($element['element_type']) . ': ' . $element['element_uuid'];
                    }
                } catch (\Exception $e) {
                    $element['title'] = ucfirst($element['element_type']) . ': ' . $element['element_uuid'];
                }
            }
            
            // Add type info for display in the UI
            $element['view_type'] = $element['type'] ?? 'view';
        }
        
        return $data;
    }
}