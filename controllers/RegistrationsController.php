<?php
	
	namespace giantbits\crelish\controllers;
	
	use app\workspace\components\RegistrationsExportTransformerBase;
	use app\workspace\components\RegistrationsExportTransformerBGT;
	use app\workspace\components\RegistrationsExportTransformerEBH;
  use app\workspace\components\RegistrationsExportTransformerFLI;
  use app\workspace\components\RegistrationsExportTransformerHTW;
  use app\workspace\components\RegistrationsExportTransformerIHF;
	use app\workspace\components\RegistrationsExportTransformerHTK;
	use app\workspace\components\RegistrationsExportTransformerDHK;
	use app\workspace\components\RegistrationsExportTransformerSHK;
  use app\workspace\components\RegistrationsExportTransformerWBE;
  use app\workspace\components\RegistrationsExportTransformerWBN;
  use app\workspace\components\strategies\DHKFormattingStrategy;
  use app\workspace\components\strategies\FLIFormattingStrategy;
  use app\workspace\components\strategies\HTKFormattingStrategy;
  use app\workspace\components\strategies\HTWFormattingStrategy;
  use app\workspace\components\strategies\WBEFormattingStrategy;
  use app\workspace\models\Registrations;
	use giantbits\crelish\components\CrelishBaseController;
	use giantbits\crelish\components\CrelishJsonModel;
	use libphonenumber\PhoneNumberFormat;
	use libphonenumber\PhoneNumberUtil;
	use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
	use PhpOffice\PhpSpreadsheet\Exception;
	use PhpOffice\PhpSpreadsheet\Spreadsheet;
	use PhpOffice\PhpSpreadsheet\Style\Alignment;
	use PhpOffice\PhpSpreadsheet\Style\Border;
	use PhpOffice\PhpSpreadsheet\Style\Fill;
	use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
  use Yii;
  use yii\base\DynamicModel;
  use yii\base\InvalidRouteException;
  use yii\bootstrap5\Html;
	use yii\data\ActiveDataProvider;
	use yii\filters\AccessControl;
	use function _\reject;
	
	class RegistrationsController extends CrelishBaseController
	{
		
		public function behaviors()
		{
			return [
				'access' => [
					'class' => AccessControl::class,
					'only' => ['create', 'index', 'delete', 'update', 'export'],
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
			$this->registerClientScripts();
			$this->ctype = 'registrations';
			$this->uuid = (!empty(\Yii::$app->getRequest()->getQueryParam('uuid'))) ? \Yii::$app->getRequest()->getQueryParam('uuid') : null;
			parent::init();
		}
		
		public function actionIndex()
		{
			$this->layout = 'crelish.twig';
			
			if (!empty(\Yii::$app->request->post('selection'))) {
				foreach (\Yii::$app->request->post('selection') as $entry) {
					Registrations::find()->where(['uuid' => $entry])->one()->delete();
				}
			}
			
			$checkCol = [
				[
					'class' => 'giantbits\crelish\components\CrelishCheckboxColumn',
				]
			];
			
			$creationCol = [
				[
					'label' => 'Created',
					'attribute' => 'created',
					'value' => function ($data) {
						return !empty($data->created) ? strftime('%d.%m.%Y', $data->created) : '';
					},
					'enableSorting' => true
				]
			];
			
			$columns = array_merge($checkCol, CrelishJsonModel::getGridColumns(new Registrations()), $creationCol);
			
			$rowOptions = function ($model, $key, $index, $grid) {
				return ['onclick' => 'location.href="update?uuid=' . $model['uuid'] . '";'];
			};
			
			$query = Registrations::find()
				->where(['!=', 'state', '-1']);
			
			if (!empty(\Yii::$app->request->get('cr_content_filter'))) {
				$search = \Yii::$app->request->get('cr_content_filter');
				$query->andWhere([
					'or',
					['=', 'eventCode', $search],
					['LIKE', 'nameFirst', $search],
					['LIKE', 'nameLast', $search],
					['LIKE', 'email', $search],
					['LIKE', 'email_2', $search]
				]);
			}
			
			$provider = new ActiveDataProvider([
				'query' => $query
			]);
			
			return $this->render('index.twig', [
				'dataProvider' => $provider,
				'columns' => $columns,
				'ctype' => $this->ctype,
				'rowOptions' => $rowOptions
			]);
		}

    /**
     * @throws InvalidRouteException
     * @throws \yii\db\Exception
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function actionExport()
		{
			$this->layout = 'crelish.twig';
			
			$phoneUtl = PhoneNumberUtil::getInstance();

      if (empty(\Yii::$app->request->get('sort'))) {
        $_GET['sort'] = '-created';
      }
			
			$modelData = new Registrations();
			$filterModel = new DynamicModel([
				'company',
				'salutation',
				'eventCode',
				'eventYear',
				'nameFirst',
				'nameLast',
				'email',
				'exported',
				'type'
			]);
			
			$query = Registrations::find()
				->andWhere([
					'or',
					['>', 'state', -1],
					['is', 'state', null]
				]);
			
			if (!empty(\Yii::$app->request->get('DynamicModel')['exported'])) {
				if (\Yii::$app->request->get('DynamicModel')['exported'] == 1) {
					$query->andWhere(['>', 'exported', 0]);
				}
			} else {
				$query->andWhere(['is', 'exported', null]);
			}
			
			// Get filters from form and apply them to the query.
			if (!empty(\Yii::$app->request->get('DynamicModel'))) {
				foreach (\Yii::$app->request->get('DynamicModel') as $attribute => $value) {
					
					if ($attribute === 'exported') {
						$filterModel->{$attribute} = $value;
						continue;
					}
					
					if (!empty($value)) {
						$filterModel->{$attribute} = $value;
						$query->andWhere(['like', $attribute, $value]);
					}
				}
			}
			
			$creationCol = [
				[
					'label' => 'Created',
					'attribute' => 'created',
					'value' => function ($data) {
						return !empty($data->created) ? date('d.m.Y H:i', $data->created) : '';
					},
					'enableSorting' => true
				]
			];
			$columns = array_merge(CrelishJsonModel::getGridColumns($modelData), $creationCol);
			
			$columns = reject($columns, function ($item) {
				return $item['attribute'] == 'type';
			});
			
			$columns[] = [
				'attribute' => 'type',
				'value' => function ($model) {
					return match ($model->type) {
						3 => 'Aussteller',
						2 => 'Referent',
						default => 'Teilnehmer',
					};
				},
			];
			
			$typeCol = array_splice($columns, count($columns) - 1, 1);
			
			// Insert the element at the new position
			array_splice($columns, count($columns) - 1, 0, $typeCol);
			
			$rowOptions = function ($model, $key, $index, $grid) {
				return ['onclick' => 'location.href="update?uuid=' . $model['uuid'] . '";'];
			};
			$provider = new ActiveDataProvider([
				'query' => $query
			]);
			
			// Mark current results as exported.
			if (!empty(\Yii::$app->request->get('mark')) && \Yii::$app->request->get('mark')) {
				$entries = $query->all();
				
				foreach ($entries as $entry) {
					$entry->exported = time();
					$entry->state = 3;
					$entry->save(false);
				}
				
				\Yii::$app->response->redirect('/crelish/registrations/export');
			}
			
			// Export current results as Excel.
			if (!empty(\Yii::$app->request->get('export')) && \Yii::$app->request->get('export')) {
				$eventCode = null;
				
				if (!empty(\Yii::$app->request->get('DynamicModel'))) {
					if (!empty(\Yii::$app->request->get('DynamicModel')['eventCode'])) {
						$eventCode = \Yii::$app->request->get('DynamicModel')['eventCode'];
					}
				}
				
				$entries = $query->all();
				
				$transformer = match ($eventCode) {
					'IHF' => new RegistrationsExportTransformerIHF($modelData, $entries),
					'EBH' => new RegistrationsExportTransformerEBH($modelData, $entries),
					'BGT' => new RegistrationsExportTransformerBGT($modelData, $entries),
					'HTK' => new RegistrationsExportTransformerHTK($modelData, $entries),
					'HTW' => new RegistrationsExportTransformerHTW($modelData, $entries),
					'DHK' => new RegistrationsExportTransformerDHK($modelData, $entries),
					'SHK' => new RegistrationsExportTransformerSHK($modelData, $entries),
					'WBE' => new RegistrationsExportTransformerWBE($modelData, $entries),
					'FLI' => new RegistrationsExportTransformerFLI($modelData, $entries),
					default => new RegistrationsExportTransformerWBN($modelData, $entries)
				};
				
				$spreadsheet = new Spreadsheet();
				$sheet = $spreadsheet->getActiveSheet();
				$sheet
					->fromArray($transformer->getHeader(), null, 'A1')
					->fromArray($transformer->getResults(), null, 'A2');
				
				$this->formatExcelSheet($sheet, $eventCode);
				// redirect output to client browser
				$fileName = 'export_' . $eventCode . '_' . time() . '.xlsx';
				
				ob_end_clean();
				
				// Set headers for file download
				header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
				header('Content-Disposition: attachment;filename="' . $fileName . '"');
				header('Cache-Control: max-age=0');
				header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
				header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // Always modified
				header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
				header('Pragma: public'); // HTTP/1.0
				
				$writer = new Xlsx($spreadsheet);
				$writer->save('php://output');

				// Stop further execution
				exit;
			}
			
			// Render the view.
			return $this->render('export.twig', [
				'dataProvider' => $provider,
				'filterModel' => $filterModel,
				'columns' => $columns,
				'ctype' => $this->ctype,
				'rowOptions' => $rowOptions
			]);
		}
		
		public function actionUpdate()
		{
			$this->layout = 'crelish.twig';
			
			$cleanModel = Registrations::find()->where(['=', 'uuid', $this->uuid])->one();
			$mailData = [];
			
			//$content = $this->buildForm('admin', [], true, 'export');
			
			foreach ($cleanModel->getMailProfile($cleanModel->type) as $field) {
				if (!empty($cleanModel->{$field})) {
					$fieldLabel = match ($field) {
						'uuid' => \Yii::t('app', 'Registrations Nr.'),
						'type' => \Yii::t('app', 'Registriert als'),
						'created' => \Yii::t('app', 'Registrationsdatum'),
						default => $cleanModel->getAttributeLabel($field),
					};
					
					$value = nl2br($cleanModel->getTransformedValue($field));
					
					if ($field === 'participation' && $cleanModel->eventCode == 'EBH') {
						switch ($value) {
							case 'WHOLEEVENT':
								$value = 'Ganze Veranstaltung';
								break;
							case 'ONLYFIRSTDAY':
								$value = 'Nur 1. Veranstaltungstag';
								break;
							case 'ONLYSECONDDAY':
								$value = 'Nur 2. Veranstaltungstag';
								break;
							case 'OFFI':
								$value = 'Vertreter:in von Schulen/Behörden';
								break;
							case 'STUD':
								$value = 'Studierende';
								break;
							case 'DOCU':
								$value = 'Nur Tagungsband';
								break;
						}
					}
					
					if ($field === 'type' && $cleanModel->eventCode == 'EBH') {
						switch ($value) {
							case 'Regular':
								$value = 'Teilnehmer';
								break;
							case 'Speaker':
								$value = 'Referent';
								break;
							case 'Exhibitor Participant':
								$value = 'Austeller Teilnehmer';
								break;
						}
					}
					
					if ($field === 'dinner' && $cleanModel->eventCode == 'EBH') {
						$value = match ($value) {
							'1' => 'Ja',
							default => 'Nein',
						};
					}
					
					if($field === 'matriculation') {
						$value = Html::a(\Yii::t('app', 'Download file'), '/uploads/registration/' . $value, ['target' => '_blank']);
					}
					
					$mailData[\Yii::t('app', $fieldLabel)] = $value;
				}
			}
			
			
			return $this->render('update.twig', [
				'content' => $cleanModel,
				'ctype' => 'registration',
				'uuid' => $this->uuid,
				'data' => $mailData
			]);
		}
		
		public function actionDelete(): void
		{
			$registration = Registrations::findOne(['=', 'uuid', $this->uuid]);
			$registration->state = -1;
			$registration->save(false);
			\Yii::$app->response->redirect('/crelish/registrations/export');
		}
		
		/**
		 * @throws Exception
		 */
		private function formatExcelSheet($sheet, $eventCode): void
		{
			switch ($eventCode) {
				case 'EBH':
					$sheet->getRowDimension(1)
						->setRowHeight(45);
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('a9d08e');
					
					$sheet->getStyle('O1:AB1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('e1efda');
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getAlignment()
						->setVertical(Alignment::VERTICAL_CENTER)
						->setWrapText(true);
					
					$sheet->getStyle('AI1:AI' . $sheet->getHighestRow())
						->getAlignment()
						->setWrapText(true);
					
					$sheet->getStyle('AJ1:AJ' . $sheet->getHighestRow())
						->getAlignment()
						->setWrapText(true);
					
					$sheet->getStyle('O1:AB' . $sheet->getHighestRow())
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_CENTER);
					
					for ($rowIndex = 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
						$sheet->getCell('L' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
						
						$sheet->getCell('M' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					}
					
					$sheet->getStyle('O1:AB1')
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_CENTER);
					
					$borderStyle = [
						'borders' => [
							'outline' => [
								'borderStyle' => Border::BORDER_MEDIUM,
								'color' => ['rgb' => '000000'],
							],
						],
					];
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->applyFromArray($borderStyle);
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getFont()
						->setBold(true);
					break;
				case 'IHF':
					$sheet->getRowDimension(1)
						->setRowHeight(45);
					
					// Fill green
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('8fbf00');
					
					// fill light orange
					$sheet->getStyle('O1:S1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('ffcc99');
					
					// Fill dark yellow
					$sheet->getStyle('T1:V1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('ffcc00');
					
					// fill orange
					$sheet->getStyle('W1:X1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('ff9900');
					
					// fill light yellow
					$sheet->getStyle('Y1:AC1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('ffff99');
					
					// fill teal
					$sheet->getStyle('AD1:AG1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('ccffff');
					
					// fill grayish
					$sheet->getStyle('AH1:AJ1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('dce6f1');
					
					// fill blue
					$sheet->getStyle('AK1:AM1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('99ccff');
					
					// fill dark green
					$sheet->getStyle('AS1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('339966');
					
					// Vertical top align
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . $sheet->getHighestRow())
						->getAlignment()
						->setVertical(Alignment::VERTICAL_TOP)
						->setWrapText(true);
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getAlignment()
						->setVertical(Alignment::VERTICAL_CENTER)
						->setWrapText(true);
					
					// Line breaks
					$sheet->getStyle('AN2:AN' . $sheet->getHighestRow())
						->getAlignment()
						->setWrapText(true);
					
					$sheet->getStyle('AO2:AO' . $sheet->getHighestRow())
						->getAlignment()
						->setWrapText(true);
					
					// Text center align.
					$sheet->getStyle('O2:AG' . $sheet->getHighestRow())
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_CENTER);
					
					
					for ($rowIndex = 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
						$sheet->getCell('L' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
						
						$sheet->getCell('M' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					}
					
					$sheet->getStyle('AQ2:AR' . $sheet->getHighestRow())
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_CENTER);
					
					$borderStyle = [
						'borders' => [
							'outline' => [
								'borderStyle' => Border::BORDER_MEDIUM,
								'color' => ['rgb' => '000000'],
							],
						],
					];
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->applyFromArray($borderStyle);
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getFont()
						->setBold(true);
					break;
				case 'BGT':
					$sheet->getRowDimension(1)
						->setRowHeight(45);
					
					// Fill purple
					$sheet->getStyle('A1:N1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('d58b89');
					
					// fill light orange
					$sheet->getStyle('O1:T1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('ffc58e');
					
					// Fill Blocks A1-A3
					$sheet->getStyle('U1:W1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('fde6d4');
					
					// fill orange
					$sheet->getStyle('X1:Z1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('fccfab');
					
					// fill light yellow
					$sheet->getStyle('AA1:AC1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('fde6d4');
					
					$sheet->getStyle('AD1:AF1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('fccfab');
					
					$sheet->getStyle('AG1:AI1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('c5c5ff');
					
					$sheet->getStyle('AJ1:AL1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('8ec5ff');
					
					$sheet->getStyle('AM1:AU1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('8ec500');
					
					
					// ALIGNMENT START
					// Vertical top align
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . $sheet->getHighestRow())
						->getAlignment()
						->setVertical(Alignment::VERTICAL_TOP)
						->setWrapText(true);
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getAlignment()
						->setVertical(Alignment::VERTICAL_CENTER)
						->setWrapText(true);
					
					// Line breaks
					$sheet->getStyle('AM2:AM' . $sheet->getHighestRow())
						->getAlignment()
						->setWrapText(true);
					
					$sheet->getStyle('AN2:AN' . $sheet->getHighestRow())
						->getAlignment()
						->setWrapText(true);
					
					// Text center align.
					$sheet->getStyle('O1:AF' . $sheet->getHighestRow())
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_CENTER);
					
					$sheet->getStyle('AP1:AR' . $sheet->getHighestRow())
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_CENTER);
					
					$sheet->getStyle('AG1:AL' . $sheet->getHighestRow())
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_RIGHT);
					
					
					// DATA TYPES
					for ($rowIndex = 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
						$sheet->getCell('L' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
						
						$sheet->getCell('M' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					}
					
					$borderStyle = [
						'borders' => [
							'outline' => [
								'borderStyle' => Border::BORDER_MEDIUM,
								'color' => ['rgb' => '000000'],
							],
						],
					];
					
					// HEADER STYLES (BORDER AND BOLD)
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->applyFromArray($borderStyle);
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getFont()
						->setBold(true);
					break;
        case 'HTK':
          $formater = new HTKFormattingStrategy();
          $formater->format($sheet);
          break;
        case 'WBE':
          $formater = new WBEFormattingStrategy();
          $formater->format($sheet);
          break;
				case 'DHK':
          $formater = new DHKFormattingStrategy();
          $formater->format($sheet);

					break;
				case 'SHK':
					
					$sheet->getRowDimension(1)
						->setRowHeight(45);
					
					// Fill grey
					$sheet->getStyle('A1:N1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('a89226');
					
					// fill light orange
					$sheet->getStyle('O1:T1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('f8d568');
					
					// Fill purple
					$sheet->getStyle('U1:AB1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('fcecb5');
					
					// fill blue
					$sheet->getStyle('AC1:AP1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('a89226');
					
					// ALIGNMENT START
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getAlignment()
						->setVertical(Alignment::VERTICAL_CENTER)
						->setWrapText(true);
					
					// Vertical top align
					$sheet->getStyle('A2:' . $sheet->getHighestColumn() . $sheet->getHighestRow())
						->getAlignment()
						->setVertical(Alignment::VERTICAL_TOP)
						->setWrapText(true);
					
					// Text center align.
					$sheet->getStyle('O1:AH' . $sheet->getHighestRow())
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_CENTER);
					
					$sheet->getStyle('AL1:AN' . $sheet->getHighestRow())
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_CENTER);
					
					// DATA TYPES
					for ($rowIndex = 2; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
						
						$sheet->getCell('N' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
						$sheet->getCell('AK' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
						
						// Correct phone numbers.
						if ($rowIndex > 1) {
							
							$phoneNumber = $sheet->getCell('L' . $rowIndex)->getValue();
							$mobileNumber = $sheet->getCell('M' . $rowIndex)->getValue();
							
							$phoneCompiled = !empty($phoneNumber) ? " +" . $phoneNumber : '';
							$mobileCompiled = !empty($mobileNumber) ? " +" . $mobileNumber : '';
							
							if (!empty($phoneNumber))
								$sheet->getCell('L' . $rowIndex)->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING)->setValue(str_replace("++", "+", $phoneCompiled));
							if (!empty($mobileNumber))
								$sheet->getCell('M' . $rowIndex)->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING)->setValue(str_replace("++", "+", $mobileCompiled));
						}
					}
					
					$borderStyle = [
						'borders' => [
							'outline' => [
								'borderStyle' => Border::BORDER_MEDIUM,
								'color' => ['rgb' => '000000'],
							],
						],
					];
					
					// HEADER STYLES (BORDER AND BOLD)
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->applyFromArray($borderStyle);
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getFont()
						->setBold(true);
					
					break;
				case 'WBN':
					$sheet->getRowDimension(1)
						->setRowHeight(45);
					
					// BG Colors
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('ffd966');
					
					$sheet->getStyle('R1:T1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('f1c232');
					
					$sheet->getStyle('U1:X1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('bf9000');
					
					$sheet->getStyle('AA1:AB1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('38761d');
					
					$sheet->getStyle('AD1:AH1')
						->getFill()
						->setFillType(Fill::FILL_SOLID)
						->getStartColor()
						->setRGB('a2c4c9');
					
					
					// Centered text
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getAlignment()
						->setVertical(Alignment::VERTICAL_CENTER);
					
					$sheet->getStyle('A2:AH' . $sheet->getHighestRow())
						->getAlignment()
						->setVertical(Alignment::VERTICAL_TOP);
					
					
					// Text wrap
					$sheet->getStyle('Y1:Y' . $sheet->getHighestRow())
						->getAlignment()
						->setWrapText(true);
					
					$sheet->getStyle('AA1:AA' . $sheet->getHighestRow())
						->getAlignment()
						->setWrapText(true);
					
					$sheet->getStyle('AB1:AB' . $sheet->getHighestRow())
						->getAlignment()
						->setWrapText(true);
					
					$sheet->getStyle('AC1:AC' . $sheet->getHighestRow())
						->getAlignment()
						->setWrapText(true);
					
					
					// Horizontal align
					$sheet->getStyle('R1:T' . $sheet->getHighestRow())
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_CENTER);
					
					$sheet->getStyle('V1:X' . $sheet->getHighestRow())
						->getAlignment()
						->setHorizontal(Alignment::HORIZONTAL_CENTER);
					
					// Force data types
					for ($rowIndex = 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
						$sheet->getCell('J' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
						
						$sheet->getCell('M' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
						
						$sheet->getCell('N' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
						
						$sheet->getCell('P' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
						
						$sheet->getCell('AF' . $rowIndex)
							->setDataType(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					}
					
					
					// Header styles
					$borderStyle = [
						'borders' => [
							'outline' => [
								'borderStyle' => Border::BORDER_MEDIUM,
								'color' => ['rgb' => '000000'],
							],
						],
					];
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->applyFromArray($borderStyle);
					
					$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')
						->getFont()
						->setBold(true);
					break;
        case 'HTW':
          $formater = new HTWFormattingStrategy();
          $formater->format($sheet);
          break;
        case 'FLI':
          $formater = new FLIFormattingStrategy();
          $formater->format($sheet);
          break;
				default:
					// Default formatting if needed
					break;
			}



			$highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
			
			for ($col = 1; $col <= $highestColumnIndex; $col++) {
				$columnLetter = Coordinate::stringFromColumnIndex($col);
				$sheet->getColumnDimension($columnLetter)->setWidth(40);
			}
		}
	}
