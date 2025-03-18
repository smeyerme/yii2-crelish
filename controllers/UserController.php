<?php
	
	namespace giantbits\crelish\controllers;
	
	use giantbits\crelish\components\CrelishBaseController;
	use giantbits\crelish\components\CrelishBaseHelper;
  use giantbits\crelish\components\CrelishDataManager;
  use giantbits\crelish\components\CrelishGlobals;
	use giantbits\crelish\components\CrelishUser;
	use giantbits\crelish\components\CrelishDataProvider;
	use giantbits\crelish\components\CrelishDynamicModel;
	use PhpOffice\PhpSpreadsheet\Spreadsheet;
	use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
  use Yii;
  use yii\data\ActiveDataProvider;
  use yii\filters\AccessControl;
	use yii\helpers\Url;
	use function _\find;
	use function _\map;
	
	class UserController extends CrelishBaseController
	{
		/**
		 * [$layout description].
		 *
		 * @var string
		 */
		public $layout = 'crelish.twig';
		
		public function behaviors()
		{
			return [
				'access' => [
					'class' => AccessControl::class,
					'only' => ['create', 'index', 'delete', 'update', 'import'],
					'rules' => [
						[
							'allow' => true,
							'roles' => ['@'],
						],
					],
				],
			];
		}
		
		private function registerClientScripts()
		{
			
			\Yii::$app->view->registerJs('
      $(document).on("pjax:complete" , function(event) {
        $(".scrollable").animate({ scrollTop: "0" });
      });
    ', \yii\web\View::POS_LOAD);
		}
		
		/**
		 * [init description].
		 *
		 * @return [type] [description]
		 */
		public function init()
		{
			
			parent::init();
			
			$this->registerClientScripts();
			
			$this->ctype = 'user';
			$this->uuid = (!empty(\Yii::$app->getRequest()->getQueryParam('uuid'))) ? \Yii::$app->getRequest()->getQueryParam('uuid') : null;
			
			// create default user element, if none is present
			$workspacePath = realpath(\Yii::getAlias('@webroot') . '/../workspace');
			if ($workspacePath === false) {
				throw new \yii\web\ServerErrorHttpException('The *workspace* folder could not be found - please create it in your project root');
			}
			$elementsPath = realpath($workspacePath . '/elements');
			if ($elementsPath === false) {
				throw new \yii\web\ServerErrorHttpException('The *elements* folder could not be found - please create it in your workspace folder');
			}
			$modelJson = realpath($elementsPath . '/' . $this->ctype . '.json');
			
			if ($modelJson === false) {
				file_put_contents($elementsPath . '/' . $this->ctype . '.json', '{"key":"user","label":"User","tabs":[{"label":"Login","key":"login","visible":false,"groups":[{"label":"Login","key":"login","fields":["email","password","login"]}]},{"label":"User","key":"user","groups":[{"label":"User","key":"user","fields":["email","password","state"]}]}],"fields":[{"label":"Email address","key":"email","type":"textInput","visibleInGrid":true,"rules":[["required"],["email"],["string",{"max":128}]]},{"label":"Password","key":"password","type":"passwordInput","visibleInGrid":false,"rules":[["required"],["string",{"max":128}]],"transform":"hash"},{"label":"Login","key":"login","type":"submitButton","visibleInGrid":false}, {"label":"Auth-Key","key":"authKey","type":"text","visibleInGrid":false}]}');
			}
		}
		
		/**
		 * [actionLogin description].
		 *
		 * @return [type] [description]
		 */
		public function actionLogin()
		{
			$this->layout = 'simple.twig';
			
      		// Turn away if logged in.
			if (!\Yii::$app->user->isGuest && \Yii::$app->user->identity->role == 9) {
				return $this->redirect(Url::to(['/crelish/content/index']));
			}
			
			$usersProvider = new CrelishDataProvider('user');
			$users = $usersProvider->rawAll();
			
			if (sizeof($users) == 0) {
				// Generate default admin.
				$adminUser = new CrelishDynamicModel(['ctype' => 'user']);
				$adminUser->email = 'admin@local.host';
				$adminUser->password = Yii::$app->security->generatePasswordHash('basta!');
				$adminUser->state = 2;
				$adminUser->authKey = \Yii::$app->security->generateRandomString();
				$adminUser->role = 9;
				$adminUser->save();
			}
			
			$model = new CrelishDynamicModel(['ctype' => 'user']);
			
			// Validate data and login the user in case of post request.
			if (\Yii::$app->request->post()) {
				if (CrelishUser::crelishLogin(\Yii::$app->request->post('CrelishDynamicModel'))) {
					return $this->redirect(Url::to(['/crelish/content/index']));
				}
			}
			
			// Render it all with twig.
			return $this->render('login.twig', [
				'model' => $model,
				'ctype' => $this->ctype,
				'uuid' => $this->uuid,
			]);
		}
		
		/**
		 * @throws Exception
		 * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
		 */
		public function actionIndex()
		{
      $filter = null;
      $checkCol = [
        [
          'class' => 'giantbits\crelish\components\CrelishCheckboxColumn',
        ]
      ];
			
			$modelClass = '\app\workspace\models\\' . ucfirst($this->ctype);
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

      // Handle status filtering
      $statusFilter = $this->handleSessionAndQueryParams('cr_status_filter');
      if (!empty($statusFilter)) {
        $filter['state'] = ['strict', $statusFilter];
      }

      // Create a data manager for the content type
      $dataManager = new CrelishDataManager($this->ctype, [
        'filter' => $filter,
        'pageSize' => 25
      ]);

      // Get the element definition
      $elementDefinition = $dataManager->getDefinitions();

      // Get the data provider
      $dataProvider = null;

      if ($elementDefinition->storage === 'db' && class_exists($modelClass)) {
        $query = $modelClass::find();

        // Apply filters
        if ($filter) {
          foreach ($filter as $key => $value) {
            if (is_array($value) && $value[0] === 'strict') {
              $query->andWhere([$key => $value[1]]);
            } elseif ($key === 'freesearch') {
              $searchFragments = explode(" ", trim($value));
              $orConditions = ['or'];

              foreach ($elementDefinition->fields as $field) {
                if (!property_exists($field, 'virtual') || !$field->virtual) {
                  foreach ($searchFragments as $fragment) {
                    $orConditions[] = ['like', $this->ctype . '.' . $field->key, $fragment];
                  }
                }
              }

              $query->andWhere($orConditions);
            } else {
              $query->andWhere(['like', $this->ctype . '.' . $key, $value]);
            }
          }
        }

        // Add relations.
        $dataManager->setRelations($query);

        if (!empty($elementDefinition->sortDefault)) {
          $sortKey = key($elementDefinition->sortDefault);
          $sortDir = $elementDefinition->sortDefault->{$sortKey};

          if (empty($_GET['sort'])) {
            $_GET['sort'] = !(empty($sortKey) && !empty($sortDir))
              ? ($sortDir === 'SORT_ASC' ? $sortKey : "-{$sortKey}")
              : null;
          }
        }

        $dataProvider = new ActiveDataProvider([
          'query' => $query,
          'pagination' => [
            'pageSize' => 25,
            'route' => Yii::$app->request->pathInfo,
            'pageParam' => 'list-page'
          ]
        ]);

      } elseif ($elementDefinition->storage === 'json') {
        $modelProvider = $dataManager->getProvider();
      }
			
			if (!empty(\Yii::$app->request->get('export')) && \Yii::$app->request->get('export')) {
				$this->doExpot($modelProvider);
			}

      $columns = [];
			$columns = array_merge($columns, $checkCol);

      // Add columns for fields with visibleInGrid = true
      if (isset($elementDefinition->fields)) {
        foreach ($elementDefinition->fields as $field) {
          // Only include fields that have visibleInGrid = true and exclude UUID
          if (property_exists($field, 'visibleInGrid') && $field->visibleInGrid === true && $field->key !== 'uuid') {
            $column = [
              'attribute' => $field->key,
              'label' => property_exists($field, 'label') ? $field->label : null,
              'format' => property_exists($field, 'format') ? $field->format : 'text'
            ];

            // Special handling for state field
            if ($field->key === 'state') {
              $column['format'] = 'raw';
              $column['label'] = Yii::t('i18n', 'Status');
              $column['value'] = function ($data) {
                switch ($data['state']) {
                  case 1:
                    return Yii::t('i18n', 'Entwurf');
                  case 2:
                    return Yii::t('i18n', 'Online');
                  case 3:
                    return Yii::t('i18n', 'Archiviert');
                  default:
                    return Yii::t('i18n', 'Offline');
                }
              };
            } // Special handling for dropdown fields
            elseif (property_exists($field, 'items')) {
              $column['format'] = 'raw';
              $column['value'] = function ($data) use ($field) {
                if (!empty($field->items) && !empty($field->items->{$data[$field->key]})) {
                  return $field->items->{$data[$field->key]};
                }
                return $data[$field->key];
              };
            } // Special handling for switch inputs
            elseif (property_exists($field, 'type') && str_contains($field->type, 'SwitchInput')) {
              $column['format'] = 'raw';
              $column['value'] = function ($data) use ($field) {
                return $data[$field->key] == 0 ? 'Nein' : 'Ja';
              };
            } // Special handling for value overwrites
            elseif (property_exists($field, 'valueOverwrite')) {
              $column['format'] = 'raw';
              $column['value'] = function ($data) use ($field) {
                return Arrays::get($data, $field->valueOverwrite);
              };
            }
            // Use gridField if specified
            if (property_exists($field, 'gridField') && !empty($field->gridField)) {
              $column['attribute'] = $field->gridField;
            }

            $columns[] = $column;
          }
        }
      }

			$rowOptions = function ($model, $key, $index, $grid) {
				return ['onclick' => 'location.href="update?uuid=' . $model['uuid'] . '";'];
			};
			
			return $this->render('index.twig', [
				'dataProvider' => $dataProvider,
				'filterProvider' => $dataManager->getFilters(),
				'columns' => $columns,
				'ctype' => $this->ctype,
				'rowOptions' => $rowOptions
			]);
		}
		
		/**
		 * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
		 */
		#[NoReturn] private function doExpot($provider): void
		{
			$provider->pageSize = 1000000;
			$models = $provider->getProvider()->getModels();
			$roles = CrelishGlobals::get('main')['userRoles'][0];
			$states = CrelishGlobals::get('main')['states'][0];
			
			
			$data = array_map(function ($entry) use ($roles, $states) {
				return [
					$entry->salutation,
					$entry->nameFirst,
					$entry->nameLast,
					(string)$entry->phone,
					$entry->email,
					$entry->company,
					$entry->code,
					$roles[(string)$entry->role],
					strftime("%d.%m.%Y", $entry->activationDate),
					strftime("%d.%m.%Y", $entry->trialEndAt),
					$states[(string)$entry->state] ?? '-',
				];
			}, $models);
			
			$spreadSheet = new Spreadsheet();
			$sheet = $spreadSheet->getActiveSheet();
			$sheet
				->fromArray([
					\Yii::t('app', 'salutation'),
					\Yii::t('app', 'nameFirst'),
					\Yii::t('app', 'nameLast'),
					\Yii::t('app', 'phone'),
					\Yii::t('app', 'email'),
					\Yii::t('app', 'company'),
					\Yii::t('app', 'code'),
					\Yii::t('app', 'type'),
					\Yii::t('app', 'activationDate'),
					\Yii::t('app', 'trialEndAt'),
					\Yii::t('app', 'state'),
				], null, 'A1')
				->fromArray($data, null, 'A2');
			
			
			for ($rowIndex = 2; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
				
				$sheet->getCell('D' . $rowIndex)
					->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			}
			
			$fileName = 'export_wissen_' . time() . '.xlsx';
			ob_end_clean();
			
			// Set headers for file download
			header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
			header('Content-Disposition: attachment;filename="' . $fileName . '"');
			header('Cache-Control: max-age=0');
			header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // Always modified
			header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
			header('Pragma: public'); // HTTP/1.0
			
			$writer = new Xlsx($spreadSheet);
			$writer->save('php://output');
			
			// Stop further execution
			exit;
		}
		
		public function actionImport()
		{
			$creationTime = null;
			
			if (\Yii::$app->request->isPost) {
				$file_mimes = ['text/x-comma-separated-values', 'text/comma-separated-values', 'application/octet-stream', 'application/vnd.ms-excel', 'application/x-csv', 'text/x-csv', 'text/csv', 'application/csv', 'application/excel', 'application/vnd.msexcel', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
				
				if (isset($_FILES['file']['name']) && in_array($_FILES['file']['type'], $file_mimes)) {
					
					$list = '';
					$arr_file = explode('.', $_FILES['file']['name']);
					$extension = end($arr_file);
					$reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
					
					if ('csv' == $extension) {
						$reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
					}
					
					$spreadsheet = $reader->load($_FILES['file']['tmp_name']);
					
					$sheetData = $spreadsheet->getSheetByName('IMPORT')->toArray();
					$creationTime = time();
					
					foreach ($sheetData as $entry) {
						// Anrede 4, Vorname 3, Name 2, Firma 6 , E-Mail 7, Mobile 12
						if (!empty($entry[6])) {
							
							$lang = trim($entry[0]);
							$nameFirst = trim($entry[1]);
							$nameLast = trim($entry[2]);
							$company = trim($entry[3]);
							$mobile = !empty($entry[5]) ? trim($entry[5]) : trim($entry[4]);
							$email = trim(str_replace(',', '.', $entry[6]));
							$salutation = !empty($entry[7]) ? trim($entry[7]) : '';
							
							// Check if user exists before creating new one.
							$user = \app\workspace\models\User::find()
								->where(['=', 'email', $email])
								->count();
							
							if ($user > 0 === false) {
								$user = new \app\workspace\models\User();
								$user->nameLast = $nameLast;
								$user->nameFirst = $nameFirst;
								$user->salutation = $salutation;
								$user->company = $company;
								$user->email = trim($email);
								$user->phone = $mobile;
								$user->created = $creationTime;
								$user->lang = $lang;
								$user->uuid = CrelishBaseHelper::GUIDv4();
								$user->role = 1;
								$user->state = 1;
								$user->authKey = \Yii::$app->security->generateRandomString();
								
								if ($user->save(false)) {
									$list .= '<br>' . $nameFirst . '; ' . $nameLast . '; ' . $company . '; ' . $email . '; ' . $mobile;
								} else {
									$list .= '<br>NOT SAVED - ERROR!!! ::' . $nameFirst . '; ' . $nameLast . '; ' . $company . '; ' . $email . '; ' . $mobile;
								}
							} else {
								$user = \app\workspace\models\User::find()
									->where(['=', 'email', $email])
									->one();
								
								if ($user) {
									$user->reminderSend = -1;
									$user->updated = $creationTime;
									$user->save(false);
								}
								$list .= '<br>NOT SAVED - EXISTING ACCOUNT (Scheduled Reminder) ::' . $nameFirst . '; ' . $nameLast . '; ' . $company . '; ' . $email . '; ' . $mobile;
							}
						}
					}
					
				} else {
					echo 'FILE PROBLEM';
				}
			}
			
			return $this->render('import.twig', [
				'list' => !empty($list) ? $list : null,
				'creationTime' => $creationTime
			]);
		}
		
		public function actionUpdate()
		{
			$content = $this->buildForm('admin');
			
			return $this->render('update.twig', [
				'content' => $content,
				'ctype' => 'user',
				'uuid' => $this->uuid
			]);
		}
		
		public function actionCreate()
		{
			$content = $this->buildForm('admin');
			
			return $this->render('create.twig', [
				'content' => $content,
				'ctype' => 'user',
				'uuid' => $this->uuid
			]);
		}
		
		public function actionDelete()
		{
			$ctype = 'user';
			$uuid = \Yii::$app->request->get('uuid');
			
			$model = new CrelishDynamicModel( ['ctype' => $ctype, 'uuid' => $uuid]);
			$model->delete();
			
			\Yii::$app->cache->flush();
			
			$this->redirect('/crelish/user/index');
		}
		
		/**
		 * [actionLogout description].
		 *
		 * @return [type] [description]
		 */
		public function actionLogout()
		{
			\Yii::$app->user->logout();
			
			return $this->redirect(Url::to(['/crelish/user/login']));
		}
		
		/**
		 * Override the setupHeaderBar method to use user-specific components
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
					// For user index, use the user-specific search and create buttons
					$this->view->params['headerBarLeft'][] = 'user-search';
					$this->view->params['headerBarRight'] = ['delete', 'user-create'];
					break;
					
				case 'create':
				case 'update':
					// For create/update actions, add back button and save buttons
					$this->view->params['headerBarLeft'][] = 'back-button';
					$this->view->params['headerBarRight'] = ['save'];
					break;
					
				case 'login':
					// For login, use simple layout
					$this->layout = 'simple.twig';
					break;
					
				default:
					// For other actions, just keep the defaults
					break;
			}
		}
	}
