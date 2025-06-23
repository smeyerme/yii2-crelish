<?php
namespace giantbits\crelish\plugins\widgetconnector;

use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use giantbits\crelish\components\CrelishInputWidget;

/**
 * Class WidgetConnectorV2
 * 
 * Improved WidgetConnector widget using the new architecture
 * This widget allows embedding of external Yii2 widgets within Crelish forms
 * 
 * @package giantbits\crelish\plugins\widgetconnector
 */
class WidgetConnectorV2 extends CrelishInputWidget
{
    /**
     * @var string The target widget class to embed
     */
    public $targetWidget;
    
    /**
     * @var array Configuration for the target widget
     */
    public $widgetConfig = [];
    
    /**
     * @var string Data format (json, string, array)
     */
    public $dataFormat = 'string';
    
    /**
     * @var mixed Processed widget data
     */
    protected $widgetData;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        // Get configuration from field
        $this->targetWidget = $this->getConfig('targetWidget', $this->getConfig('widget'));
        $this->widgetConfig = $this->getConfig('widgetConfig', $this->getConfig('config', []));
        $this->dataFormat = $this->getConfig('dataFormat', 'string');
        
        // Validate target widget
        if (empty($this->targetWidget)) {
            throw new \yii\base\InvalidConfigException('WidgetConnector requires a targetWidget configuration');
        }
        
        if (!class_exists($this->targetWidget)) {
            throw new \yii\base\InvalidConfigException("Target widget class not found: {$this->targetWidget}");
        }
        
        parent::init();
    }

    /**
     * {@inheritdoc}
     */
    protected function registerWidgetAssets()
    {
        // Register generic widget connector assets if needed
        $css = '
        .widget-connector-container {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            background-color: #f8f9fa;
            margin-bottom: 1rem;
        }
        
        .widget-connector-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .widget-connector-widget {
            background-color: white;
            border-radius: 0.25rem;
            padding: 0.75rem;
        }
        
        .widget-connector-error {
            color: #dc3545;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 0.25rem;
            padding: 0.75rem;
            margin: 0.5rem 0;
        }
        
        /* Dark mode support */
        [data-theme="dark"] .widget-connector-container {
            background-color: #2d3748;
            border-color: #4a5568;
        }
        
        [data-theme="dark"] .widget-connector-label {
            color: #e2e8f0;
        }
        
        [data-theme="dark"] .widget-connector-widget {
            background-color: #1a202c;
        }
        ';
        
        $this->view->registerCss($css);
    }

    /**
     * {@inheritdoc}
     */
    public function processData($data)
    {
        // Process data according to specified format
        switch ($this->dataFormat) {
            case 'json':
                if (is_string($data)) {
                    $this->widgetData = Json::decode($data);
                } else {
                    $this->widgetData = $data;
                }
                break;
                
            case 'array':
                if (is_string($data)) {
                    // Try JSON first, then comma-separated
                    if (substr($data, 0, 1) === '[') {
                        $this->widgetData = Json::decode($data);
                    } else {
                        $this->widgetData = array_map('trim', explode(',', $data));
                    }
                } elseif (is_array($data)) {
                    $this->widgetData = $data;
                } else {
                    $this->widgetData = [$data];
                }
                break;
                
            case 'string':
            default:
                if (is_array($data) || is_object($data)) {
                    $this->widgetData = Json::encode($data);
                } else {
                    $this->widgetData = (string)$data;
                }
                break;
        }
        
        return $this->widgetData;
    }

    /**
     * {@inheritdoc}
     */
    public function renderWidget()
    {
        try {
            // Prepare widget configuration
            $config = array_merge($this->widgetConfig, [
                'model' => $this->model,
                'attribute' => $this->attribute,
                'value' => $this->widgetData,
            ]);
            
            // Remove conflicting options
            unset($config['name'], $config['id']);
            
            // Create the target widget
            $widget = $this->targetWidget::widget($config);
            
            // Wrap in container
            $html = Html::beginTag('div', [
                'class' => 'form-group field-' . Html::getInputId($this->model, $this->attribute) . ($this->isRequired() ? ' required' : ''),
            ]);
            
            $html .= Html::beginTag('div', ['class' => 'widget-connector-container']);
            
            // Add label if needed
            if ($this->field && !empty($this->field->label)) {
                $html .= Html::tag('label', Html::encode($this->field->label), [
                    'class' => 'widget-connector-label',
                    'for' => Html::getInputId($this->model, $this->attribute),
                ]);
            }
            
            // Add widget wrapper
            $html .= Html::beginTag('div', ['class' => 'widget-connector-widget']);
            $html .= $widget;
            $html .= Html::endTag('div');
            
            // Add hidden input to store processed value
            $html .= Html::hiddenInput(
                $this->getInputName(),
                is_string($this->widgetData) ? $this->widgetData : Json::encode($this->widgetData),
                ['id' => $this->getInputId()]
            );
            
            $html .= Html::endTag('div');
            $html .= Html::tag('div', '', ['class' => 'help-block help-block-error']);
            $html .= Html::endTag('div');
            
            return $html;
            
        } catch (\Exception $e) {
            // Return error display
            return $this->renderError($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getInitializationScript()
    {
        $id = $this->getInputId();
        $config = Json::encode([
            'targetWidget' => $this->targetWidget,
            'dataFormat' => $this->dataFormat,
            'fieldKey' => $this->formKey,
        ]);
        
        return "
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('{$id}').closest('.widget-connector-container');
            if (container) {
                // Listen for changes in the embedded widget
                container.addEventListener('change', function(e) {
                    const hiddenInput = document.getElementById('{$id}');
                    if (hiddenInput && e.target !== hiddenInput) {
                        // Update hidden input when embedded widget changes
                        hiddenInput.value = e.target.value;
                        hiddenInput.dispatchEvent(new Event('change'));
                    }
                });
                
                // Store widget config for potential JavaScript access
                container._widgetConnectorConfig = {$config};
            }
        });
        ";
    }

    /**
     * {@inheritdoc}
     */
    public function getClientConfig()
    {
        $config = parent::getClientConfig();
        
        $config['targetWidget'] = $this->targetWidget;
        $config['widgetConfig'] = $this->widgetConfig;
        $config['dataFormat'] = $this->dataFormat;
        $config['widgetData'] = $this->widgetData;
        
        return $config;
    }

    /**
     * Render error message
     * 
     * @param string $message
     * @return string
     */
    protected function renderError($message)
    {
        $html = Html::beginTag('div', [
            'class' => 'form-group field-' . Html::getInputId($this->model, $this->attribute) . ($this->isRequired() ? ' required' : ''),
        ]);
        
        $html .= Html::beginTag('div', ['class' => 'widget-connector-container']);
        
        if ($this->field && !empty($this->field->label)) {
            $html .= Html::tag('label', Html::encode($this->field->label), [
                'class' => 'widget-connector-label',
            ]);
        }
        
        $html .= Html::tag('div', 'Widget Error: ' . Html::encode($message), [
            'class' => 'widget-connector-error'
        ]);
        
        // Add fallback input
        $html .= Html::textInput(
            $this->getInputName(),
            $this->getValue(),
            [
                'id' => $this->getInputId(),
                'class' => 'form-control',
                'placeholder' => 'Enter value manually due to widget error',
            ]
        );
        
        $html .= Html::endTag('div');
        $html .= Html::endTag('div');
        
        return $html;
    }

    /**
     * {@inheritdoc}
     */
    public function loadTranslations()
    {
        $this->translations = [
            'widgetError' => Yii::t('app', 'Widget Error'),
            'fallbackInput' => Yii::t('app', 'Enter value manually'),
            'loading' => Yii::t('app', 'Loading widget...'),
        ];
    }
}