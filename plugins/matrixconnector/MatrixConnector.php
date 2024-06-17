<?php
	
	namespace giantbits\crelish\plugins\matrixconnector;
	
	use giantbits\crelish\components\CrelishDataResolver;
	use giantbits\crelish\components\CrelishDynamicModel;
	use giantbits\crelish\components\CrelishDataProvider;
	use yii\base\Widget;
	use yii\helpers\Html;
	use yii\helpers\Json;
	use yii\grid\ActionColumn;
	
	class MatrixConnector extends Widget
	{
		public $data;
		public $formKey;
		public $field;
		public $model;
		
		public function init()
		{
			parent::init();
			
			if (!empty($this->data)) {
				$this->data = $this->processData($this->data);
			} else {
				$this->data = Json::encode(['main' => []]);
			}
		}
		
		public function run()
		{
			
			$elementType = !empty($_GET['cet']) ? $_GET['cet'] : 'page';
			//$modelProvider = new CrelishDataProvider($elementType);
			$modelProvider = CrelishDataResolver::resolveProvider($elementType, []);
			$filterModel = new CrelishDynamicModel(['ctype' => $elementType]);
			
			return $this->render('matrix.twig', [
				'dataProvider' => method_exists($modelProvider, 'getProvider') ? $modelProvider->getProvider() : $modelProvider,
				'filterModel ' => $filterModel,
				'columns' => [
					'systitle',
					[
						'class' => ActionColumn::class,
						'template' => '{update}',
						'buttons' => [
							'update' => function ($url, $model, $elementType) {
								if (!is_array($model)) {
									$ctype = explode('\\', strtolower($model::class));
									$ctype = end($ctype);
								} else {
									$ctype = $elementType;
								}
								
								return Html::a('<span class="fa fa-plus"></span>', '', [
									'title' => \Yii::t('app', 'Add'),
									'data-pjax' => '0',
									'data-content' => Json::encode(
										[
											'uuid' => is_array($model) ? $model['uuid'] : $model->uuid,
											'ctype' => is_array($model) ? $model['ctype'] : $model->ctype,
											'info' => [
												[
													'label' => \Yii::t('app', 'Titel intern'),
													'value' => is_array($model) ? $model['systitle'] : $model->systitle
												],
												[
													'label' => \Yii::t('app', 'Status'),
													'value' => is_array($model) ? $model['state'] : $model->state
												]
											]
										]),
									'class' => 'cntAdd'
								]);
							}
						]
					]
				],
				'ctype' => $elementType,
				'formKey' => $this->formKey,
				'label' => $this->field->label,
				'processedData' => $this->data
			]);
		}
		
		private function processData($data)
		{
			if (is_string($data)) {
				$data = Json::decode($data);
			}
			
			$processedData = [];
			
			foreach ($data as $key => $item) {
				
				$processedData[$key] = [];
				
				foreach ($item as $reference) {
					$info = [];
					$dataItem = new CrelishDataProvider($reference['ctype'], [], $reference['uuid']);
					$itemData = $dataItem->one();
					
					
					foreach ($dataItem->definitions->fields as $field) {
						if (isset($field->visibleInGrid) && $field->visibleInGrid) {
							if (!empty($field->label) && !empty($itemData[$field->key])) {
								
								if ($field && property_exists($field, 'transform')) {
									$transformer = 'giantbits\crelish\components\transformer\CrelishFieldTransformer' . ucfirst($field->transform);
									if (class_exists($transformer) && method_exists($transformer, 'afterFind')) {
										$transformer::afterFind($itemData[$field->key]);
									}
								}
								
								$info[] = ['label' => $field->label, 'value' => $itemData[$field->key]];
							}
						}
					}
					
					$processedData[$key][] = [
						'area' => $key,
						'uuid' => $reference['uuid'],
						'ctype' => $reference['ctype'],
						'info' => $info
					];
				}
			}
			
			return Json::encode($processedData);
		}
	}
