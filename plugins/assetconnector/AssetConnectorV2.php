<?php
namespace giantbits\crelish\plugins\assetconnector;

use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use giantbits\crelish\components\CrelishInputWidget;
use giantbits\crelish\components\CrelishDynamicModel;

/**
 * Class AssetConnectorV2
 * 
 * Improved AssetConnector widget using the new architecture
 * 
 * @package giantbits\crelish\plugins\assetconnector
 */
class AssetConnectorV2 extends CrelishInputWidget
{
    /**
     * @var CrelishDynamicModel|null The loaded asset data
     */
    protected $assetData = null;
    
    /**
     * @var string Asset type filter
     */
    public $assetType = 'all';
    
    /**
     * @var string Accept attribute for file input
     */
    public $accept = '*/*';
    
    /**
     * @var bool Allow multiple file selection
     */
    public $multiple = false;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        // Check for multiple mode in field config BEFORE calling parent init
        if ($this->field && isset($this->field->config)) {
            if (isset($this->field->config->multiple)) {
                $this->multiple = (bool)$this->field->config->multiple;
            } elseif (is_array($this->field->config) && isset($this->field->config['multiple'])) {
                $this->multiple = (bool)$this->field->config['multiple'];
            }
        }
        
        // Debug log the multiple flag
        Yii::info("AssetConnectorV2 init - multiple flag: " . ($this->multiple ? 'true' : 'false'), __METHOD__);
        
        parent::init();
    }

    /**
     * {@inheritdoc}
     */
    protected function registerWidgetAssets()
    {
        // Set the asset path
        $this->assetPath = Yii::getAlias('@vendor/giantbits/yii2-crelish/resources/asset-connector/dist/asset-connector.js');
        
        // Register the main asset
        parent::registerWidgetAssets();
        
        // Register translations
        $this->registerTranslations();
        
        // Register initialization script AFTER the main asset
        $initScript = $this->getInitializationScript();
        if ($initScript) {
            $this->view->registerJs($initScript, \yii\web\View::POS_READY, 'asset-connector-init-' . $this->getId());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function processData($data)
    {
        // Debug logging
        Yii::info("AssetConnectorV2 processData - multiple: " . ($this->multiple ? 'true' : 'false') . ", data type: " . gettype($data) . ", data: " . (is_string($data) ? $data : Json::encode($data)), __METHOD__);
        
        if ($this->multiple) {
            // Handle multiple assets - extract only UUIDs for storage
            if (empty($data)) {
                return [];
            }
            
            $uuids = [];
            
            if (is_string($data)) {
                if (substr($data, 0, 1) == '[') {
                    $decoded = Json::decode($data);
                    $uuids = $this->extractUuidsFromArray($decoded);
                } else {
                    // Single UUID as string, convert to array
                    $uuids = [$data];
                }
            } elseif (is_array($data)) {
                $uuids = $this->extractUuidsFromArray($data);
            } else {
                $uuids = [$data];
            }
            
            // Return only valid UUIDs, filtered
            return array_filter($uuids, function($uuid) {
                return !empty($uuid) && is_string($uuid);
            });
            
        } else {
            // Handle single asset (backward compatible - store only UUID string)
            $uuid = null;
            
            if (!is_object($data)) {
                // Handle null or empty data
                if (empty($data)) {
                    $uuid = null;
                } elseif (is_string($data)) {
                    if (substr($data, 0, 1) == '{' || substr($data, 0, 1) == '[') {
                        $decoded = Json::decode($data);
                        $uuid = is_array($decoded) && isset($decoded['uuid']) ? $decoded['uuid'] : (is_object($decoded) && isset($decoded->uuid) ? $decoded->uuid : null);
                    } else {
                        $uuid = $data; // Direct UUID string
                    }
                }
            } else {
                // Extract UUID from object
                $uuid = isset($data->uuid) ? $data->uuid : null;
            }

            // Set up assetData for internal use but return only UUID
            if (!empty($uuid)) {
                $this->assetData = new CrelishDynamicModel([
                    'ctype' => 'asset', 
                    'uuid' => $uuid
                ]);
            }
            
            // Return only the UUID string for backward compatibility
            return $uuid;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function renderWidget()
    {
        // Get asset value
        if ($this->multiple) {
            $assetValue = is_array($this->data) ? $this->data : [];
            
            // Debug logging
            if (!empty($assetValue)) {
                Yii::info("AssetConnectorV2 multiple mode - data: " . Json::encode($assetValue), __METHOD__);
            }
        } else {
            // For single mode, $this->data now contains just the UUID string
            $assetValue = $this->data ?: '';
        }
        
        // Build container attributes
        $containerOptions = [
            'class' => 'asset-connector-container',
            'data-field-key' => $this->formKey,
            'data-label' => $this->field->label ?? $this->attribute,
            'data-input-name' => $this->getInputName(),
            'data-value' => $this->multiple ? Json::encode($assetValue) : ($assetValue ?: ''),
            'data-required' => $this->isRequired() ? 'true' : 'false',
            'data-asset-type' => $this->assetType,
            'data-accept' => $this->accept,
            'data-multiple' => $this->multiple ? 'true' : 'false',
        ];
        
        // Add translations to container
        if (!empty($this->translations)) {
            $containerOptions['data-translations'] = Json::encode($this->translations);
        }
        
        // Debug output
        Yii::info("AssetConnectorV2 render - multiple: " . ($this->multiple ? 'true' : 'false') . ", data-value: " . $containerOptions['data-value'], __METHOD__);
        
        // Add config data if available
        if ($this->field && isset($this->field->config)) {
            $containerOptions['data-config'] = Json::encode($this->field->config);
        }
        
        // Build HTML
        $html = Html::beginTag('div', ['class' => 'form-group field-' . Html::getInputId($this->model, $this->attribute) . ($this->isRequired() ? ' required' : '')]);
        
        // Add label if needed (for standalone usage)
        if ($this->field && !empty($this->field->label)) {
            $html .= Html::label($this->field->label, $this->getInputId(), [
                'class' => 'control-label' . ($this->isRequired() ? ' required' : '')
            ]);
        }
        
        $html .= Html::tag('div', '', $containerOptions);
        $html .= Html::hiddenInput(
            $this->getInputName(), 
            $this->multiple ? Json::encode($assetValue) : ($assetValue ?: ''), 
            ['id' => $this->getInputId()]
        );
        $html .= Html::tag('div', '', ['class' => 'help-block help-block-error']);
        $html .= Html::endTag('div');
        
        return $html;
    }

    /**
     * {@inheritdoc}
     */
    public function getInitializationScript()
    {
        $containerId = Html::getInputId($this->model, $this->attribute);
        $config = Json::encode($this->getClientConfig());
        
        return "
        (function() {
            function initializeAssetConnectorWidget() {
                const hiddenInput = document.getElementById('{$containerId}');
                if (hiddenInput) {
                    const container = hiddenInput.closest('.form-group').querySelector('.asset-connector-container');
                    if (container) {
                        // Check if already initialized
                        if (container.dataset.initialized === 'true') {
                            return;
                        }
                        
                        // Mark as initialized
                        container.dataset.initialized = 'true';
                        
                        // Try to use the global AssetConnector initializer first
                        if (typeof window.initializeAssetConnector === 'function') {
                            try {
                                window.initializeAssetConnector(container);
                                console.log('AssetConnector V2 initialized via global function');
                                return;
                            } catch (e) {
                                console.warn('Global AssetConnector initializer failed:', e);
                            }
                        }
                        
                        // Fallback: Create a simple asset selector
                        if (!container.querySelector('.asset-connector-fallback')) {
                            const fallbackDiv = document.createElement('div');
                            fallbackDiv.className = 'asset-connector-fallback';
                            fallbackDiv.innerHTML = `
                                <div style=\"border: 2px dashed #007cba; padding: 15px; border-radius: 4px; background: #f0f8ff;\">
                                    <div style=\"margin-bottom: 10px;\">
                                        <label style=\"display: block; font-weight: bold; margin-bottom: 5px;\">Asset UUID:</label>
                                        <input type=\"text\" 
                                               class=\"form-control asset-uuid-input\"
                                               placeholder=\"Enter or paste asset UUID...\" 
                                               value=\"\${hiddenInput.value || ''}\" 
                                               style=\"width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;\">
                                    </div>
                                    <small style=\"color: #666;\">AssetConnector V2 - Fallback Mode</small>
                                </div>
                            `;
                            
                            container.appendChild(fallbackDiv);
                            
                            // Set up value sync
                            const uuidInput = fallbackDiv.querySelector('.asset-uuid-input');
                            uuidInput.addEventListener('input', function() {
                                hiddenInput.value = this.value;
                                hiddenInput.dispatchEvent(new Event('change'));
                            });
                            
                            console.log('AssetConnector V2 initialized in fallback mode');
                        }
                    }
                } else {
                    console.warn('AssetConnector V2: Hidden input not found with ID: {$containerId}');
                }
            }
            
            // Try immediate initialization
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initializeAssetConnectorWidget);
            } else {
                // DOM is already loaded, initialize immediately
                initializeAssetConnectorWidget();
            }
        })();
        ";
    }

    /**
     * {@inheritdoc}
     */
    public function loadTranslations()
    {
        $this->translations = [
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
            'titleSelectImage' => Yii::t('app', 'Select Media'),
            // Multiple selection labels
            'labelSelectFiles' => Yii::t('app', 'Select Files'),
            'labelNoFilesSelected' => Yii::t('app', 'No files selected'),
            'labelAddMoreFiles' => Yii::t('app', 'Add More Files'),
            'labelClearAll' => Yii::t('app', 'Clear All'),
            'labelFileSelected' => Yii::t('app', 'file selected'),
            'labelFilesSelected' => Yii::t('app', 'files selected'),
            'labelSelectFile' => Yii::t('app', 'Select File'),
            'labelChangeFile' => Yii::t('app', 'Change File'),
            'labelNoFileSelected' => Yii::t('app', 'No file selected')
        ];
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
     * Register widget translations
     */
    protected function registerTranslations()
    {
        $js = "window.assetConnectorTranslations = " . Json::encode($this->translations) . ";";
        $this->view->registerJs($js, \yii\web\View::POS_HEAD);
    }

    /**
     * {@inheritdoc}
     */
    public function getClientConfig()
    {
        $config = parent::getClientConfig();
        
        // Add asset-specific configuration
        $config['assetType'] = $this->assetType;
        $config['accept'] = $this->accept;
        $config['multiple'] = $this->multiple;
        $config['assetData'] = $this->assetData ? $this->assetData->attributes : null;
        
        return $config;
    }
    
    /**
     * Extract UUIDs from mixed array data
     * 
     * @param array $data
     * @return array
     */
    protected function extractUuidsFromArray($data)
    {
        $uuids = [];
        
        foreach ($data as $item) {
            if (is_string($item)) {
                // Direct UUID string
                $uuids[] = $item;
            } elseif (is_array($item) && isset($item['uuid'])) {
                // Array with uuid key
                $uuids[] = $item['uuid'];
            } elseif (is_object($item) && isset($item->uuid)) {
                // Object with uuid property
                $uuids[] = $item->uuid;
            }
        }
        
        return $uuids;
    }
}