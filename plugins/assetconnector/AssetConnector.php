<?php

namespace giantbits\crelish\plugins\assetconnector;

use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishFormWidget;
use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\View;

class AssetConnector extends CrelishFormWidget
{
  public $data;
  public $rawData;
  public $formKey;
  public $field;
  public $value;
  private $assetData = null;

  public function init()
  {
    parent::init();

    if (!empty($this->data)) {
      $this->rawData = $this->data;
      $this->processData($this->data);
    } else {
      $this->processData(null);
      $this->rawData = [];
    }
    
    // Get the AssetManager instance
    $assetManager = Yii::$app->assetManager;

    // Define the source path of your JS file
    $sourcePath = Yii::getAlias('@vendor/giantbits/yii2-crelish/resources/asset-connector/dist/asset-connector.js');

    // Publish the file and get the published URL
    $publishedUrl = $assetManager->publish($sourcePath, [
      'forceCopy' => YII_DEBUG,
      'appendTimestamp' => true,
    ])[1];

    // Register the script in the view
    $this->view->registerJsFile($publishedUrl);

  }

  private function processData($data)
  {
    if (!is_object($data)) {
      // Handle null or empty data
      if (empty($data)) {
        $data = ['uuid' => null];
      } elseif (is_string($data) && (substr($data, 0, 1) == '{' || substr($data, 0, 1) == '[')) {
        $data = Json::decode($data);
      } else {
        $data = ['uuid' => $data];
      }
    }

    if (array_key_exists('uuid', (array)$data)) {
      $itemData = new CrelishDynamicModel(['ctype' => 'asset', 'uuid' => $data['uuid']]);

      if (!empty($itemData['uuid'])) {
        $this->assetData = $itemData;
      }
    }
  }

  public function run()
  {
    $isRequired = false;
    
    // Check if the field has a required rule
    if (!empty($this->field->rules)) {
      foreach ($this->field->rules as $rule) {
        if (is_array($rule) && in_array('required', $rule)) {
          $isRequired = true;
          break;
        }
      }
    }
    
    // Prepare the asset data for the Vue component
    $assetValue = !empty($this->assetData) ? $this->assetData->uuid : '';

    // Render the container for the Vue component
    $inputName = "CrelishDynamicModel[{$this->field->key}]";
    
    $html = '<div class="form-group field-crelishdynamicmodel-' . $this->formKey . ($isRequired ? ' required' : '') . '">';
    $html .= '<div class="asset-connector-container" ';
    $html .= 'data-field-key="' . htmlspecialchars($this->field->key) . '" ';
    $html .= 'data-label="' . htmlspecialchars($this->field->label) . '" ';
    $html .= 'data-input-name="' . htmlspecialchars($inputName) . '" ';
    $html .= 'data-value="' . htmlspecialchars($assetValue) . '" ';
    $html .= 'data-required="' . ($isRequired ? 'true' : 'false') . '">';
    $html .= '</div>';
    $html .= '<input type="hidden" id="asset_' . $this->formKey . '" name="' . $inputName . '" value="' . htmlspecialchars($assetValue) . '">';
    $html .= '<div class="help-block help-block-error"></div>';
    $html .= '</div>';

    $translations = [
      'labelSelectImage' => Yii::t('app', 'Select Media'),
      'labelChangeImage' => Yii::t('app', 'Change Media'),
      'labelClear' => Yii::t('app', 'Entfernen'),
      'labelUploadNewImage' => Yii::t('app', 'Upload New Media'),
      'labelSearchImages' => Yii::t('app', 'Search medias...'),
      'labelAllFileTypes' => Yii::t('app', 'All file types'),
      'labelJpegImages' => Yii::t('app', 'JPEG images'),
      'labelPngImages' => Yii::t('app', 'PNG images'),
      'labelGifImages' => Yii::t('app', 'GIF images'),
      'labelSvgImages' => Yii::t('app', 'SVG images'),
      'labelPdfDocuments' => Yii::t('app', 'PDF documents'),
      'labelLoadingImages' => Yii::t('app', 'Loading medias...'),
      'labelNoImagesFound' => Yii::t('app', 'No images found. Try adjusting your search or upload a new image.'),
      'labelLoadMore' => Yii::t('app', 'Load More'),
      'labelCancel' => Yii::t('app', 'Cancel'),
      'labelSelect' => Yii::t('app', 'Select'),
      'labelUploadingStatus' => Yii::t('app', 'Uploading...'),
      'labelUploadSuccessful' => Yii::t('app', 'Upload successful!'),
      'labelUploadFailed' => Yii::t('app', 'Upload failed. Please try again.'),
      'titleSelectImage' => Yii::t('app', 'Select Media')
    ];

    $js = "window.assetConnectorTranslations = " . json_encode($translations) . ";";

    Yii::$app->view->registerJs($js, View::POS_HEAD);

    return $html;
  }
} 