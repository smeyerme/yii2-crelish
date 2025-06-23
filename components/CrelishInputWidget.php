<?php
namespace giantbits\crelish\components;

use Yii;
use yii\helpers\Html;
use yii\widgets\InputWidget;
use giantbits\crelish\components\interfaces\CrelishWidgetInterface;

/**
 * Class CrelishInputWidget
 * 
 * Base class for all Crelish form input widgets
 * Extends Yii2's InputWidget for better form integration
 * 
 * @package giantbits\crelish\components
 */
abstract class CrelishInputWidget extends InputWidget implements CrelishWidgetInterface
{
    /**
     * @var mixed The raw data value
     */
    public $data;
    
    /**
     * @var mixed The raw/unprocessed value (for backward compatibility)
     */
    public $rawData;
    
    /**
     * @var string The form field key
     */
    public $formKey;
    
    /**
     * @var \stdClass The field definition object
     */
    public $field;
    
    /**
     * @var mixed The current value (alias for data)
     */
    public $value;
    
    /**
     * @var array Widget-specific options
     */
    public $widgetOptions = [];
    
    /**
     * @var bool Whether assets have been registered for this widget type
     */
    protected static $assetsRegistered = [];
    
    /**
     * @var string Path to widget assets
     */
    protected $assetPath;
    
    /**
     * @var array Translations for the widget
     */
    protected $translations = [];

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();
        
        // Initialize properties for backward compatibility
        if ($this->data === null && $this->value !== null) {
            $this->data = $this->value;
        } elseif ($this->value === null && $this->data !== null) {
            $this->value = $this->data;
        }
        
        // Get field definition from model if available
        if ($this->field === null && $this->model && $this->attribute) {
            if (isset($this->model->fieldDefinitions->fields[$this->attribute])) {
                $this->field = $this->model->fieldDefinitions->fields[$this->attribute];
            }
        }
        
        // Set form key
        if ($this->formKey === null) {
            $this->formKey = $this->attribute;
        }
        
        // Process the data
        $this->data = $this->processData($this->data);
        $this->value = $this->data;
        
        // Register assets
        $this->registerAssets();
        
        // Load translations
        $this->loadTranslations();
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        return $this->renderWidget();
    }

    /**
     * {@inheritdoc}
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function setValue($value)
    {
        $this->value = $value;
        $this->data = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getFieldDefinition()
    {
        return $this->field;
    }

    /**
     * {@inheritdoc}
     */
    public function registerAssets()
    {
        $widgetClass = get_class($this);
        
        // Only register assets once per widget type
        if (isset(self::$assetsRegistered[$widgetClass])) {
            return;
        }
        
        self::$assetsRegistered[$widgetClass] = true;
        
        // Register widget-specific assets
        $this->registerWidgetAssets();
    }

    /**
     * Register widget-specific assets
     * Override this method in child classes
     */
    protected function registerWidgetAssets()
    {
        // Default implementation - override in child classes
        if ($this->assetPath) {
            $this->publishAndRegisterAsset($this->assetPath);
        }
    }

    /**
     * Helper method to publish and register a JavaScript asset
     * 
     * @param string $assetPath
     * @param int $position
     */
    protected function publishAndRegisterAsset($assetPath, $position = \yii\web\View::POS_END)
    {
        $assetManager = Yii::$app->assetManager;
        $publishedUrl = $assetManager->publish($assetPath, [
            'forceCopy' => YII_DEBUG,
            'appendTimestamp' => true,
        ])[1];
        
        $this->view->registerJsFile($publishedUrl, ['position' => $position]);
    }

    /**
     * {@inheritdoc}
     */
    public function getInitializationScript()
    {
        return null; // Override in child classes if needed
    }

    /**
     * {@inheritdoc}
     */
    public function supportsAjaxRendering()
    {
        return true; // Most widgets should support AJAX rendering
    }

    /**
     * {@inheritdoc}
     */
    public function getClientConfig()
    {
        return [
            'widgetClass' => get_class($this),
            'fieldKey' => $this->formKey,
            'value' => $this->value,
            'options' => $this->widgetOptions,
            'translations' => $this->translations,
        ];
    }

    /**
     * Load widget translations
     */
    public function loadTranslations()
    {
        // Override in child classes to load specific translations
    }
    
    /**
     * Get widget translations
     * 
     * @return array
     */
    public function getTranslations()
    {
        return $this->translations;
    }

    /**
     * Get the input name for the form field
     * 
     * @return string
     */
    protected function getInputName()
    {
        if ($this->hasModel()) {
            return Html::getInputName($this->model, $this->attribute);
        }
        return $this->name;
    }

    /**
     * Get the input ID for the form field
     * 
     * @return string
     */
    protected function getInputId()
    {
        if ($this->hasModel()) {
            return Html::getInputId($this->model, $this->attribute);
        }
        return $this->id ?: $this->name;
    }

    /**
     * Check if field is required
     * 
     * @return bool
     */
    protected function isRequired()
    {
        if ($this->field && isset($this->field->rules)) {
            foreach ($this->field->rules as $rule) {
                if (is_array($rule) && in_array('required', $rule)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get field configuration value
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getConfig($key, $default = null)
    {
        if ($this->field && isset($this->field->config) && isset($this->field->config->$key)) {
            return $this->field->config->$key;
        }
        return $default;
    }

    /**
     * Get widget option value
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getOption($key, $default = null)
    {
        return $this->widgetOptions[$key] ?? $default;
    }

    /**
     * Render using a view file
     * 
     * @param string $view
     * @param array $params
     * @return string
     */
    protected function renderView($view, $params = [])
    {
        $defaultParams = [
            'widget' => $this,
            'model' => $this->model,
            'attribute' => $this->attribute,
            'field' => $this->field,
            'value' => $this->value,
            'inputName' => $this->getInputName(),
            'inputId' => $this->getInputId(),
            'required' => $this->isRequired(),
        ];
        
        return $this->render($view, array_merge($defaultParams, $params));
    }

    /**
     * Get normalized value as array
     * 
     * @param mixed $value
     * @return array
     */
    protected function normalizeToArray($value)
    {
        if (is_array($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            // Try JSON decode
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return is_array($decoded) ? $decoded : [$decoded];
            }
            
            // Try comma-separated
            if (strpos($value, ',') !== false) {
                return array_map('trim', explode(',', $value));
            }
            
            // Single value
            return $value ? [$value] : [];
        }
        
        if (is_object($value)) {
            return (array)$value;
        }
        
        return [];
    }
}