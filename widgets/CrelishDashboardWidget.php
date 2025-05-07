<?php

namespace giantbits\crelish\widgets;

use Yii;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * Base class for dashboard widgets
 */
class CrelishDashboardWidget extends Widget
{
    /**
     * @var string Widget title
     */
    public $title = '';
    
    /**
     * @var string Widget description
     */
    public $description = '';
    
    /**
     * @var int Widget size (1-12, based on Bootstrap grid)
     */
    public $size = 6;
    
    /**
     * @var array Widget filters
     */
    public $filters = [];
    
    /**
     * @var string Content type filter
     */
    public $contentType = '';
    
    /**
     * @var bool Whether to refresh widget data automatically
     */
    public $autoRefresh = false;
    
    /**
     * @var int Auto refresh interval in seconds
     */
    public $refreshInterval = 60;
    
    /**
     * @var string Dashboard section
     */
    public $section = '';
    
    /**
     * @var int Widget index in section
     */
    public $index = 0;
    
    /**
     * Initialize the widget
     */
    public function init()
    {
        parent::init();
        
        // Register base assets
        // Custom initialization logic
    }
    
    /**
     * Run the widget
     * @return string
     */
    public function run()
    {
        // Check if Twig renderer is available
        if (isset(Yii::$app->view->renderers['twig'])) {
            // Render using Twig
            return $this->renderTwig();
        } else {
            // Fallback to PHP rendering
            return $this->renderPhp();
        }
    }
    
    /**
     * Render widget using Twig
     * @return string
     */
    protected function renderTwig()
    {
        return Yii::$app->view->renderFile(
            '@giantbits/crelish/views/widgets/dashboard-widget.twig',
            [
                'id' => $this->id,
                'title' => $this->title,
                'description' => $this->description,
                'contentId' => $this->id . '-content',
                'content' => $this->renderContent(),
                'filters' => $this->renderFilters(),
                'autoRefresh' => $this->autoRefresh,
                'refreshInterval' => $this->refreshInterval,
                'section' => $this->section,
                'index' => $this->index
            ]
        );
    }
    
    /**
     * Render widget using PHP (fallback)
     * @return string
     */
    protected function renderPhp()
    {
        $content = $this->renderContent();
        $filters = $this->renderFilters();
        
        $html = <<<HTML
<div class="dashboard-widget card mb-4" 
     data-widget-id="{$this->id}" 
     data-section="{$this->section}"
     data-index="{$this->index}"
     data-auto-refresh="{$this->autoRefresh}" 
     data-refresh-interval="{$this->refreshInterval}">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">{$this->title}</h3>
        <div class="widget-controls">
HTML;

        if (!empty($filters)) {
            $html .= <<<HTML
            <button type="button" class="widget-filter-toggle"
                    data-bs-toggle="collapse" data-bs-target="#{$this->id}-filters">
                <i class="fa-sharp fa-regular fa-filter"></i>
            </button>
HTML;
        }

        $html .= <<<HTML
            <button type="button" class="widget-refresh">
                <i class="fa-sharp fa-regular fa-arrows-rotate"></i>
            </button>
            <button type="button" class="widget-remove">
                <i class="fa-sharp fa-regular fa-xmark"></i>
            </button>
        </div>
    </div>
HTML;

        if (!empty($filters)) {
            $html .= <<<HTML
    <div id="{$this->id}-filters" class="widget-filters collapse">
        <div class="card-body border-bottom">
            <form class="widget-filter-form" data-widget-id="{$this->id}">
                {$filters}
                <button type="submit" class="btn btn-primary btn-sm mt-2">
                    Apply Filters
                </button>
            </form>
        </div>
    </div>
HTML;
        }

        $html .= <<<HTML
    <div class="card-body widget-content">
        {$content}
    </div>
HTML;

        if (!empty($this->description)) {
            $html .= <<<HTML
    <div class="card-footer text-muted">
        <small>{$this->description}</small>
    </div>
HTML;
        }

        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render widget content
     * @return string
     */
    protected function renderContent()
    {
        return Html::tag('div', Yii::t('crelish', 'Widget content not implemented'), ['class' => 'alert alert-warning']);
    }
    
    /**
     * Render widget filters
     * @return string
     */
    protected function renderFilters()
    {
        $html = '';
        
        foreach ($this->filters as $key => $filter) {
            $type = isset($filter['type']) ? $filter['type'] : 'text';
            $label = isset($filter['label']) ? $filter['label'] : ucfirst($key);
            $value = isset($filter['value']) ? $filter['value'] : '';
            $options = isset($filter['options']) ? $filter['options'] : [];
            
            $html .= '<div class="mb-3">';
            $html .= Html::label($label, "filter-{$key}", ['class' => 'form-label']);
            
            switch ($type) {
                case 'select':
                    $html .= Html::dropDownList(
                        $key,
                        $value,
                        $options,
                        ['class' => 'form-select', 'id' => "filter-{$key}"]
                    );
                    break;
                    
                case 'checkbox':
                    $html .= '<div class="form-check">';
                    $html .= Html::checkbox(
                        $key,
                        (bool)$value,
                        [
                            'class' => 'form-check-input',
                            'id' => "filter-{$key}"
                        ]
                    );
                    $html .= '</div>';
                    break;
                    
                case 'date':
                    $html .= Html::input(
                        'date',
                        $key,
                        $value,
                        ['class' => 'form-control', 'id' => "filter-{$key}"]
                    );
                    break;
                    
                default:
                    $html .= Html::textInput(
                        $key,
                        $value,
                        ['class' => 'form-control', 'id' => "filter-{$key}"]
                    );
            }
            
            $html .= '</div>';
        }
        
        return $html;
    }
    
    /**
     * Get widget data for AJAX refresh
     * @return array
     */
    public function getWidgetData()
    {
        return [
            'content' => $this->renderContent()
        ];
    }
    
    /**
     * Get period start date based on period string
     * @param string $period Period string (day, week, month, year)
     * @return string SQL-compatible date string
     */
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
}