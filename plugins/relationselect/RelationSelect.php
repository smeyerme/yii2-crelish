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

  public function init()
  {
    parent::init();

    // Set the asset URL (adjust based on your application structure)
    $this->assetUrl = Yii::$app->assetManager->getPublishedUrl('@vendor/giantbits/yii2-crelish/resources/relation-selector/dist');

    // Register the Vue component script
    $this->registerAssets();

    // Set related content type
    $contentType = isset($this->field->config->ctype) ? $this->field->config->ctype : null;
    
    if (!$contentType) {
      Yii::warning('No content type defined for relation select field: ' . $this->field->key);
      return;
    }

    // Get stored items for the model if it's not new
    if (!empty($this->model->uuid) && isset($this->field->config->multiple) && $this->field->config->multiple) {
      $model = call_user_func('app\workspace\models\\' . ucfirst($this->model->ctype) . '::find')->where(['uuid' => $this->model->uuid])->one();
      
      if ($model) {
        $currentValue = $model->{$this->field->key};
        
        // If it's a string, try to decode it as JSON
        if (is_string($currentValue)) {
          $currentValue = json_decode($currentValue, true) ?: [];
        } else if (!is_array($currentValue)) {
          $currentValue = [];
        }
        
        $this->storedItems = $currentValue;
      }
    } elseif (!empty($this->data)) {
      // For single relation
      $this->storedItems = is_object($this->data) ? [$this->data->uuid] : [$this->data];
    }
  }

  /**
   * @throws InvalidConfigException
   */
  protected function registerAssets()
  {

    // Get the AssetManager instance
    $assetManager = Yii::$app->assetManager;

    // Define the source path of your JS file
    $sourcePath = $sourcePath = Yii::getAlias('@vendor/giantbits/yii2-crelish/resources/relation-selector/dist/relation-selector.js');

    // Publish the file and get the published URL
    $publishedUrl = $assetManager->publish($sourcePath, [
      'forceCopy' => YII_DEBUG,
      'appendTimestamp' => true,
    ])[1];

    // Get the view
    $view = Yii::$app->getView();
    
    // Register jQuery dependency (should already be registered by Yii)
    $view->registerJsFile(
      'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
      ['position' => View::POS_END, 'depends' => [\yii\web\JqueryAsset::class]]
    );
    
    // Register Select2 CSS
    $view->registerCssFile(
      'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
      ['position' => View::POS_HEAD]
    );
    
    // Register the Vue component script (after jQuery and Select2)
    $view->registerJsFile(
      $publishedUrl,
      ['position' => View::POS_END, 'depends' => [\yii\web\JqueryAsset::class]]
    );
    
    // Add translations
    $translations = [
      'choosePlaceholder' => Yii::t('crelish', 'Bitte wählen...'),
      'addButton' => Yii::t('crelish', 'Hinzufügen'),
      'assignedItems' => Yii::t('crelish', 'Zugeordnete Einträge'),
      'actions' => Yii::t('crelish', 'Aktionen'),
      'noItemsSelected' => Yii::t('crelish', 'Keine Einträge ausgewählt'),
      'itemAlreadyAdded' => Yii::t('crelish', 'Dieser Eintrag wurde bereits hinzugefügt'),
      'loadingOptions' => Yii::t('crelish', 'Lade Optionen...')
    ];
    
    $js = "window.relationSelectorTranslations = " . Json::encode($translations) . ";";
    $view->registerJs($js, View::POS_HEAD);
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
    
    // Prepare table columns
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
    
    return $this->render('relationselect.twig', [
      'formKey' => $this->formKey,
      'field' => $this->field,
      'required' => $isRequired ? 'required' : '',
      'isRequired' => $isRequired,
      'fieldKey' => $this->field->key,
      'contentType' => $this->field->config->ctype,
      'storedValue' => Json::encode($this->storedItems),
      'inputName' => "CrelishDynamicModel[{$this->field->key}]",
      'label' => $this->field->label,
      'columns' => Json::encode($columns),
      'isMultiple' => isset($this->field->config->multiple) && $this->field->config->multiple,
    ]);
  }
}
