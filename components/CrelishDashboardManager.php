<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\Component;
use yii\helpers\Json;

/**
 * Dashboard manager for analytics dashboard
 * Handles widget registration, configuration and rendering
 */
class CrelishDashboardManager extends Component
{
    /**
     * @var array Dashboard widgets configuration
     */
    public $widgets = [];
    
    /**
     * @var array Available widget types
     */
    private $_availableWidgets = [];
    
    /**
     * @var array Widget instances
     */
    private $_instances = [];

    /**
     * Initialize the component
     */
    public function init()
    {
        parent::init();
        
        // Load dashboard configuration
        $configFile = Yii::getAlias('@giantbits/crelish/config/dashboard.json');
        if (file_exists($configFile)) {
            $this->widgets = Json::decode(file_get_contents($configFile));
        } else {
            // Create default configuration if not exists
            $this->widgets = $this->getDefaultConfig();
            $this->saveConfiguration();
        }
        
        // Register default widget types
        $this->registerWidgetType('pageviews', 'giantbits\crelish\widgets\PageViewsWidget');
        $this->registerWidgetType('uniquevisitors', 'giantbits\crelish\widgets\UniqueVisitorsWidget');
        $this->registerWidgetType('topelements', 'giantbits\crelish\widgets\TopElementsWidget');
        $this->registerWidgetType('contenttypes', 'giantbits\crelish\widgets\ContentTypesWidget');
        $this->registerWidgetType('userjourney', 'giantbits\crelish\widgets\UserJourneyWidget');
        $this->registerWidgetType('contentperformance', 'giantbits\crelish\widgets\ContentPerformanceWidget');
    }
    
    /**
     * Register a widget type
     * @param string $type Widget type identifier
     * @param string $class Widget class
     */
    public function registerWidgetType($type, $class)
    {
        $this->_availableWidgets[$type] = $class;
    }
    
    /**
     * Get dashboard widgets for a specific section
     * @param string $section Dashboard section ('top', 'left', 'right', 'bottom')
     * @return array
     */
    public function getWidgets($section)
    {
        $results = [];
        
        if (isset($this->widgets[$section]) && is_array($this->widgets[$section])) {
            foreach ($this->widgets[$section] as $index => $widgetConfig) {
                $widget = $this->createWidget($widgetConfig, $section, $index);
                if ($widget) {
                    $results[] = $widget;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Create a widget instance from configuration
     * @param array $config Widget configuration
     * @param string $section Dashboard section
     * @param int $index Widget index in section
     * @return \yii\base\Widget|null
     */
    protected function createWidget($config, $section, $index)
    {
        if (!isset($config['type']) || !isset($this->_availableWidgets[$config['type']])) {
            return null;
        }
        
        $class = $this->_availableWidgets[$config['type']];
        $options = isset($config['options']) ? $config['options'] : [];
        
        // Add section and index for reference
        $options['section'] = $section;
        $options['index'] = $index;
        
        return new $class($options);
    }
    
    /**
     * Get all available widget types
     * @return array
     */
    public function getAvailableWidgetTypes()
    {
        return $this->_availableWidgets;
    }
    
    /**
     * Add a new widget to the configuration
     * @param string $section Dashboard section
     * @param string $type Widget type
     * @param array $options Widget options
     * @return bool
     */
    public function addWidget($section, $type, $options = [])
    {
        if (!isset($this->_availableWidgets[$type])) {
            return false;
        }
        
        if (!isset($this->widgets[$section])) {
            $this->widgets[$section] = [];
        }
        
        $this->widgets[$section][] = [
            'type' => $type,
            'options' => $options
        ];
        
        return $this->saveConfiguration();
    }
    
    /**
     * Remove a widget from configuration
     * @param string $section Dashboard section
     * @param int $index Widget index
     * @return bool
     */
    public function removeWidget($section, $index)
    {
        if (!isset($this->widgets[$section]) || !isset($this->widgets[$section][$index])) {
            return false;
        }
        
        unset($this->widgets[$section][$index]);
        $this->widgets[$section] = array_values($this->widgets[$section]);
        
        return $this->saveConfiguration();
    }
    
    /**
     * Get default dashboard configuration
     * @return array
     */
    protected function getDefaultConfig()
    {
        return [
            'top' => [
                [
                    'type' => 'pageviews',
                    'options' => [
                        'size' => 12,
                        'title' => 'Page Views Over Time',
                        'autoRefresh' => true,
                        'refreshInterval' => 300
                    ]
                ]
            ],
            'left' => [
                [
                    'type' => 'uniquevisitors',
                    'options' => [
                        'title' => 'Unique Visitors'
                    ]
                ],
                [
                    'type' => 'topelements',
                    'options' => [
                        'title' => 'Top Content Elements',
                        'limit' => 10
                    ]
                ]
            ],
            'right' => [
                [
                    'type' => 'contentperformance',
                    'options' => [
                        'title' => 'Content Performance by Type'
                    ]
                ]
            ],
            'bottom' => [
                [
                    'type' => 'userjourney',
                    'options' => [
                        'size' => 12,
                        'title' => 'Recent User Journey'
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Save configuration to file
     * @return bool
     */
    protected function saveConfiguration()
    {
        $configFile = Yii::getAlias('@giantbits/crelish/config/dashboard.json');
        $config = Json::encode($this->widgets, JSON_PRETTY_PRINT);
        return file_put_contents($configFile, $config) !== false;
    }
}