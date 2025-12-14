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
  private bool $isMultiple = false;
  private array $multipleAssets = [];
  private ?int $maxItems = null;
  private ?string $mimeFilter = null;

  public function init()
  {
    parent::init();

    // Check for multiple mode from field config
    if (!empty($this->field->config->multiple)) {
      $this->isMultiple = (bool)$this->field->config->multiple;
    }

    // Get maxItems from field config
    if (!empty($this->field->config->maxItems)) {
      $this->maxItems = (int)$this->field->config->maxItems;
    }

    // Get mimeFilter from field config
    if (!empty($this->field->config->mimeFilter)) {
      $this->mimeFilter = $this->field->config->mimeFilter;
    }

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
    // Handle multiple mode
    if ($this->isMultiple) {
      $this->processMultipleData($data);
      return;
    }

    // Single mode processing (existing logic)
    if (!is_object($data)) {
      // Handle null or empty data
      if (empty($data)) {
        $data = ['uuid' => null];
      } elseif (is_string($data) && (str_starts_with($data, '{') || str_starts_with($data, '['))) {
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

  /**
   * Process data for multiple asset mode
   */
  private function processMultipleData($data): void
  {
    $this->multipleAssets = [];

    if (empty($data)) {
      return;
    }

    // Decode JSON string if needed
    if (is_string($data)) {
      if (str_starts_with($data, '[')) {
        $data = Json::decode($data);
      } else {
        // Single UUID string - convert to array
        $data = [$data];
      }
    }

    // Convert object to array
    if (is_object($data)) {
      $data = (array)$data;
    }

    // Process array of UUIDs - make sure we only get simple strings
    if (is_array($data)) {
      foreach ($data as $uuid) {
        // Only add if it's a non-empty string (UUID)
        if (!empty($uuid) && is_string($uuid)) {
          $this->multipleAssets[] = $uuid;
        }
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

    // Render the container for the Vue component
    $inputName = "CrelishDynamicModel[{$this->field->key}]";

    // Prepare asset value based on mode
    if ($this->isMultiple) {
      // Multiple mode: JSON array of UUIDs
      $assetValue = !empty($this->multipleAssets) ? Json::encode($this->multipleAssets) : '[]';
    } else {
      // Single mode: single UUID string
      $assetValue = !empty($this->assetData) ? $this->assetData->uuid : '';
    }

    $html = '<div class="form-group field-crelishdynamicmodel-' . $this->formKey . ($isRequired ? ' required' : '') . '">';
    $html .= '<div class="asset-connector-container" ';
    $html .= 'data-field-key="' . htmlspecialchars($this->field->key) . '" ';
    $html .= 'data-label="' . htmlspecialchars($this->field->label) . '" ';
    $html .= 'data-input-name="' . htmlspecialchars($inputName) . '" ';
    $html .= 'data-value="' . htmlspecialchars($assetValue) . '" ';
    $html .= 'data-required="' . ($isRequired ? 'true' : 'false') . '" ';
    $html .= 'data-multiple="' . ($this->isMultiple ? 'true' : 'false') . '" ';
    if ($this->maxItems !== null) {
      $html .= 'data-max-items="' . $this->maxItems . '" ';
    }
    if ($this->mimeFilter !== null) {
      $html .= 'data-mime-filter="' . htmlspecialchars($this->mimeFilter) . '" ';
    }
    $html .= '>';
    $html .= '</div>';
    $html .= '<input type="hidden" id="asset_' . $this->formKey . '" name="' . $inputName . '" value="' . htmlspecialchars($assetValue) . '">';
    $html .= '<div class="help-block help-block-error"></div>';
    $html .= '</div>';

    $translations = [
      'labelSelectImage' => Yii::t('app', 'Select Media'),
      'labelChangeImage' => Yii::t('app', 'Change Media'),
      'labelClear' => Yii::t('app', 'Entfernen'),
      'labelClearAll' => Yii::t('app', 'Alle entfernen'),
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
      'titleSelectImage' => Yii::t('app', 'Select Media'),
      'titleSelectImages' => Yii::t('app', 'Select Media'),
      'labelAddMore' => Yii::t('app', 'Weitere hinzufügen'),
      'labelSelectedCount' => Yii::t('app', '{count} ausgewählt'),
      'labelMaxItemsReached' => Yii::t('app', 'Maximum erreicht ({max})'),
      'labelDragToReorder' => Yii::t('app', 'Ziehen zum Sortieren'),
    ];

    $js = "window.assetConnectorTranslations = " . json_encode($translations) . ";";

    Yii::$app->view->registerJs($js, View::POS_HEAD);

    return $html;
  }
} 