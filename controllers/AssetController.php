<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:19
 */

namespace giantbits\crelish\controllers;

use Cocur\Slugify\Slugify;
use app\workspace\models\Asset;
use app\workspace\models\Document;
use ColorThief\ColorThief;
use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\CrelishDynamicModel;
use League\Glide\ServerFactory;
use Mpdf\MpdfException;
use setasign\Fpdi\PdfParser\PdfParserException;
use Yii;
use yii\base\Exception;
use yii\base\Model;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\Response;
use yii\web\UploadedFile;
use yii\helpers\Url;
use giantbits\crelish\components\CrelishDataManager;
use yii\filters\AccessControl;
use function _\map;

class ExtractModel extends Model
{
  public $start;
  public $end;
  public $title;
  public $description;
  public $author;
}

class AssetController extends CrelishBaseController
{

  public $layout = 'crelish.twig';

  public function behaviors()
  {
    return [
      'access' => [
        'class' => AccessControl::class,
        'rules' => [
          [
            'allow' => true,
            'actions' => ['login', 'glide', 'api-search', 'api-get', 'api-upload'],
            'roles' => ['?', '@'], // Allow both guests and authenticated users to access these endpoints
          ],
          [
            'allow' => true,
            'actions' => [],
            'roles' => ['@'],
          ],
        ],
      ],
    ];
  }

  public function init()
  {
    parent::init();
    $this->enableCsrfValidation = false;
  }

  public function actions(): array
  {
    return [
      'glide' => 'giantbits\crelish\actions\GlideAction'
    ];
  }

  /**
   * Override the setupHeaderBar method to use asset-specific components
   */
  protected function setupHeaderBar()
  {
    // Default left components for all actions
    $this->view->params['headerBarLeft'] = ['toggle-sidebar'];

    // Default right components (empty by default)
    $this->view->params['headerBarRight'] = [];

    // Set specific components based on action
    $action = $this->action ? $this->action->id : null;

    switch ($action) {
      case 'index':
        // For asset index, use search and view controls
        $this->view->params['headerBarLeft'][] = 'search';
        $this->view->params['headerBarRight'] = ['delete', 'asset-view-controls'];
        break;

      case 'update':
        // For update actions, add back button and save buttons
        $this->view->params['headerBarLeft'][] = 'back-button';
        $this->view->params['headerBarRight'] = [['save', true, true]];
        break;

      default:
        // For other actions, just keep the defaults
        break;
    }
  }

  public function actionGlideInt()
  {
    $path = Yii::$app->request->get('path', null);
    $params = Yii::$app->request->getQueryParams();
    unset($params['path']);

    $server = ServerFactory::create([
      'source' => Yii::getAlias('@app/web/uploads'),
      'cache' => Yii::getAlias('@runtime/glide'),
      'presets' => Yii::$app->params['crelish']['glide_presets'],
      'driver' => 'imagick',
    ]);

    if (file_exists(Yii::getAlias('@app/web/uploads') . '/' . $path)) {
      //$server->outputImage($path, $params);

      $oneYearInSeconds = 60 * 60 * 24 * 365;
      $response = $server->getImageResponse($path, $params);

      Yii::$app->response->format = Response::FORMAT_RAW;
      Yii::$app->response->headers->add('Content-Type', $response->getHeaders()['Content-Type']);
      Yii::$app->response->headers->set('Cache-Control', 'max-age=' . $oneYearInSeconds . ', public');

      // Send the image content
      return $response->getContent();
    }
  }

  public function actionIndex()
  {
    $this->enableCsrfValidation = false;
    $filter = null;

    $modelClass = '\app\workspace\models\Asset';
    if (!empty($_POST['selection'])) {
      if (class_exists($modelClass)) {
        foreach ($_POST['selection'] as $selection) {
          $delModel = $modelClass::findOne($selection);
          $delModel->delete();
        }
      }
    }

    // Handle content filtering
    $searchTerm = $this->handleSessionAndQueryParams('cr_content_filter');
    if (!empty($searchTerm)) {
      $filter = ['freesearch' => $searchTerm];
    }

    if (empty($_GET['sort'])) {
      $_GET['sort'] = "-created";
    }

    $dataManager = new CrelishDataManager('asset', ['filter' => $filter]);
    $checkCol = [
      [
        'class' => 'giantbits\crelish\components\CrelishCheckboxColumn',
      ],
      [
        'label' => Yii::t('app', 'Preview'),
        'format' => 'raw',
        'value' => function ($model) {
          $preview = Yii::t('app', 'n/a');

          switch ($model['mime']) {
            case 'image/jpg':
            case 'image/jpeg':
            case 'image/webp':
            case 'image/gif':
            case 'image/png':
              $preview = Html::img('/crelish/asset/glide?path=' . CrelishBaseHelper::getAssetUrl($model['pathName'], $model['fileName']) . '&w=160&f=fit', ['style' => 'width: 80px; height: auto;']);
              break;
            case 'image/svg+xml':
              $preview = Html::img($model['pathName'] . $model['src'], ['style' => 'width: 80px; height: auto;']);
              break;
            case 'application/pdf':
              $preview = Html::img('/crelish/asset/glide?path=thumbs/' . $model['thumbnail'] . '&p=small', ['style' => 'width: 80px; height: auto;']);
              break;
          }

          return $preview;
        }
      ]
    ];
    $columns = array_merge($checkCol, $dataManager->getColumns());
    $columns = map($columns, function ($item) use ($dataManager) {
      if (key_exists('attribute', $item) && $item['attribute'] === 'state') {
        $item['format'] = 'raw';
        $item['label'] = 'Status';
        $item['value'] = function ($data) {
          switch ($data['state']) {
            case 1:
              $state = Yii::t('i18n', 'Entwurf');
              break;
            case 2:
              $state = Yii::t('i18n', 'Online');
              break;
            case 3:
              $state = Yii::t('i18n', 'Archiviert');
              break;
            default:
              $state = Yii::t('i18n', 'Offline');
          };

          return $state;
        };
      }
      return $item;
    });
    $rowOptions = function ($model, $key, $index, $grid) {
      return ['onclick' => 'location.href="update?ctype=asset&uuid=' . $model['uuid'] . '";'];
    };

    return $this->render('index.twig', [
      'dataProvider' => $dataManager->getProvider(),
      'filterProvider' => $dataManager->getFilters(),
      'columns' => $columns,
      'ctype' => $this->ctype,
      'rowOptions' => $rowOptions
    ]);
  }

  public function actionUpdate()
  {
    $uuid = !empty(Yii::$app->getRequest()->getQueryParam('uuid')) ? Yii::$app->getRequest()->getQueryParam('uuid') : null;
    $model = new CrelishDynamicModel(['uuid' => $uuid, 'ctype' => 'asset']);

    // Save content if post request for asset.
    if (!empty(Yii::$app->request->post('CrelishDynamicModel')) && !Yii::$app->request->isAjax) {
      $model->attributes = $_POST['CrelishDynamicModel'];

      if ($model->validate()) {
        $model->save();

        if (!empty($_POST['save_n_return']) && $_POST['save_n_return'] == "1") {
          header('Location: ' . Url::to([
              'asset/index'
            ]));

          exit(0);
        }

        Yii::$app->session->setFlash('success', 'Asset saved successfully...');
        header("Location: " . Url::to(['asset/update', 'uuid' => $model->uuid]));
        exit(0);
      } else {
        Yii::$app->session->setFlash('error', 'Asset save failed...');
      }
    }

    $alerts = '';
    foreach (Yii::$app->session->getAllFlashes() as $key => $message) {
      $alerts .= '<div class="c-alerts__alert c-alerts__alert--' . $key . '">' . $message . '</div>';
    }

    // Check if this is an image asset that can be edited
    $showImageEditor = false;
    $editableImageTypes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    if (in_array($model->mime, $editableImageTypes)) {
      $showImageEditor = true;
    }

    if (in_array($model->mime, ['image/jpg', 'image/jpeg', 'image/png', 'image/bmp', 'image/gif'])) {
      $srcName = CrelishBaseHelper::getAssetUrl($model->pathName, $model->fileName);

      try {
        $targetFile = Yii::getAlias('@webroot') . $srcName;
        $domColor = @ColorThief::getColor($targetFile, 20);
        $palColor = @ColorThief::getPalette($targetFile);

        $colormain_rgb = Json::encode($domColor);
        $colormain_hex = '#' . sprintf('%02x', $domColor[0]) . sprintf('%02x', $domColor[1]) . sprintf('%02x', $domColor[2]);
      } catch (\Exception $e) {

      }
    }

    $extractedDoc = new Asset();

    // Register the image editor assets if needed
    if ($showImageEditor) {
      $assetManager = Yii::$app->assetManager;

      // Define the source path of your JS file
      $sourcePath = Yii::getAlias('@vendor/giantbits/yii2-crelish/resources/image-editor/dist/image-editor.js');

      // Publish the file and get the published URL
      $publishedUrl = $assetManager->publish($sourcePath, [
        'forceCopy' => YII_DEBUG,
        'appendTimestamp' => true,
      ])[1];

      // Register the script in the view
      $this->view->registerJsFile($publishedUrl);    
    }

    return $this->render('update.twig', [
      'model' => $model,
      'extractModel' => $extractedDoc,
      'colormain_hex' => $colormain_hex ?? null,
      'colorpalette' => $palColor ?? null,
      'alerts' => $alerts,
      'showImageEditor' => $showImageEditor
    ]);
  }

  /**
   * @throws PdfParserException
   * @throws MpdfException
   */
  private function extractFromPdf($uuid)
  {
    $data = (object)Yii::$app->request->post('ExtractModel');

    $asset = Asset::findOne(['uuid' => $uuid]);
    if ($newFile = $asset->splitPdf($data->start, $data->end, $data->title)) {

      $document = new Document();
      $document->asset = $newFile->uuid;
      $document->title = 'demo';
      $document->save(false);

      Yii::$app->session->setFlash('success', 'Neue PDF-Datei wurde erzeugt.');
      ob_clean();
      header("Location: " . Url::to(['asset/index']));
      exit(0);
    }
  }

  public function actionUpload()
  {
    $file = UploadedFile::getInstanceByName('file');

    $slugger = new Slugify();
    $mimeTypesToExtensions = [
      // Images
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
      'image/svg+xml' => 'svg',

      // Adobe PDF
      'application/pdf' => 'pdf',

      // Microsoft Office
      'application/msword' => 'doc',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
      'application/vnd.ms-excel' => 'xls',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
      'application/vnd.ms-powerpoint' => 'ppt',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',

      // OpenOffice formats
      'application/vnd.oasis.opendocument.text' => 'odt',
      'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
      'application/vnd.oasis.opendocument.presentation' => 'odp',

      // Text files
      'text/plain' => 'txt',
      'text/csv' => 'csv',
      'text/html' => 'html',
      'text/css' => 'css',
      'text/javascript' => 'js',
      'application/json' => 'json',
      'application/xml' => 'xml',

      // Archives
      'application/zip' => 'zip',
      'application/x-rar-compressed' => 'rar',
      'application/x-tar' => 'tar',
      'application/gzip' => 'gz',

      // Audio formats
      'audio/mpeg' => 'mp3',
      'audio/ogg' => 'ogg',
      'audio/wav' => 'wav',

      // Video formats
      'video/mp4' => 'mp4',
      'video/webm' => 'webm',
      'video/ogg' => 'ogv',

      // Other formats
      'application/octet-stream' => 'bin' // General binary file
      // Add more types as needed
    ];

    if ($file) {
      $mimeType = mime_content_type($file->tempName);
      $mimeTypeExt = $mimeTypesToExtensions[$mimeType];
    }

    if ($file && $mimeTypeExt) {
      $destName = time() . '_' . $slugger->slugify($file->name) . '.' . $mimeTypeExt;
      $targetFile = Yii::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $destName;

      if ($file->saveAs($targetFile)) {
        $model = new CrelishDynamicModel(['ctype' => 'asset']);
        $model->systitle = $destName;
        $model->title = $destName;
        //$model->src = \Yii::getAlias('@webroot') . '/' . 'uploads' . '/' . $destName;
        $model->src = $destName;
        $model->fileName = $destName;
        $model->pathName = '/' . 'uploads' . '/';
        $model->mime = $mimeType;
        $model->size = $file->size;
        $model->state = 2;

        try {
          //$domColor = ColorThief::getColor($targetFile, 20);
          //$palColor = ColorThief::getPalette(\Yii::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $destName);

          //$model->colormain_rgb = Json::encode($domColor);
          //$model->colormain_hex = '#' . sprintf('%02x', $domColor[0]) . sprintf('%02x', $domColor[1]) . sprintf('%02x', $domColor[2]);
          //$model->colorpalette = Json::encode($palColor);

        } catch (Exception $e) {
          Yii::$app->session->setFlash('secondary', 'Color theft could not be completed. (Image too large?)');
        }
        $model->save();
      } else {
        throw new \yii\web\BadRequestHttpException('File could not be saved on server.');
      }
    }

    return false;
  }

  public function actionDelete()
  {
    $uuid = !empty(Yii::$app->getRequest()->getQueryParam('uuid')) ? Yii::$app->getRequest()->getQueryParam('uuid') : null;
    $modelProvider = new CrelishDynamicModel(['ctype' => 'asset', 'uuid' => $uuid]);
    if (@unlink(Yii::getAlias('@webroot') . $modelProvider->src) || !file_exists(Yii::getAlias('@webroot') . $modelProvider->src)) {
      $modelProvider->delete();
      Yii::$app->session->setFlash('success', 'Asset deleted successfully...');
      header("Location: " . Url::to(['asset/index']));
      exit(0);
    };


    Yii::$app->session->setFlash('danger', 'Asset could not be deleted...');
    header("Location: " . Url::to(['asset/index', ['uuid' => $modelProvider->uuid]]));
    exit(0);
  }

  /**
   * Search assets and return JSON response
   * This action supports filtering by search term and mime type
   *
   * @return array JSON response with assets
   */
  public function actionApiSearch()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    // Get search parameters
    $searchTerm = Yii::$app->request->get('q', '');
    $mimeType = Yii::$app->request->get('mime', '');
    $page = (int)Yii::$app->request->get('page', 1);
    $limit = (int)Yii::$app->request->get('limit', 20);

    // Calculate offset for pagination
    $offset = ($page - 1) * $limit;

    // Build the filter for the data provider
    $filter = [];

    if (!empty($searchTerm)) {
      $filter['freesearch'] = $searchTerm;
    }

    if (!empty($mimeType)) {
      $filter['mime'] = $mimeType;
    }

    // Create data manager with filters
    $dataManager = new CrelishDataManager('asset', [
      'filter' => $filter,
      'pageSize' => $limit,
      'sort' => ['defaultOrder' => ['created' => SORT_DESC]] // Correct format for sorting
    ]);

    // Get data provider and models
    $provider = $dataManager->getProvider();
    $totalCount = $provider->getTotalCount();
    $models = $provider->getModels();

    // Format the data for the response
    $items = [];
    foreach ($models as $model) {
      $previewUrl = '';

      // Generate preview URL based on mime type
      switch ($model['mime']) {
        case 'image/jpg':
        case 'image/jpeg':
        case 'image/gif':
        case 'image/png':
        case 'image/webp':
          $previewUrl = '/crelish/asset/glide?path=' . CrelishBaseHelper::getAssetUrl($model['pathName'], $model['fileName']) . '&w=180&h=150&f=fit';
          break;
        case 'image/svg+xml':
          $previewUrl = $model['pathName'] . $model['src'];
          break;
        case 'application/pdf':
          $previewUrl = '/crelish/asset/glide?path=thumbs/' . $model['thumbnail'] . '&p=small';
          break;
        default:
          // Default placeholder for unsupported file types
          $previewUrl = '/crelish/asset/glide?path=placeholders/file.png&w=180&h=150&f=fit';
      }

      $items[] = [
        'uuid' => $model['uuid'],
        'title' => $model['title'] ?? $model['systitle'] ?? 'Untitled',
        'mime' => $model['mime'],
        'preview_url' => $previewUrl,
        'full_url' => CrelishBaseHelper::getAssetUrl($model['pathName'], $model['fileName']),
        'created' => $model['created']
      ];
    }

    return [
      'items' => $items,
      'total' => $totalCount,
      'page' => $page,
      'pages' => ceil($totalCount / $limit)
    ];
  }

  /**
   * Get a single asset by UUID
   *
   * @return array JSON response with asset details
   */
  public function actionApiGet()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $uuid = Yii::$app->request->get('uuid');
    if (empty($uuid)) {
      return ['error' => 'Missing UUID parameter'];
    }

    $model = Asset::findOne(['uuid' => $uuid]);
    if (!$model) {
      return ['error' => 'Asset not found'];
    }

    $previewUrl = '';

    // Generate preview URL based on mime type
    switch ($model->mime) {
      case 'image/jpg':
      case 'image/jpeg':
      case 'image/gif':
      case 'image/png':
      case 'image/webp':
        $previewUrl = '/crelish/asset/glide?path=' . CrelishBaseHelper::getAssetUrl($model->pathName, $model->fileName) . '&w=180&h=150&f=fit';
        break;
      case 'image/svg+xml':
        $previewUrl = $model->pathName . $model->src;
        break;
      case 'application/pdf':
        $previewUrl = '/crelish/asset/glide?path=thumbs/' . $model->thumbnail . '&p=small';
        break;
      default:
        // Default placeholder for unsupported file types
        $previewUrl = '/crelish/asset/glide?path=placeholders/file.png&w=180&h=150&f=fit';
    }

    return [
      'uuid' => $model->uuid,
      'title' => $model->title ?? $model->systitle ?? 'Untitled',
      'mime' => $model->mime,
      'preview_url' => $previewUrl,
      'full_url' => CrelishBaseHelper::getAssetUrl($model->pathName, $model->fileName),
      'created' => $model->created
    ];
  }

  /**
   * Upload files via API and return JSON response
   *
   * @return array JSON response with upload status
   */
  public function actionApiUpload()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $file = UploadedFile::getInstanceByName('file');

    if (!$file) {
      return [
        'success' => false,
        'message' => 'No file uploaded'
      ];
    }

    $slugger = new Slugify();
    $mimeTypesToExtensions = [
      // Images
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
      'image/svg+xml' => 'svg',

      // Adobe PDF
      'application/pdf' => 'pdf',

      // Microsoft Office
      'application/msword' => 'doc',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
      'application/vnd.ms-excel' => 'xls',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
      'application/vnd.ms-powerpoint' => 'ppt',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',

      // OpenOffice formats
      'application/vnd.oasis.opendocument.text' => 'odt',
      'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
      'application/vnd.oasis.opendocument.presentation' => 'odp',

      // Text files
      'text/plain' => 'txt',
      'text/csv' => 'csv',
      'text/html' => 'html',
      'text/css' => 'css',
      'text/javascript' => 'js',
      'application/json' => 'json',
      'application/xml' => 'xml',

      // Archives
      'application/zip' => 'zip',
      'application/x-rar-compressed' => 'rar',
      'application/x-tar' => 'tar',
      'application/gzip' => 'gz',

      // Audio formats
      'audio/mpeg' => 'mp3',
      'audio/ogg' => 'ogg',
      'audio/wav' => 'wav',

      // Video formats
      'video/mp4' => 'mp4',
      'video/webm' => 'webm',
      'video/ogg' => 'ogv',

      // Other formats
      'application/octet-stream' => 'bin' // General binary file
    ];

    $mimeType = mime_content_type($file->tempName);
    $mimeTypeExt = $mimeTypesToExtensions[$mimeType] ?? null;

    if (!$mimeTypeExt) {
      return [
        'success' => false,
        'message' => 'Unsupported file type: ' . $mimeType
      ];
    }

    $destName = time() . '_' . $slugger->slugify($file->name) . '.' . $mimeTypeExt;
    $targetFile = Yii::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $destName;

    if (!$file->saveAs($targetFile)) {
      return [
        'success' => false,
        'message' => 'Failed to save file on server'
      ];
    }

    // Create the asset record
    $model = new CrelishDynamicModel(['ctype' => 'asset']);
    $model->systitle = $destName;
    $model->title = $destName;
    $model->src = $destName;
    $model->fileName = $destName;
    $model->pathName = '/' . 'uploads' . '/';
    $model->mime = $mimeType;
    $model->size = $file->size;
    $model->state = 2;

    // Try to get color information for images
    if (in_array($mimeType, ['image/jpg', 'image/jpeg', 'image/png', 'image/bmp', 'image/gif'])) {
      try {
        $domColor = ColorThief::getColor($targetFile, 20);
        $palColor = ColorThief::getPalette($targetFile);

        $model->colormain_rgb = Json::encode($domColor);
        $model->colormain_hex = '#' . sprintf('%02x', $domColor[0]) . sprintf('%02x', $domColor[1]) . sprintf('%02x', $domColor[2]);
        $model->colorpalette = Json::encode($palColor);
      } catch (Exception $e) {
        // Silently ignore color extraction errors
      }
    }

    if (!$model->save()) {
      return [
        'success' => false,
        'message' => 'Failed to save asset record: ' . implode(', ', $model->getErrorSummary(true))
      ];
    }

    // Generate preview URL based on mime type
    $previewUrl = '';
    switch ($mimeType) {
      case 'image/jpg':
      case 'image/jpeg':
      case 'image/gif':
      case 'image/png':
      case 'image/webp':
        $previewUrl = '/crelish/asset/glide?path=' . CrelishBaseHelper::getAssetUrl($model->pathName, $model->fileName) . '&w=180&h=150&f=fit';
        break;
      case 'image/svg+xml':
        $previewUrl = $model->pathName . $model->src;
        break;
      case 'application/pdf':
        $previewUrl = '/crelish/asset/glide?path=thumbs/' . $model->thumbnail . '&p=small';
        break;
      default:
        // Default placeholder for unsupported file types
        $previewUrl = '/crelish/asset/glide?path=placeholders/file.png&w=180&h=150&f=fit';
    }

    return [
      'success' => true,
      'message' => 'File uploaded successfully',
      'asset' => [
        'uuid' => $model->uuid,
        'title' => $model->title ?? $model->systitle ?? 'Untitled',
        'mime' => $model->mime,
        'preview_url' => $previewUrl,
        'full_url' => CrelishBaseHelper::getAssetUrl($model->pathName, $model->fileName),
        'created' => $model->created
      ]
    ];
  }

  /**
   * API endpoint for saving an edited version of an asset
   * 
   * @return array JSON response with the new asset
   */
  public function actionApiSaveEditedAsset()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;
    
    // Get parameters
    $originalUuid = Yii::$app->request->post('original_uuid');
    $editParams = Yii::$app->request->post('edit_params');
    $editType = Yii::$app->request->post('edit_type');
    
    if (empty($originalUuid) || empty($editParams) || empty($editType)) {
      return [
        'success' => false,
        'message' => 'Missing required parameters'
      ];
    }
    
    // Find original asset
    $originalAsset = Asset::findOne(['uuid' => $originalUuid]);
    if (!$originalAsset) {
      return [
        'success' => false,
        'message' => 'Original asset not found'
      ];
    }
    
    // Only allow editing of image assets
    $allowedMimeTypes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($originalAsset->mime, $allowedMimeTypes)) {
      return [
        'success' => false,
        'message' => 'Only image assets can be edited'
      ];
    }
    
    // Create new asset record
    $newAsset = new Asset();
    $newAsset->attributes = $originalAsset->attributes;
    $newAsset->uuid = CrelishBaseHelper::GUIDv4();
    $newAsset->parent_uuid = $originalUuid;
    $newAsset->edit_params = Json::encode($editParams);
    $newAsset->edit_type = $editType;
    $newAsset->is_original = false;
    
    // Modify title to indicate it's an edited version
    $pathInfo = pathinfo($originalAsset->fileName);
    $newTitle = $pathInfo['filename'] . '_' . $editType;
    if (isset($pathInfo['extension'])) {
      $newTitle .= '.' . $pathInfo['extension'];
    }
    $newAsset->systitle = $newTitle;
    $newAsset->title = $newTitle;
    
    // The actual file doesn't need to be duplicated since Glide will generate it on demand
    
    if ($newAsset->save()) {
      // Generate preview URL based on mime type and edit params
      $previewUrl = $this->generatePreviewUrl($newAsset, $editParams);
      
      return [
        'success' => true,
        'asset' => [
          'uuid' => $newAsset->uuid,
          'title' => $newAsset->title,
          'mime' => $newAsset->mime,
          'preview_url' => $previewUrl,
          'full_url' => CrelishBaseHelper::getAssetUrl($newAsset->pathName, $newAsset->fileName),
          'created' => $newAsset->created,
          'edit_type' => $newAsset->edit_type,
          'parent_uuid' => $newAsset->parent_uuid
        ]
      ];
    } else {
      return [
        'success' => false,
        'message' => 'Failed to save edited asset: ' . implode(', ', $newAsset->getErrorSummary(true))
      ];
    }
  }
  
  /**
   * Generate a preview URL for an asset, applying any edit parameters
   * 
   * @param Asset $asset The asset
   * @param array|null $editParams Optional edit parameters to apply
   * @return string The preview URL
   */
  protected function generatePreviewUrl($asset, $editParams = null)
  {
    $baseUrl = '/crelish/asset/glide?path=' . CrelishBaseHelper::getAssetUrl($asset->pathName, $asset->fileName);
    
    // Add edit parameters if provided
    if (!empty($editParams)) {
      // Convert edit parameters to Glide parameters
      $glideParams = $this->convertToGlideParams($editParams);
      if (!empty($glideParams)) {
        $baseUrl .= '&' . http_build_query($glideParams);
      }
    } else if (!$asset->is_original && !empty($asset->edit_params)) {
      // Use stored edit parameters for edited assets
      $storedParams = Json::decode($asset->edit_params);
      $glideParams = $this->convertToGlideParams($storedParams);
      if (!empty($glideParams)) {
        $baseUrl .= '&' . http_build_query($glideParams);
      }
    }
    
    // Add standard preview parameters
    $baseUrl .= '&w=180&h=150&f=fit';
    
    return $baseUrl;
  }
  
  /**
   * Convert editor parameters to Glide parameters
   * 
   * @param array $editParams Editor parameters
   * @return array Glide parameters
   */
  protected function convertToGlideParams($editParams)
  {
    $glideParams = [];
    
    // Handle crop parameters
    if (isset($editParams['crop']) && is_array($editParams['crop'])) {
      $crop = $editParams['crop'];
      if (isset($crop['x'], $crop['y'], $crop['width'], $crop['height'])) {
        // Convert percentage values to Glide's crop format
        $glideParams['crop'] = $crop['width'] . ',' . $crop['height'] . ',' . $crop['x'] . ',' . $crop['y'];
      }
    }
    
    // Handle rotation
    if (isset($editParams['rotate']) && is_numeric($editParams['rotate'])) {
      $glideParams['rot'] = (int)$editParams['rotate'];
    }
    
    // Handle flip
    if (isset($editParams['flip'])) {
      if ($editParams['flip'] === 'h' || $editParams['flip'] === 'horizontal') {
        $glideParams['flip'] = 'h';
      } else if ($editParams['flip'] === 'v' || $editParams['flip'] === 'vertical') {
        $glideParams['flip'] = 'v';
      } else if ($editParams['flip'] === 'both') {
        $glideParams['flip'] = 'both';
      }
    }
    
    return $glideParams;
  }
  
  /**
   * Get all edited versions of an asset
   * 
   * @return array JSON response with edited versions
   */
  public function actionApiGetEditedVersions()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;
    
    $uuid = Yii::$app->request->get('uuid');
    if (empty($uuid)) {
      return [
        'success' => false,
        'message' => 'Missing UUID parameter'
      ];
    }
    
    // Find all edited versions of this asset
    $editedVersions = Asset::findAll(['parent_uuid' => $uuid]);
    
    $items = [];
    foreach ($editedVersions as $version) {
      // Generate preview URL with edit parameters
      $previewUrl = $this->generatePreviewUrl($version);
      
      $items[] = [
        'uuid' => $version->uuid,
        'title' => $version->title,
        'mime' => $version->mime,
        'preview_url' => $previewUrl,
        'full_url' => CrelishBaseHelper::getAssetUrl($version->pathName, $version->fileName),
        'created' => $version->created,
        'edit_type' => $version->edit_type
      ];
    }
    
    return [
      'success' => true,
      'items' => $items,
      'total' => count($items)
    ];
  }
}
