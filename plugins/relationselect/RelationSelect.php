<?php

namespace giantbits\crelish\plugins\relationselect;

use giantbits\crelish\components\CrelishFormWidget;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii\web\View;
use yii\web\JsExpression;

class RelationSelect extends CrelishFormWidget
{
  public $data;
  public $rawData;
  public $formKey;
  public $field;
  public $value;
  public $attribute;
  public $allowClear = false;

  private $relationDataType;
  private $predefinedOptions;
  private $storedItems = [];
  private $assetUrl;

  /**
   * Helper method to convert various data formats to a normalized array of UUIDs
   * This handles all possible ways the data could be stored
   *
   * @param mixed $value The value to normalize
   * @return array An array of UUIDs
   */
  private function normalizeToArray($value) {
    // Empty values
    if (empty($value)) {
      return [];
    }

    // Already an array
    if (is_array($value)) {
      // Make sure we have only string UUIDs, not objects or nested arrays
      return array_map(function($item) {
        if (is_object($item) && isset($item->uuid)) {
          return $item->uuid;
        } elseif (is_array($item) && isset($item['uuid'])) {
          return $item['uuid'];
        } else {
          return (string)$item;
        }
      }, $value);
    }

    // If it's an object with a uuid property
    if (is_object($value) && isset($value->uuid)) {
      return [$value->uuid];
    }

    // Try to parse as JSON (could be an array or object)
    if (is_string($value) && (strpos($value, '[') === 0 || strpos($value, '{') === 0)) {
      try {
        $decoded = Json::decode($value);
        return $this->normalizeToArray($decoded); // Recursive call to handle the decoded value
      } catch (\Exception $e) {
        // Not valid JSON, treat as a string UUID
      }
    }

    // Assume it's a single UUID string
    return [(string)$value];
  }

  public function init()
  {
    parent::init();

    // Set the asset URL
    $this->assetUrl = Yii::$app->assetManager->getPublishedUrl('@vendor/giantbits/yii2-crelish/resources/relation-selector/dist');

    // Register the component script
    $this->registerAssets();

    // Set related content type
    $contentType = isset($this->field->config->ctype) ? $this->field->config->ctype : null;

    if (!$contentType) {
      Yii::warning('No content type defined for relation select field: ' . $this->field->key);
      return;
    }

    // Check if multiple selection is enabled
    $isMultiple = isset($this->field->config->multiple) && $this->field->config->multiple;

    // Get stored items for the model if it's not new
    if (!empty($this->model->uuid)) {
      $model = call_user_func('app\workspace\models\\' . ucfirst($this->model->ctype) . '::find')->where(['uuid' => $this->model->uuid])->one();

      if ($model) {
        $currentValue = $model->{$this->field->key};

        // Normalize to array of UUIDs regardless of input format
        $normalizedArray = $this->normalizeToArray($currentValue);

        // If multiple is disabled, only take the first item
        if (!$isMultiple && count($normalizedArray) > 0) {
          Yii::info("Field {$this->field->key} is configured as single relation but has multiple values. Taking only the first value.");
          $this->storedItems = [$normalizedArray[0]];
        } else {
          $this->storedItems = $normalizedArray;
        }
      }
    } elseif (!empty($this->data)) {
      // For new model with initial data
      $this->storedItems = $this->normalizeToArray($this->data);

      // If multiple is disabled, only take the first item
      if (!$isMultiple && count($this->storedItems) > 0) {
        $this->storedItems = [$this->storedItems[0]];
      }
    }
  }

  /**
   * @throws InvalidConfigException
   */
  protected function registerAssets()
  {
    // Register custom CSS for dark mode support
    $view = Yii::$app->getView();
    
    // Register Krajee Select2 initialization function to prevent errors and hide loading spinner
    $js = <<<JS
// Define Krajee's required initialization functions if they don't exist
if (typeof initS2Loading !== 'function') {
    window.initS2Loading = function(id, optVar) {
        // Hide loading indicators immediately
        setTimeout(function() {
            var s2LoadingElm = "#" + id + "-container .kv-plugin-loading.loading-" + id;
            $(s2LoadingElm).removeClass("kv-loading").hide();
            
            var s2Header = "#" + id + "-container .select2-container--krajee";
            $(s2Header).removeClass("loading");
        }, 100);
    };
}
if (typeof initS2Unload !== 'function') {
    window.initS2Unload = function(id, optVar) {
        // Placeholder function to prevent errors
        var s2LoadingElm = "#" + id + "-container .kv-plugin-loading.loading-" + id;
        $(s2LoadingElm).removeClass("kv-loading").hide();
    };
}
if (typeof initS2Change !== 'function') {
    window.initS2Change = function(id, optVar) {
        // Placeholder function to prevent errors
        var s2LoadingElm = "#" + id + "-container .kv-plugin-loading.loading-" + id;
        $(s2LoadingElm).removeClass("kv-loading").hide();
    };
}

// Additional function to ensure loading spinner is removed
$(document).ready(function() {
    setTimeout(function() {
        $('.kv-plugin-loading').hide();
        $('.kv-loading').removeClass('kv-loading');
        $('.loading').removeClass('loading');
    }, 500);
});
JS;
    $view->registerJs($js, View::POS_HEAD);
    
    $css = <<<CSS
/* Hide loading spinners immediately */
.kv-plugin-loading {
  display: none !important;
}
.select2-container--krajee.loading {
  opacity: 1 !important;
}

/* Relation Select Styling */
.selected-items {
  border: 1px solid #dee2e6;
  border-radius: 0.25rem;
  padding: 0.75rem;
  background-color: #f8f9fa;
}

.no-items-message {
  padding: 0.75rem;
  color: #6c757d;
  font-style: italic;
}

.actions {
  white-space: nowrap;
}

.actions button {
  margin-right: 0.25rem;
}

/* Dark mode support */
html[data-theme="dark"] .selected-items {
  border-color: #495057;
  background-color: #1e2a3b;
  color: #f8f9fa;
}

html[data-theme="dark"] .no-items-message {
  color: #adb5bd;
}

/* Dark mode CSS for all possible Krajee themes - bs3, bs4, bs5, krajee */
html[data-theme="dark"] .select2-container--krajee .select2-selection,
html[data-theme="dark"] .select2-container--krajee-bs3 .select2-selection,
html[data-theme="dark"] .select2-container--krajee-bs4 .select2-selection,
html[data-theme="dark"] .select2-container--krajee-bs5 .select2-selection {
  background-color: #2c3e50;
  border-color: #495057;
  color: #f8f9fa;
}

html[data-theme="dark"] .select2-container--krajee .select2-selection__rendered,
html[data-theme="dark"] .select2-container--krajee-bs3 .select2-selection__rendered,
html[data-theme="dark"] .select2-container--krajee-bs4 .select2-selection__rendered,
html[data-theme="dark"] .select2-container--krajee-bs5 .select2-selection__rendered {
  color: #f8f9fa;
}

html[data-theme="dark"] .select2-container--krajee .select2-dropdown,
html[data-theme="dark"] .select2-container--krajee-bs3 .select2-dropdown,
html[data-theme="dark"] .select2-container--krajee-bs4 .select2-dropdown,
html[data-theme="dark"] .select2-container--krajee-bs5 .select2-dropdown {
  background-color: #2c3e50;
  border-color: #495057;
}

html[data-theme="dark"] .select2-container--krajee .select2-results__option,
html[data-theme="dark"] .select2-container--krajee-bs3 .select2-results__option,
html[data-theme="dark"] .select2-container--krajee-bs4 .select2-results__option,
html[data-theme="dark"] .select2-container--krajee-bs5 .select2-results__option {
  color: #f8f9fa;
}

html[data-theme="dark"] .select2-container--krajee .select2-results__option--highlighted[aria-selected],
html[data-theme="dark"] .select2-container--krajee-bs3 .select2-results__option--highlighted[aria-selected],
html[data-theme="dark"] .select2-container--krajee-bs4 .select2-results__option--highlighted[aria-selected],
html[data-theme="dark"] .select2-container--krajee-bs5 .select2-results__option--highlighted[aria-selected] {
  background-color: #3498db;
}

td.actions button {
    border-radius: 0.6rem;
    background-color: #ffffff;
}

CSS;
    
    $view->registerCss($css);
  }

  public function run()
  {
    $isRequired = false;

    // Check if field is required
    foreach ($this->field->rules as $rule) {
      foreach ($rule as $set) {
        if ($set == 'required') {
          $isRequired = true;
          break 2;
        }
      }
    }

    // Check if multiple selection is enabled
    $isMultiple = isset($this->field->config->multiple) && $this->field->config->multiple;

    // Prepare data for the view
    $hiddenValue = '';

    if ($isMultiple) {
      // For multiple selection, store as JSON array
      $hiddenValue = Json::encode($this->storedItems);
    } else {
      // For single selection, store as simple string
      $hiddenValue = !empty($this->storedItems) ? (string)$this->storedItems[0] : '';
    }

    // Prepare select data and initial value for Select2
    $selectData = [];
    $selectValue = [];

    // If we have stored items, we need to fetch their details to display
    if (!empty($this->storedItems)) {
      foreach ($this->storedItems as $uuid) {
        // Only fetch valid UUIDs
        if (empty($uuid) || !is_string($uuid) || trim($uuid) === '') continue;

        // Fetch item details from API to get the title
        $model = call_user_func('\giantbits\crelish\components\CrelishDataResolver::resolveModel', [
          'ctype' => $this->field->config->ctype,
          'uuid' => $uuid
        ]);

        if ($model) {
          $displayText = $model->systitle ?? $model->title ?? $model->name ?? $uuid;
          // In single mode, store as a simple associative array
          if (!$isMultiple) {
            $selectData = [$uuid => $displayText];
          } else {
            // In multiple mode, build up the array
            $selectData[$uuid] = $displayText;
          }
          $selectValue[] = $uuid;
        }
      }
    }

    // For non-multiple selection, only use the first value
    if (!$isMultiple) {
      if (!empty($selectValue)) {
        $selectValue = $selectValue[0];
      } else {
        $selectValue = '';
      }
    } else {
      // Ensure it's always an array for multiple selection
      $selectValue = (array)$selectValue;
    }

    // Determine AJAX URL for Select2
    $ajaxUrl = '/crelish-api/content/' . $this->field->config->ctype;

    // Determine filter fields for searching
    $filterFields = isset($this->field->config->filterFields) 
      ? $this->field->config->filterFields 
      : ['systitle'];
      
    // No need for complex JSON encoding here, we'll build the AJAX config directly in the template
    
    // Generate a unique ID for the form element
    $fieldId = 'rel_' . $this->field->key . '_' . substr(md5(uniqid()), 0, 8);
    
    // No need for complex JSON encoding here, we'll build the plugin events directly in the template

    // Prepare table columns for multiple selection mode
    $columns = [];
    if (isset($this->field->config->columns) && is_array($this->field->config->columns)) {
      foreach ($this->field->config->columns as $column) {
        if (isset($column['attribute'])) {
          $columns[] = [
            'key' => $column['attribute'],
            'label' => isset($column['label']) ? $column['label'] : $column['attribute']
          ];
        }
      }
    }

    // If no columns specified, use default
    if (empty($columns)) {
      $columns = [
        ['key' => 'systitle', 'label' => Yii::t('crelish', 'Titel')]
      ];
    }
    
    // Make sure the select data is in the correct format for the Widget
    if (is_array($selectData) && !empty($selectData)) {
      if (!$isMultiple) {
        // For single selection mode, make sure it's a simple associative array
        if (count($selectData) > 1) {
          $selectData = array_slice($selectData, 0, 1, true);
        }
      }
    } else {
      // If empty or not an array, provide a default empty array
      $selectData = [];
    }
    
    // Make sure the select value is in the correct format
    if ($isMultiple) {
      // For multiple mode, ensure it's an array
      $selectValue = is_array($selectValue) ? $selectValue : [];
    } else {
      // For single mode, ensure it's a string
      $selectValue = !empty($selectValue) ? (is_array($selectValue) ? (string)reset($selectValue) : (string)$selectValue) : '';
    }
    
    return $this->render('relationselect.twig', [
      'formKey' => $this->formKey,
      'field' => $this->field,
      'required' => $isRequired ? 'required' : '',
      'isRequired' => $isRequired,
      'contentType' => $this->field->config->ctype,
      'hiddenValue' => $hiddenValue,
      'inputName' => "CrelishDynamicModel[{$this->field->key}]",
      'selectValue' => $selectValue,
      'selectData' => $selectData,
      'isMultiple' => $isMultiple,
      'allowClear' => !$isRequired,
      'columns' => $columns,
      'fieldId' => $fieldId,
      'ajaxUrl' => $ajaxUrl,
      'filterFields' => $filterFields
    ]);
  }
}