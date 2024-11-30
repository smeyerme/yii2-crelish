<?php
	
	namespace giantbits\crelish\plugins\selfselect;
	
	use giantbits\crelish\components\CrelishFormWidget;
  use yii\helpers\VarDumper;
  use function _\find;
	
	
	class SelfSelect extends CrelishFormWidget
	{
		public $data;
		public $rawData;
		public $formKey;
		public $field;
		public $value;
		
		private $selectData = [];
		private $includeDataType;
		private $allowMultiple = false;
		private $allowClear = false;
		private $hiddenValue = '';
		private $selectedValue;
		private $predefinedOptions;
		
		public function init()
		{

			$this->includeDataType = $this->field->config->ctype;
			$this->allowMultiple = !empty($this->field->config->multiple) ? $this->field->config->multiple : false;
			$this->predefinedOptions = !empty($this->field->config->options) ? $this->field->config->options : null;
			
			if (!empty($this->data)) {
				if (strpos($this->data, ";") > 0) {
					$this->selectedValue = explode("; ", $this->data);
					$this->hiddenValue = $this->data;
				} else {
					$this->rawData = $this->data;
					$this->selectedValue = $this->data;
					$this->hiddenValue = $this->data;
				}
				
				$this->data = $this->processData($this->data);
			} else {
				$this->data = $this->processData();
				$this->rawData = "";
			}
			
			parent::init();
		}
		
		public function run()
		{
			
			$isRequired = find($this->field->rules, function ($rule) {
				foreach ($rule as $set) {
					if ($set == 'required') {
						return true;
					}
				}
				return false;
			});
			
			return $this->render('selfselect.twig', [
				'formKey' => $this->formKey,
				'field' => $this->field,
				'required' => ($isRequired) ? 'required' : '',
				'selectData' => $this->selectData,
				'selectValue' => $this->selectedValue,
				'hiddenValue' => $this->hiddenValue,
				'includeDataType' => $this->includeDataType,
				'allowMultiple' => $this->allowMultiple,
        'allowClear' => $this->allowClear,
			]);
		}
		
		private function processData($data = null)
		{
			// Load datasource.
			if (is_array($this->predefinedOptions)) {
				foreach ($this->predefinedOptions as $option) {
					$dataItems[][$this->formKey] = $option;
				}
			} else {
				$modelClass = '\app\workspace\models\\' . ucfirst($this->includeDataType);
				$dataItems = $modelClass::find()->asArray()->select($this->field->key)->distinct()->orderBy([$this->field->key => 'asc'])->all();
			}
			
			if (!empty($dataItems)) {
				foreach ($dataItems as $item) {
					
					if (!empty($item[$this->formKey]) && is_string($item[$this->formKey])) {
						if (strpos($item[$this->formKey], ";") > 0) {
							foreach (explode("; ", $item[$this->formKey]) as $entry) {
								$this->selectData[$entry] = $entry;
							}
						} else {
							$this->selectData[$item[$this->formKey]] = $item[$this->formKey];
						}
					}
				}
			}
			
			return $data;
		}
	}
