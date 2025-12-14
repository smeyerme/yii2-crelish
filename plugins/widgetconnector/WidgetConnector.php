<?php

namespace giantbits\crelish\plugins\widgetconnector;

use giantbits\crelish\components\ConfigurableWidgetInterface;
use giantbits\crelish\components\CrelishFormWidget;
use kartik\select2\Select2Asset;
use kartik\select2\ThemeKrajeeBs5Asset;
use Yii;
use yii\helpers\Json;

/**
 * WidgetConnector - CMS plugin for selecting and configuring workspace widgets.
 *
 * Scans workspace/widgets for classes implementing ConfigurableWidgetInterface
 * and renders a dynamic configuration form based on their schema.
 *
 * Outputs two form fields:
 * - widgetType: The widget class name (e.g., "EventList")
 * - options: JSON string of widget configuration options
 */
class WidgetConnector extends CrelishFormWidget
{
    public $model;
    public $attribute;
    public $data;
    public $formKey;
    public $field;

    private array $availableWidgets = [];
    private ?string $selectedWidget = null;
    private array $currentOptions = [];

    public function init(): void
    {
        parent::init();

        // The widgetType field value is passed as $this->data
        if (!empty($this->data)) {
            $this->selectedWidget = is_string($this->data) ? $this->data : null;
        }

        // Try to get current options from the model
        if ($this->model && isset($this->model->options)) {
            $options = $this->model->options;
            if (is_string($options) && !empty($options)) {
                try {
                    $this->currentOptions = Json::decode($options);
                } catch (\Exception $e) {
                    $this->currentOptions = [];
                }
            } elseif (is_array($options)) {
                $this->currentOptions = $options;
            } elseif (is_object($options)) {
                $this->currentOptions = (array)$options;
            }
        }

        // Scan for available widgets
        $this->availableWidgets = $this->scanWidgets();
    }

    public function run(): string
    {
        $fieldId = 'widget_connector_' . $this->formKey;

        // Check if any widget has relation fields and register Select2 assets
        $hasRelationFields = false;
        foreach ($this->availableWidgets as $widget) {
            if (!empty($widget['schema'])) {
                foreach ($widget['schema'] as $fieldConfig) {
                    if (isset($fieldConfig['type']) && $fieldConfig['type'] === 'relation') {
                        $hasRelationFields = true;
                        break 2;
                    }
                }
            }
        }

        if ($hasRelationFields) {
            $view = Yii::$app->getView();
            Select2Asset::register($view);
            ThemeKrajeeBs5Asset::register($view);
        }

        return $this->render('widgetconnector.twig', [
            'formKey' => $this->formKey,
            'field' => $this->field,
            'fieldId' => $fieldId,
            'availableWidgets' => $this->availableWidgets,
            'selectedWidget' => $this->selectedWidget,
            'currentOptions' => $this->currentOptions,
        ]);
    }

    /**
     * Scan workspace/widgets directory for configurable widgets
     */
    private function scanWidgets(): array
    {
        $widgets = [];
        $widgetsPath = Yii::getAlias('@app/workspace/widgets');

        if (!is_dir($widgetsPath)) {
            return $widgets;
        }

        $directories = scandir($widgetsPath);

        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $widgetPath = $widgetsPath . DIRECTORY_SEPARATOR . $dir;
            $widgetFile = $widgetPath . DIRECTORY_SEPARATOR . $dir . '.php';

            if (!is_dir($widgetPath) || !file_exists($widgetFile)) {
                continue;
            }

            $className = "app\\workspace\\widgets\\{$dir}\\{$dir}";

            if (!class_exists($className)) {
                continue;
            }

            // Check if widget implements ConfigurableWidgetInterface
            if (!in_array(ConfigurableWidgetInterface::class, class_implements($className))) {
                // Still list non-configurable widgets but with limited info
                $widgets[$dir] = [
                    'name' => $dir,
                    'class' => $className,
                    'configurable' => false,
                    'meta' => [
                        'label' => $dir,
                        'description' => 'Legacy widget (not configurable)',
                        'category' => 'legacy',
                    ],
                    'schema' => [],
                ];
                continue;
            }

            // Get widget metadata and schema
            $meta = $className::getWidgetMeta();
            $schema = $className::getConfigSchema();

            $widgets[$dir] = [
                'name' => $dir,
                'class' => $className,
                'configurable' => true,
                'meta' => $meta,
                'schema' => $schema,
            ];
        }

        // Sort by category then label
        uasort($widgets, function ($a, $b) {
            $catA = $a['meta']['category'] ?? 'zzz';
            $catB = $b['meta']['category'] ?? 'zzz';
            if ($catA !== $catB) {
                return strcmp($catA, $catB);
            }
            return strcmp($a['meta']['label'], $b['meta']['label']);
        });

        return $widgets;
    }

    /**
     * Static method to get widgets for API calls
     */
    public static function getAvailableWidgets(): array
    {
        $connector = new self();
        return $connector->scanWidgets();
    }
}