<?php
namespace giantbits\crelish\components\strategies;

use Yii;
use giantbits\crelish\components\interfaces\CrelishWidgetInterface;
use yii\helpers\Html;
use yii\helpers\Json;

/**
 * Class JsonStructureRenderStrategy
 * 
 * Rendering strategy for widgets within JsonStructureEditor
 * 
 * @package giantbits\crelish\components\strategies
 */
class JsonStructureRenderStrategy implements WidgetRenderStrategy
{
    /**
     * @var string Unique prefix for this context
     */
    protected $contextPrefix;
    
    /**
     * @var array Path segments for nested fields
     */
    protected $fieldPath;

    /**
     * Constructor
     * 
     * @param string $contextPrefix
     * @param array $fieldPath
     */
    public function __construct($contextPrefix = '', $fieldPath = [])
    {
        $this->contextPrefix = $contextPrefix;
        $this->fieldPath = $fieldPath;
    }

    /**
     * {@inheritdoc}
     */
    public function render(CrelishWidgetInterface $widget, array $context = [])
    {
        // Get widget configuration
        $config = $widget->getClientConfig();
        $fieldKey = $widget->formKey ?: $widget->attribute;
        
        // Generate unique ID for this instance
        $uniqueId = $this->generateUniqueId($fieldKey);
        
        // Build the field path
        $path = implode('.', $this->fieldPath);
        
        // Prepare widget data
        $widgetData = [
            'uniqueId' => $uniqueId,
            'fieldKey' => $fieldKey,
            'path' => $path,
            'value' => $widget->getValue(),
            'config' => $config,
            'widgetClass' => get_class($widget),
        ];
        
        // If widget supports AJAX rendering, render a placeholder
        if ($widget->supportsAjaxRendering()) {
            return $this->renderPlaceholder($widgetData);
        }
        
        // Otherwise, render directly with a wrapper
        return $this->renderDirectly($widget, $widgetData);
    }

    /**
     * {@inheritdoc}
     */
    public function supports(CrelishWidgetInterface $widget)
    {
        // This strategy supports all widgets for JsonStructureEditor context
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getInitScript(CrelishWidgetInterface $widget, array $context = [])
    {
        $uniqueId = $context['uniqueId'] ?? $this->generateUniqueId($widget->formKey);
        $initScript = $widget->getInitializationScript();
        
        if ($initScript) {
            // Wrap in a function to avoid conflicts
            return "(function() {
                const container = document.getElementById('{$uniqueId}');
                if (container) {
                    {$initScript}
                }
            })();";
        }
        
        return null;
    }

    /**
     * Render a placeholder for AJAX loading
     * 
     * @param array $widgetData
     * @return string
     */
    protected function renderPlaceholder($widgetData)
    {
        $attributes = [
            'id' => $widgetData['uniqueId'],
            'class' => 'crelish-widget-placeholder',
            'data-widget-class' => $widgetData['widgetClass'],
            'data-field-key' => $widgetData['fieldKey'],
            'data-path' => $widgetData['path'],
            'data-value' => Json::encode($widgetData['value']),
            'data-config' => Json::encode($widgetData['config']),
        ];
        
        $content = Html::tag('div', 'Loading widget...', ['class' => 'widget-loading']);
        
        return Html::tag('div', $content, $attributes);
    }

    /**
     * Render widget directly with wrapper
     * 
     * @param CrelishWidgetInterface $widget
     * @param array $widgetData
     * @return string
     */
    protected function renderDirectly(CrelishWidgetInterface $widget, $widgetData)
    {
        $attributes = [
            'id' => $widgetData['uniqueId'],
            'class' => 'crelish-widget-container',
            'data-widget-class' => $widgetData['widgetClass'],
            'data-field-key' => $widgetData['fieldKey'],
            'data-path' => $widgetData['path'],
        ];
        
        $content = $widget->renderWidget();
        
        // Add initialization script if any
        $initScript = $this->getInitScript($widget, $widgetData);
        if ($initScript) {
            $content .= Html::script($initScript);
        }
        
        return Html::tag('div', $content, $attributes);
    }

    /**
     * Generate a unique ID for the widget instance
     * 
     * @param string $fieldKey
     * @return string
     */
    protected function generateUniqueId($fieldKey)
    {
        $pathString = implode('-', $this->fieldPath);
        $prefix = $this->contextPrefix ?: 'widget';
        
        return $prefix . '-' . $pathString . '-' . $fieldKey . '-' . uniqid();
    }
}