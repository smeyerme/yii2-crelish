<?php

namespace giantbits\crelish\plugins\formiojsoneditor;

use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\CrelishFormWidget;
use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\View;

/**
 * Modern JSON Editor plugin using Formio.js
 * Provides a beautiful, modern interface for editing JSON data
 */
class FormioJsonEditor extends CrelishFormWidget
{
  /**
   * @var mixed The data to edit
   */
  public $data;

  /**
   * @var string The form key
   */
  public $formKey;

  /**
   * @var object The field definition
   */
  public $field;

  /**
   * @var string The attribute name
   */
  public $attribute;

  /**
   * @var string Theme to use (bootstrap, material, semantic)
   */
  public $theme = 'bootstrap5';

  /**
   * Initialize the widget
   */
  public function init()
  {
    parent::init();

    // Register Formio.js from CDN
    $this->view->registerCssFile(
      'https://cdn.jsdelivr.net/npm/@formio/js@latest/dist/formio.full.min.css',
      ['position' => View::POS_HEAD]
    );

    $this->view->registerJsFile(
      'https://cdn.jsdelivr.net/npm/@formio/js@latest/dist/formio.full.min.js',
      ['position' => View::POS_HEAD]
    );

    // Register our custom styling
    $this->registerCustomStyles();
  }

  /**
   * Run the widget
   * @return string the HTML markup
   */
  public function run()
  {
    // Debug widget properties
    CrelishBaseHelper::dump("FormioJsonEditor - field: " . ($this->field ? $this->field->key : 'null') . ", formKey: " . ($this->formKey ?: 'null') . ", data: " . json_encode($this->data), 'formiojsoneditor');

    // Ensure i18n structure is prepared for translatable fields
    $this->prepareI18nStructure();

    // Check if field is required
    $isRequired = $this->isFieldRequired();

    // Get current data
    $jsonData = $this->getCurrentData();

    // Generate Formio form definition from JSON Schema
    $formDefinition = $this->generateFormioDefinition();

    // Prepare the editor container
    $fieldKey = $this->field ? $this->field->key : ($this->formKey ?: 'unknown');
    $editorId = 'formio-editor-' . $fieldKey;
    $inputName = $this->getInputName();

    // Create the form group container
    $html = Html::beginTag('div', [
      'class' => 'form-group field-crelishdynamicmodel-' . $this->formKey . ($isRequired ? ' required' : ''),
      'style' => 'margin-bottom: 2rem;'
    ]);

    // Create the editor label
    $html .= Html::label($this->field ? $this->field->label : 'JSON Field', $editorId, [
      'class' => 'control-label formio-label' . ($isRequired ? ' required' : ''),
      'style' => 'font-weight: 600; margin-bottom: 1rem; display: block; color: #374151;'
    ]);

    // Add description if available
    if ($this->field && property_exists($this->field, 'schema') &&
        property_exists($this->field->schema, 'description')) {
      $html .= Html::tag('p', $this->field->schema->description, [
        'class' => 'formio-description',
        'style' => 'color: #6b7280; font-size: 0.875rem; margin-bottom: 1rem;'
      ]);
    }

    // Create container for the Formio editor
    $html .= '<div id="' . $editorId . '" class="formio-container" style="min-height: 300px;"></div>';

    // Create hidden input to store the JSON data
    $jsonString = is_string($jsonData) ? $jsonData : Json::encode($jsonData);
    $html .= Html::hiddenInput($inputName, $jsonString, [
      'id' => 'hidden-' . $editorId,
      'class' => 'formio-hidden-input'
    ]);

    // Add error container
    $html .= Html::tag('div', '', [
      'class' => 'help-block help-block-error formio-errors',
      'style' => 'color: #dc2626; font-size: 0.875rem; margin-top: 0.5rem;'
    ]);

    $html .= Html::endTag('div');

    // Register JavaScript to initialize the Formio editor
    $this->registerFormioJs($editorId, $formDefinition, $jsonData);

    return $html;
  }

  /**
   * Check if field is required
   */
  protected function isFieldRequired()
  {
    if ($this->field && !empty($this->field->rules)) {
      foreach ($this->field->rules as $rule) {
        if (is_array($rule) && in_array('required', $rule)) {
          return true;
        }
      }
    }
    return false;
  }

  /**
   * Get current data for the field
   */
  protected function getCurrentData()
  {
    $currentLang = Yii::$app->language;
    $isTranslatable = $this->field && property_exists($this->field, 'translatable') && $this->field->translatable === true;

    $jsonData = $this->data;

    // Handle translatable fields
    if ($isTranslatable && $this->field && isset($this->model->i18n[$currentLang][$this->field->key])) {
      $jsonData = $this->model->i18n[$currentLang][$this->field->key];
    }

    // If still empty, get default from schema
    if (empty($jsonData)) {
      $jsonData = $this->getDefaultFromSchema();
    }

    // Handle data migration for hotel_configuration field
    if ($this->field && $this->field->key === 'hotel_configuration' && (is_object($jsonData) || is_array($jsonData))) {
      $jsonData = $this->migrateHotelConfigurationData($jsonData);
    }

    return $jsonData;
  }

  /**
   * Migrate hotel configuration data from old structure to new array-based structure
   */
  protected function migrateHotelConfigurationData($data)
  {
    // Convert object to array for easier manipulation
    $dataArray = is_object($data) ? (array)$data : $data;

    // Check if we already have the new array-based structure
    if (isset($dataArray['options']) && is_array($dataArray['options'])) {
      // Already in new format
      return $dataArray;
    }

    // Migrate from old flat structure to new array structure
    $newOptions = [];

    // Check for old nested structure with 'options' object first
    if (isset($dataArray['options']) && is_object($dataArray['options'])) {
      $oldOptions = (array)$dataArray['options'];

      foreach ($oldOptions as $key => $optionData) {
        $optionArray = is_object($optionData) ? (array)$optionData : $optionData;

        // Handle legacy key mapping: NGT0 -> MAIN_ONLY
        if ($key === 'NGT0') {
          $key = 'MAIN_ONLY';
        }

        $newOptions[] = array_merge([
          'key' => $key,
          'enabled' => true,
          'sortOrder' => count($newOptions)
        ], $optionArray);
      }

      // Remove the old options key
      unset($dataArray['options']);
    } else {
      // Check for old flat structure with direct FULL, MAIN_ONLY, NONE properties
      $oldKeys = ['FULL', 'MAIN_ONLY', 'NONE', 'NGT0'];
      foreach ($oldKeys as $key) {
        if (isset($dataArray[$key])) {
          $optionData = is_object($dataArray[$key]) ? (array)$dataArray[$key] : $dataArray[$key];

          // Handle legacy key mapping: NGT0 -> MAIN_ONLY
          $finalKey = ($key === 'NGT0') ? 'MAIN_ONLY' : $key;

          $newOptions[] = array_merge([
            'key' => $finalKey,
            'enabled' => true,
            'sortOrder' => count($newOptions)
          ], $optionData);

          // Remove the old direct property
          unset($dataArray[$key]);
        }
      }
    }

    // Set the new options array
    if (!empty($newOptions)) {
      $dataArray['options'] = $newOptions;
    }

    return $dataArray;
  }

  /**
   * Generate Formio form definition from JSON Schema
   */
  protected function generateFormioDefinition()
  {
    if (!$this->field || !property_exists($this->field, 'schema')) {
      // Fallback: simple JSON editor
      return [
        'components' => [
          [
            'type' => 'textarea',
            'key' => 'jsonData',
            'label' => 'JSON Data',
            'rows' => 10,
            'validate' => [
              'custom' => 'valid = (function() { try { JSON.parse(input); return true; } catch(e) { return "Invalid JSON format"; } })()'
            ]
          ]
        ]
      ];
    }

    $schema = $this->field->schema;

    // Convert JSON Schema to Formio components
    if ($schema->type === 'array') {
      return $this->generateArrayForm($schema);
    } elseif ($schema->type === 'object') {
      return $this->generateObjectForm($schema);
    }

    // Fallback
    return ['components' => []];
  }

  /**
   * Generate form for array type
   */
  protected function generateArrayForm($schema)
  {
    $components = [];

    if (property_exists($schema, 'items') && $schema->items->type === 'object') {
      // Array of objects - use DataGrid component
      $columns = [];

      if (property_exists($schema->items, 'properties')) {
        foreach ($schema->items->properties as $key => $prop) {
          $columns[] = [
            'label' => $prop->title ?? ucfirst($key),
            'key' => $key,
            'type' => $this->getFormioType($prop),
            'input' => true,
            'validate' => $this->getValidation($prop, in_array($key, $schema->items->required ?? []))
          ];
        }
      }

      $components[] = [
        'type' => 'datagrid',
        'key' => 'data',
        'label' => $schema->title ?? 'Items',
        'components' => $columns,
        'addAnotherPosition' => 'bottom',
        'striped' => false,
        'bordered' => true,
        'hover' => true,
        'condensed' => false
      ];
    }

    return ['components' => $components];
  }

  /**
   * Generate form for object type
   */
  protected function generateObjectForm($schema)
  {
    $components = [];

    if (property_exists($schema, 'properties')) {
      foreach ($schema->properties as $key => $prop) {
        $component = [
          'type' => $this->getFormioType($prop),
          'key' => $key,
          'label' => $prop->title ?? ucfirst($key),
          'input' => true,
          'validate' => $this->getValidation($prop, in_array($key, $schema->required ?? []))
        ];

        // Add description if available
        if (property_exists($prop, 'description')) {
          $component['description'] = $prop->description;
        }

        // Add default value
        if (property_exists($prop, 'default')) {
          $component['defaultValue'] = $prop->default;
        }

        // Handle specific types
        if ($prop->type === 'boolean') {
          $component['inputType'] = 'checkbox';
        } elseif ($prop->type === 'number') {
          $component['inputType'] = 'number';
        } elseif (property_exists($prop, 'format') && $prop->format === 'date') {
          $component['type'] = 'datetime';
          $component['enableDate'] = true;
          $component['enableTime'] = false;
          $component['format'] = 'yyyy-MM-dd';
        } elseif ($prop->type === 'object' && property_exists($prop, 'properties')) {
          // Handle nested objects by creating a container with nested components
          $component['type'] = 'container';
          $component['components'] = [];

          foreach ($prop->properties as $nestedKey => $nestedProp) {
            $nestedComponent = [
              'type' => $this->getFormioType($nestedProp),
              'key' => $nestedKey,
              'label' => $nestedProp->title ?? ucfirst($nestedKey),
              'input' => true,
              'validate' => $this->getValidation($nestedProp, in_array($nestedKey, $prop->required ?? []))
            ];

            // Add description if available
            if (property_exists($nestedProp, 'description')) {
              $nestedComponent['description'] = $nestedProp->description;
            }

            // Add default value
            if (property_exists($nestedProp, 'default')) {
              $nestedComponent['defaultValue'] = $nestedProp->default;
            }

            // Handle specific nested types
            if ($nestedProp->type === 'boolean') {
              $nestedComponent['inputType'] = 'checkbox';
            } elseif ($nestedProp->type === 'number') {
              $nestedComponent['inputType'] = 'number';
            } elseif (property_exists($nestedProp, 'format') && $nestedProp->format === 'date') {
              $nestedComponent['type'] = 'datetime';
              $nestedComponent['enableDate'] = true;
              $nestedComponent['enableTime'] = false;
              $nestedComponent['format'] = 'yyyy-MM-dd';
            }

            $component['components'][] = $nestedComponent;
          }
        }

        $components[] = $component;
      }
    }

    return ['components' => $components];
  }

  /**
   * Get Formio component type from JSON Schema type
   */
  protected function getFormioType($prop)
  {
    switch ($prop->type) {
      case 'string':
        if (property_exists($prop, 'format') && $prop->format === 'date') {
          return 'datetime';
        }
        return 'textfield';
      case 'number':
      case 'integer':
        return 'number';
      case 'boolean':
        return 'checkbox';
      case 'array':
        return 'datagrid';
      case 'object':
        return 'container';
      default:
        return 'textfield';
    }
  }

  /**
   * Get validation rules from JSON Schema property
   */
  protected function getValidation($prop, $required = false)
  {
    $validation = [];

    if ($required) {
      $validation['required'] = true;
    }

    if (property_exists($prop, 'pattern')) {
      $validation['pattern'] = $prop->pattern;
    }

    if (property_exists($prop, 'minimum')) {
      $validation['min'] = $prop->minimum;
    }

    if (property_exists($prop, 'maximum')) {
      $validation['max'] = $prop->maximum;
    }

    return $validation;
  }

  /**
   * Register JavaScript to initialize Formio
   */
  protected function registerFormioJs($editorId, $formDefinition, $currentData)
  {
    $formJson = Json::encode($formDefinition);
    $dataJson = Json::encode($currentData ?: new \stdClass());

    $js = <<<JS
document.addEventListener('DOMContentLoaded', function() {
  console.log('FormioJsonEditor: DOM loaded, looking for editor: $editorId');
  const container = document.getElementById('$editorId');
  const hiddenInput = document.getElementById('hidden-$editorId');

  console.log('FormioJsonEditor: Container found:', !!container, 'Hidden input found:', !!hiddenInput);
  console.log('FormioJsonEditor: Formio available:', typeof Formio);

  if (!container || !hiddenInput) {
    console.warn('FormioJsonEditor: Missing container or hidden input for $editorId');
    return;
  }

  // Formio form definition
  const formDefinition = $formJson;
  console.log('FormioJsonEditor: Form definition for $editorId:', formDefinition);

  // Initial data
  let initialData = {};
  try {
    initialData = $dataJson;
    console.log('FormioJsonEditor: Initial data for $editorId:', initialData);
  } catch (e) {
    console.warn('Invalid initial data for $editorId');
  }

  // Create Formio form
  console.log('FormioJsonEditor: Creating form for $editorId...');
  Formio.createForm(container, formDefinition, {
    buttonSettings: {
      show: false  // Hide submit button
    },
    noAlerts: true
  }).then(function(form) {
    console.log('FormioJsonEditor: Form created successfully for $editorId:', form);

    // Set initial data
    if (initialData && Object.keys(initialData).length > 0) {
      form.submission = { data: initialData };
    }

    // Listen for changes
    form.on('change', function() {
      const data = form.submission.data;
      hiddenInput.value = JSON.stringify(data, null, 0);

      // Clear any previous errors
      const errorContainer = container.parentNode.querySelector('.formio-errors');
      if (errorContainer) {
        errorContainer.innerHTML = '';
      }
    });

    // Handle validation errors
    form.on('error', function(errors) {
      const errorContainer = container.parentNode.querySelector('.formio-errors');
      if (errorContainer && errors.length > 0) {
        errorContainer.innerHTML = errors.map(err => err.message).join('<br>');
      }
    });

    // Store form reference for external access
    container._formioForm = form;
  }).catch(function(error) {
    console.error('Error creating Formio form:', error);
    container.innerHTML = '<div style="color: red; padding: 1rem;">Error loading form editor. Please check your JSON schema.</div>';
  });
});
JS;

    $this->view->registerJs($js, View::POS_END);
  }

  /**
   * Register custom styling for modern appearance
   */
  protected function registerCustomStyles()
  {
    $css = <<<CSS
.formio-container .formio-form {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.formio-container .form-group {
  margin-bottom: 1.5rem;
}

.formio-container .control-label {
  font-weight: 600;
  color: #374151;
  margin-bottom: 0.5rem;
  display: block;
}

.formio-container .form-control {
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.formio-container .form-control:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
  outline: none;
}

.formio-container .btn {
  border-radius: 0.375rem;
  font-weight: 500;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  transition: all 0.15s ease-in-out;
}

.formio-container .btn-primary {
  background-color: #3b82f6;
  border-color: #3b82f6;
  color: white;
}

.formio-container .btn-primary:hover {
  background-color: #2563eb;
  border-color: #2563eb;
}

.formio-container .btn-success {
  background-color: #10b981;
  border-color: #10b981;
  color: white;
}

.formio-container .btn-success:hover {
  background-color: #059669;
  border-color: #059669;
}

.formio-container .table {
  border-radius: 0.375rem;
  overflow: hidden;
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
}

.formio-container .table th {
  background-color: #f9fafb;
  font-weight: 600;
  color: #374151;
  border-bottom: 1px solid #e5e7eb;
}

.formio-container .datagrid-table {
  margin-top: 1rem;
}

.formio-label {
  font-size: 1rem;
  color: #111827;
}

.formio-description {
  line-height: 1.5;
}

.formio-errors {
  font-size: 0.875rem;
  margin-top: 0.25rem;
}

/* Custom checkbox styling */
.formio-container .form-check-input {
  width: 1.125rem;
  height: 1.125rem;
  border: 1px solid #d1d5db;
  border-radius: 0.25rem;
}

.formio-container .form-check-input:checked {
  background-color: #3b82f6;
  border-color: #3b82f6;
}

/* Better spacing for form groups */
.formio-container .formio-component {
  margin-bottom: 1.5rem;
}

/* Modern date picker styling */
.formio-container .flatpickr-input {
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
}
CSS;

    $this->view->registerCss($css);
  }

  /**
   * Prepare i18n structure (same as original JsonEditor)
   */
  protected function prepareI18nStructure()
  {
    $isTranslatable = $this->field && property_exists($this->field, 'translatable') && $this->field->translatable === true;

    if ($isTranslatable) {
      if (!property_exists($this->model, 'i18n') || !is_array($this->model->i18n)) {
        $this->model->i18n = [];
      }

      if (isset(Yii::$app->params['crelish']['languages']) && is_array(Yii::$app->params['crelish']['languages'])) {
        foreach (Yii::$app->params['crelish']['languages'] as $lang) {
          if (!isset($this->model->i18n[$lang])) {
            $this->model->i18n[$lang] = [];
          }

          if ($this->field && !isset($this->model->i18n[$lang][$this->field->key])) {
            $defaultValue = $this->getDefaultFromSchema();
            $this->model->i18n[$lang][$this->field->key] = $defaultValue;
          }
        }
      } else {
        $currentLang = Yii::$app->language;
        if (!isset($this->model->i18n[$currentLang])) {
          $this->model->i18n[$currentLang] = [];
        }

        if ($this->field && !isset($this->model->i18n[$currentLang][$this->field->key])) {
          $defaultValue = $this->getDefaultFromSchema();
          $this->model->i18n[$currentLang][$this->field->key] = $defaultValue;
        }
      }
    }
  }

  /**
   * Get input name (same as original JsonEditor)
   */
  protected function getInputName()
  {
    $isTranslatable = $this->field && property_exists($this->field, 'translatable') && $this->field->translatable === true;
    $currentLang = Yii::$app->language;

    if ($isTranslatable && $this->field) {
      return "CrelishDynamicModel[i18n][{$currentLang}][{$this->field->key}]";
    } else {
      return "CrelishDynamicModel[{$this->formKey}]";
    }
  }

  /**
   * Get default from schema (same as original JsonEditor)
   */
  protected function getDefaultFromSchema()
  {
    if (!$this->field || !property_exists($this->field, 'schema')) {
      return null;
    }

    $schema = $this->field->schema;

    if ($schema->type === 'array') {
      return [];
    } elseif ($schema->type === 'object') {
      $defaults = new \stdClass();
      if (property_exists($schema, 'properties')) {
        foreach ($schema->properties as $propName => $propSchema) {
          if (property_exists($propSchema, 'default')) {
            $defaults->{$propName} = $propSchema->default;
          }
        }
      }
      return $defaults;
    }

    return null;
  }
}