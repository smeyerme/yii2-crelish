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
	use giantbits\crelish\components\CrelishDataProvider;
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
			$this->enableCsrfValidation = false;
			parent::init();
		}
		
		public function actions(): array
		{
			return [
				'glide' => 'giantbits\crelish\actions\GlideAction'
			];
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
			
			if (!empty($_GET['cr_content_filter'])) {
				$filter = ['freesearch' => $_GET['cr_content_filter']];
			}


      if(empty($_GET['sort'])) {
        $_GET['sort'] = "-created";
      }
			
			$modelProvider = new CrelishDataProvider('asset', ['filter' => $filter]);
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
			$columns = array_merge($checkCol, $modelProvider->columns);
			$columns = map($columns, function ($item) use ($modelProvider) {
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
				'dataProvider' => $modelProvider->getProvider(),
				'filterProvider' => $modelProvider->getFilters(),
				'columns' => $columns,
				'ctype' => $this->ctype,
				'rowOptions' => $rowOptions
			]);
		}
		
		public function actionUpdate()
		{
			$uuid = !empty(Yii::$app->getRequest()->getQueryParam('uuid')) ? Yii::$app->getRequest()->getQueryParam('uuid') : null;
			$model = new CrelishDynamicModel( ['uuid' => $uuid, 'ctype' => 'asset']);
			
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
			
			return $this->render('update.twig', [
				'model' => $model,
				'extractModel' => $extractedDoc,
				'colormain_hex' => $colormain_hex ?? null,
				'colorpalette' => $palColor ?? null,
				'alerts' => $alerts
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
					$model = new CrelishDynamicModel( ['ctype' => 'asset']);
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
			$modelProvider = new CrelishDynamicModel( ['ctype' => 'asset', 'uuid' => $uuid]);
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

      // Create data provider with filters
      $modelProvider = new CrelishDataProvider('asset', [
        'filter' => $filter,
        'pagination' => [
          'pageSize' => $limit,
          'page' => $page - 1, // Adjust for 0-based indexing in data provider
        ],
        'sort' => ['by' => '-created'] // Default sort by created date (newest first)
      ]);

      $provider = $modelProvider->getProvider();
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
	}
