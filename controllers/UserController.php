<?php
	
	namespace giantbits\crelish\controllers;
	
	use giantbits\crelish\components\CrelishBaseController;
	use giantbits\crelish\components\CrelishBaseHelper;
	use giantbits\crelish\components\CrelishGlobals;
	use giantbits\crelish\components\CrelishUser;
	use giantbits\crelish\components\CrelishDataProvider;
	use giantbits\crelish\components\CrelishDynamicModel;
	use PhpOffice\PhpSpreadsheet\Spreadsheet;
	use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
		public $layout = 'simple.twig';
		
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
			// Turn away if logged in.
			if (!\Yii::$app->user->isGuest && \Yii::$app->user->idendity->role == 9) {
				return $this->redirect(Url::to(['/crelish/content/index']));
			}
			
			$usersProvider = new CrelishDataProvider('user');
			$users = $usersProvider->rawAll();
			
			if (sizeof($users) == 0) {
				// Generate default admin.
				$adminUser = new CrelishDynamicModel(['email', 'password', 'login', 'state', 'role'], ['ctype' => 'user']);
				$adminUser->email = 'admin@local.host';
				$adminUser->password = 'basta!';
				$adminUser->state = 2;
				$adminUser->authKey = \Yii::$app->security->generateRandomString();
				$adminUser->role = 9;
				$adminUser->save();
			}
			
			$model = new CrelishDynamicModel(['email', 'password'], ['ctype' => 'user']);
			
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
			$this->layout = 'crelish.twig';
			
			$modelClass = '\app\workspace\models\\' . ucfirst($this->ctype);
			if (!empty($_POST['selection'])) {
				if (class_exists($modelClass)) {
					foreach ($_POST['selection'] as $selection) {
						$delModel = $modelClass::findOne($selection);
						$delModel->delete();
					}
				}
			}
			
			$filter = [];
			
			if (!empty($_GET['cr_content_filter'])) {
				$filter['freesearch'] = $_GET['cr_content_filter'];
				\Yii::$app->session->set('cr_content_filter', $_GET['cr_content_filter']);
			} else {
				if (!empty(\Yii::$app->session->get('cr_content_filter'))) {
					$filter['freesearch'] = \Yii::$app->session->get('cr_content_filter');
				}
			}
			
			if (!empty($_GET['cr_status_filter'])) {
				$filter['state'] = ['strict', $_GET['cr_status_filter']];
				\Yii::$app->session->set('cr_status_filter', $_GET['cr_status_filter']);
			} else {
				if (!empty(\Yii::$app->session->get('cr_status_filter'))) {
					$filter['state'] = ['strict', \Yii::$app->session->get('cr_status_filter')];
				}
			}
			
			$modelProvider = new CrelishDataProvider('user', ['filter' => $filter]);
			
			if (!empty(\Yii::$app->request->get('export')) && \Yii::$app->request->get('export')) {
				$this->doExpot($modelProvider);
			}
			
			$checkCol = [
				[
					'class' => 'giantbits\crelish\components\CrelishCheckboxColumn',
				]
			];
			
			$columns = array_merge($checkCol, $modelProvider->columns);
			
			$columns = map($columns, function ($item) use ($modelProvider) {
				
				if (key_exists('attribute', $item) && $item['attribute'] === 'state') {
					$item['format'] = 'raw';
					$item['label'] = 'Status';
					$item['value'] = function ($data) {
						$state = match ($data['state']) {
							1 => 'Inactive',
							2 => 'Online',
							3 => 'Archived',
							default => 'Offline',
						};;
						return $state;
					};
				}
				
				
				if (key_exists('attribute', $item) && $item['attribute'] === 'role') {
					$item['format'] = 'raw';
					$item['label'] = 'Rolle / Typ';
					$item['value'] = function ($data) {
						switch ($data['role']) {
							case 1:
								$state = 'Registriert';
								break;
							case 2:
								$state = 'Abonent';
								break;
							case 9:
								$state = 'Admin';
								break;
							default:
								$state = 'Gast';
						};
						return $state;
					};
				}
				
				
				if (key_exists('attribute', $item) && $item['attribute'] === 'activationDate') {
					$item['format'] = 'raw';
					$item['label'] = 'Datum Aktivierung';
					$item['value'] = function ($data) {
						return !empty($data['activationDate']) ? strftime("%d.%m.%Y", $data['activationDate']) : '';
					};
				}
				
				
				if (key_exists('attribute', $item) && $item['attribute'] === 'trialEndAt') {
					$item['format'] = 'raw';
					$item['label'] = 'Datum Ablauf';
					$item['value'] = function ($data) {
						return !empty($data['trialEndAt']) ? strftime("%d.%m.%Y", $data['trialEndAt']) : '';
					};
				}
				
				if (key_exists('attribute', $item)) {
					// Add magic here: get definition for attribute, check for items, use items for label display.
					$itemDef = find($modelProvider->definitions->fields, function ($itm) use ($item) {
						return $itm->key == $item['attribute'];
					});
					
					
					if (is_object($itemDef) && property_exists($itemDef, 'items')) {
						$item['format'] = 'raw';
						$item['label'] = $itemDef->label;
						$item['value'] = function ($data) use ($itemDef) {
							
							$key = $data[$itemDef->key];
							return $itemDef->items->{$key};
						};
					} elseif (is_object($itemDef) && property_exists($itemDef, 'type') && str_contains($itemDef->type, 'SwitchInput')) {
						$item['format'] = 'raw';
						$item['label'] = $itemDef->label;
						$item['value'] = function ($data) use ($itemDef) {
							return $data[$itemDef->key] == 0 ? 'Nein' : 'Ja';
						};
					}
					
				}
				
				
				return $item;
			});
			
			
			$rowOptions = function ($model, $key, $index, $grid) {
				return ['onclick' => 'location.href="update?uuid=' . $model['uuid'] . '";'];
			};
			
			return $this->render('index.twig', [
				'dataProvider' => $modelProvider->getProvider(),
				'filterProvider' => $modelProvider->getFilters(),
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
					\Yii::t('crelish', 'salutation'),
					\Yii::t('crelish', 'nameFirst'),
					\Yii::t('crelish', 'nameLast'),
					\Yii::t('crelish', 'phone'),
					\Yii::t('crelish', 'email'),
					\Yii::t('crelish', 'company'),
					\Yii::t('crelish', 'code'),
					\Yii::t('crelish', 'type'),
					\Yii::t('crelish', 'activationDate'),
					\Yii::t('crelish', 'trialEndAt'),
					\Yii::t('crelish', 'state'),
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
			$this->layout = 'crelish.twig';
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
			$this->layout = 'crelish.twig';
			
			$content = $this->buildForm('admin');
			
			return $this->render('update.twig', [
				'content' => $content,
				'ctype' => 'user',
				'uuid' => $this->uuid
			]);
		}
		
		public function actionCreate()
		{
			$this->layout = 'crelish.twig';
			
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
			
			$model = new CrelishDynamicModel([], ['ctype' => $ctype, 'uuid' => $uuid]);
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
			
			return $this->goHome();
		}
	}
