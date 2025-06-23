<?php
namespace giantbits\crelish\components;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use giantbits\crelish\components\interfaces\CrelishWidgetInterface;
use giantbits\crelish\components\strategies\WidgetRenderStrategy;
use giantbits\crelish\components\strategies\StandardRenderStrategy;

/**
 * Class CrelishWidgetFactory
 * 
 * Factory for creating and rendering Crelish widgets
 * 
 * @package giantbits\crelish\components
 */
class CrelishWidgetFactory extends Component
{
    /**
     * @var array Widget class mappings
     */
    protected static $widgetMappings = [
        'assetConnector' => 'giantbits\crelish\plugins\assetconnector\AssetConnector',
        'asset' => 'giantbits\crelish\plugins\assetconnector\AssetConnector',
        'relationSelect' => 'giantbits\crelish\plugins\relationselect\RelationSelect',
        'relation' => 'giantbits\crelish\plugins\relationselect\RelationSelect',
        'jsonStructureEditor' => 'giantbits\crelish\plugins\jsonstructureeditor\JsonStructureEditor',
        'widgetConnector' => 'giantbits\crelish\plugins\widgetconnector\WidgetConnector',
    ];
    
    /**
     * @var array V2 widget class mappings (preferred over V1)
     */
    protected static $v2WidgetMappings = [
        'assetConnector' => 'giantbits\crelish\plugins\assetconnector\AssetConnectorV2',
        'asset' => 'giantbits\crelish\plugins\assetconnector\AssetConnectorV2',
        'relationSelect' => 'giantbits\crelish\plugins\relationselect\RelationSelectV2',
        'relation' => 'giantbits\crelish\plugins\relationselect\RelationSelectV2',
        'widgetConnector' => 'giantbits\crelish\plugins\widgetconnector\WidgetConnectorV2',
    ];
    
    /**
     * @var WidgetRenderStrategy The rendering strategy
     */
    protected $renderStrategy;

    /**
     * Constructor
     * 
     * @param WidgetRenderStrategy|null $renderStrategy
     * @param array $config
     */
    public function __construct($renderStrategy = null, $config = [])
    {
        $this->renderStrategy = $renderStrategy ?: new StandardRenderStrategy();
        parent::__construct($config);
    }

    /**
     * Register a widget mapping
     * 
     * @param string $type Widget type
     * @param string $class Widget class name
     */
    public static function registerWidget($type, $class)
    {
        static::$widgetMappings[$type] = $class;
    }

    /**
     * Create a widget instance
     * 
     * @param array $fieldDef Field definition
     * @param mixed $model Model instance
     * @param mixed $value Current value
     * @return CrelishWidgetInterface
     * @throws InvalidConfigException
     */
    public function createWidget($fieldDef, $model, $value = null)
    {
        $widgetClass = $this->resolveWidgetClass($fieldDef);
        
        if (!$widgetClass) {
            throw new InvalidConfigException("Cannot resolve widget class for field type: " . ($fieldDef['type'] ?? 'unknown'));
        }
        
        // Prepare widget configuration
        $config = [
            'model' => $model,
            'attribute' => $fieldDef['key'] ?? null,
            'formKey' => $fieldDef['key'] ?? null,
            'field' => $this->createFieldObject($fieldDef),
            'value' => $value,
            'data' => $value,
        ];
        
        // Add widget options if specified
        if (isset($fieldDef['widgetOptions'])) {
            $config['widgetOptions'] = $fieldDef['widgetOptions'];
        }
        
        // Add any field config as widget properties
        if (isset($fieldDef['config'])) {
            foreach ($fieldDef['config'] as $key => $val) {
                if (!isset($config[$key])) {
                    $config[$key] = $val;
                }
            }
            
            // Debug logging for AssetConnector
            if (strpos($widgetClass, 'AssetConnector') !== false && isset($fieldDef['config']['multiple'])) {
                Yii::info("CrelishWidgetFactory: Creating AssetConnector with multiple=" . ($fieldDef['config']['multiple'] ? 'true' : 'false'), __METHOD__);
            }
        }
        
        // Create widget instance
        $widget = Yii::createObject($widgetClass, [$config]);
        
        if (!$widget instanceof CrelishWidgetInterface) {
            throw new InvalidConfigException("Widget class must implement CrelishWidgetInterface: $widgetClass");
        }
        
        return $widget;
    }

    /**
     * Render a widget
     * 
     * @param CrelishWidgetInterface $widget
     * @param array $context
     * @return string
     */
    public function renderWidget(CrelishWidgetInterface $widget, array $context = [])
    {
        return $this->renderStrategy->render($widget, $context);
    }

    /**
     * Create and render a widget in one step
     * 
     * @param array $fieldDef Field definition
     * @param mixed $model Model instance
     * @param mixed $value Current value
     * @param array $context Render context
     * @return string
     * @throws InvalidConfigException
     */
    public function widget($fieldDef, $model, $value = null, array $context = [])
    {
        $widget = $this->createWidget($fieldDef, $model, $value);
        return $this->renderWidget($widget, $context);
    }

    /**
     * Set the render strategy
     * 
     * @param WidgetRenderStrategy $strategy
     */
    public function setRenderStrategy(WidgetRenderStrategy $strategy)
    {
        $this->renderStrategy = $strategy;
    }

    /**
     * Resolve widget class from field definition
     * 
     * @param array $fieldDef
     * @return string|null
     */
    protected function resolveWidgetClass($fieldDef)
    {
        // Check for explicit widget class
        if (!empty($fieldDef['widgetClass'])) {
            return $fieldDef['widgetClass'];
        }
        
        // Check field type
        if (!empty($fieldDef['type'])) {
            $type = $fieldDef['type'];
            
            // Handle widget_ prefix
            if (strpos($type, 'widget_') === 0) {
                return str_replace('widget_', '', $type);
            }
            
            // Check if V1 is forced (for testing/compatibility)
            if (!static::$_forceV1) {
                // Check V2 mappings first (preferred)
                if (isset(static::$v2WidgetMappings[$type])) {
                    $v2Class = static::$v2WidgetMappings[$type];
                    if (class_exists($v2Class)) {
                        return $v2Class;
                    }
                }
            }
            
            // Fall back to V1 mappings
            if (isset(static::$widgetMappings[$type])) {
                return static::$widgetMappings[$type];
            }
            
            // Try to find V2 plugin by type name (if not forcing V1)
            if (!static::$_forceV1) {
                $v2PluginClass = 'giantbits\\crelish\\plugins\\' . strtolower($type) . '\\' . ucfirst($type) . 'V2';
                if (class_exists($v2PluginClass)) {
                    return $v2PluginClass;
                }
            }
            
            // Try to find V1 plugin by type name
            $pluginClass = 'giantbits\\crelish\\plugins\\' . strtolower($type) . '\\' . ucfirst($type);
            if (class_exists($pluginClass)) {
                return $pluginClass;
            }
        }
        
        return null;
    }

    /**
     * Create field object from array definition
     * 
     * @param array $fieldDef
     * @return \stdClass
     */
    protected function createFieldObject($fieldDef)
    {
        $field = new \stdClass();
        
        // Standard properties
        $field->key = $fieldDef['key'] ?? null;
        $field->label = $fieldDef['label'] ?? '';
        $field->type = $fieldDef['type'] ?? 'text';
        $field->rules = $fieldDef['rules'] ?? [];
        
        // Config object
        $field->config = new \stdClass();
        if (isset($fieldDef['config'])) {
            foreach ($fieldDef['config'] as $key => $val) {
                $field->config->$key = $val;
            }
        }
        
        // Copy any other properties
        foreach ($fieldDef as $key => $val) {
            if (!property_exists($field, $key)) {
                $field->$key = $val;
            }
        }
        
        return $field;
    }

    /**
     * Check if a type is a registered widget
     * 
     * @param string $type
     * @return bool
     */
    public static function isWidget($type)
    {
        // Check if it's a widget_ prefixed type
        if (strpos($type, 'widget_') === 0) {
            return true;
        }
        
        // Check V2 mappings first
        if (isset(static::$v2WidgetMappings[$type])) {
            return true;
        }
        
        // Check if it's in our V1 mappings
        if (isset(static::$widgetMappings[$type])) {
            return true;
        }
        
        // Check if it's a V2 plugin
        $v2PluginClass = 'giantbits\\crelish\\plugins\\' . strtolower($type) . '\\' . ucfirst($type) . 'V2';
        if (class_exists($v2PluginClass)) {
            return true;
        }
        
        // Check if it's a V1 plugin
        $pluginClass = 'giantbits\\crelish\\plugins\\' . strtolower($type) . '\\' . ucfirst($type);
        if (class_exists($pluginClass)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get widget version info
     * 
     * @param string $type
     * @return array [version => 'v1'|'v2', class => 'ClassName']
     */
    public static function getWidgetInfo($type)
    {
        // Check V2 first
        if (isset(static::$v2WidgetMappings[$type])) {
            $v2Class = static::$v2WidgetMappings[$type];
            if (class_exists($v2Class)) {
                return ['version' => 'v2', 'class' => $v2Class];
            }
        }
        
        // Check V2 plugin
        $v2PluginClass = 'giantbits\\crelish\\plugins\\' . strtolower($type) . '\\' . ucfirst($type) . 'V2';
        if (class_exists($v2PluginClass)) {
            return ['version' => 'v2', 'class' => $v2PluginClass];
        }
        
        // Check V1 mappings
        if (isset(static::$widgetMappings[$type])) {
            return ['version' => 'v1', 'class' => static::$widgetMappings[$type]];
        }
        
        // Check V1 plugin
        $pluginClass = 'giantbits\\crelish\\plugins\\' . strtolower($type) . '\\' . ucfirst($type);
        if (class_exists($pluginClass)) {
            return ['version' => 'v1', 'class' => $pluginClass];
        }
        
        return ['version' => null, 'class' => null];
    }
    
    /**
     * Force use of V1 widgets (for compatibility testing)
     * 
     * @param bool $forceV1
     */
    public static function setForceV1($forceV1 = true)
    {
        static::$_forceV1 = $forceV1;
    }
    
    /**
     * @var bool Force V1 widgets flag
     */
    protected static $_forceV1 = false;
}