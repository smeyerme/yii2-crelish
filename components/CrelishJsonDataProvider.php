<?php
	/**
	 * Created by PhpStorm.
	 * User: devop
	 * Date: 03.02.16
	 * Time: 20:57
	 */
	
	namespace giantbits\crelish\components;
	
	use yii\base\Component;
	use yii\data\ArrayDataProvider;
	use yii\data\DataProviderInterface;
	use yii\helpers\FileHelper;
	use yii\helpers\Json;
	use yii\widgets\LinkPager;
	use function _\filter;
	use function _\flatten;
	use function _\get;
	
	/**
	 * @deprecated since version 2.0.0, use CrelishDataManager instead.
	 * This class is maintained for backward compatibility and will be removed in a future version.
	 *
	 * @property-read \yii\data\ArrayDataProvider $provider
	 * @property-read mixed $columns
	 * @property-read array $sorting
	 * @property-read \giantbits\crelish\components\CrelishDynamicJsonModel $filters
	 */
	class CrelishJsonDataProvider extends Component
	{
		private $ctype;
		private $allModels;
		private $definitions;
		private $key = 'uuid';
		private $uuid;
		private $pathAlias;
		private $pageSize = 30;
		private $dataManager;
		
		/**
		 * Constructor
		 * 
		 * @param string $ctype Content type
		 * @param array $settings Settings
		 * @param string|null $uuid UUID
		 * @param bool $forceFull Force full load
		 */
		public function __construct($ctype, $settings = [], $uuid = NULL, $forceFull = FALSE)
		{
			$this->ctype = $ctype;
			$this->uuid = $uuid;
			
			// Use the new data manager
			$this->dataManager = new CrelishDataManager($ctype, $settings, $uuid);
			$this->definitions = $this->dataManager->getDefinitions();
			
			if (!empty($uuid)) {
				$this->allModels = [$this->dataManager->one()];
			} else {
				$this->allModels = $this->dataManager->rawAll();
			}
			
			parent::__construct();
		}
		
		/**
		 * Filter models
		 * 
		 * @param array $filter Filter
		 * @return void
		 */
		public function filterModels($filter)
		{
			// This is now handled by the data manager
			$this->allModels = $this->dataManager->rawAll();
		}
		
		/**
		 * Sort models
		 * 
		 * @param array $sort Sort
		 * @return void
		 */
		public function sortModels($sort)
		{
			// This is now handled by the data manager
			$this->allModels = $this->dataManager->rawAll();
		}
		
		/**
		 * Get all records
		 * 
		 * @return array Records and pagination
		 */
		public function all()
		{
			return $this->dataManager->all();
		}
		
		/**
		 * Get a single record
		 * 
		 * @return array|null Record
		 */
		public function one()
		{
			return $this->dataManager->one();
		}
		
		/**
		 * Get a data provider
		 * 
		 * @return DataProviderInterface Data provider
		 */
		public function getProvider(): DataProviderInterface
		{
			return $this->dataManager->getProvider();
		}
		
		/**
		 * Get all records as raw data
		 * 
		 * @return array Records
		 */
		public function rawAll()
		{
			return $this->dataManager->rawAll();
		}
		
		/**
		 * Delete a record
		 * 
		 * @return bool Whether the deletion was successful
		 */
		public function delete()
		{
			return $this->dataManager->delete();
		}
		
		/**
		 * Get element definitions
		 * 
		 * @return \stdClass Element definitions
		 */
		public function getDefinitions(): \stdClass
		{
			return $this->dataManager->getDefinitions();
		}
		
		/**
		 * Get sorting configuration
		 * 
		 * @return array Sorting configuration
		 */
		public function getSorting()
		{
			return $this->dataManager->getSorting();
		}
		
		/**
		 * Get filters
		 * 
		 * @return CrelishDynamicJsonModel Filters
		 */
		public function getFilters(): CrelishDynamicJsonModel
		{
			return $this->dataManager->getFilters();
		}
		
		/**
		 * Get columns configuration
		 * 
		 * @return array Columns configuration
		 */
		public function getColumns()
		{
			return $this->dataManager->getColumns();
		}
		
		private function resolveDataSource($uuid = '')
		{
			$ds = DIRECTORY_SEPARATOR;
			$fileDataSource = '';
			
			$langDataFolder = (\Yii::$app->params['defaultLanguage'] != \Yii::$app->language) ? $ds . \Yii::$app->language : '';
			$fileDataSource = \Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $langDataFolder . $ds . $uuid . '.json';
			
			if (!file_exists(\Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $langDataFolder . $ds . $uuid . '.json')) {
				$fileDataSource = \Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $ds . $uuid . '.json';
			}
			
			return $fileDataSource;
		}
		
		private function parseFolderContent($folder)
		{
			$filesArr = [];
			$allModels = [];
			$fullFolder = \Yii::getAlias($this->pathAlias) . DIRECTORY_SEPARATOR . $folder;
			
			if (!file_exists($fullFolder)) {
				FileHelper::createDirectory($fullFolder);
			}
			
			$files = FileHelper::findFiles($fullFolder, ['recursive' => FALSE]);
			if (isset($files[0])) {
				foreach ($files as $file) {
					$filesArr[] = $file;
				}
			}
			
			foreach ($filesArr as $file) {
				$finalArr = [];
				$content = file_get_contents($file);
				$modelArr = json_decode($content, TRUE);
				
				if (is_null($modelArr)) {
					$segments = explode(DIRECTORY_SEPARATOR, $file);
					CrelishBaseController::addError("Invalid JSON in " . array_pop($segments));
					continue;
				}
				
				$modelArr['id'] = $file;
				$modelArr['ctype'] = $this->ctype;
				
				// Handle specials like in frontend.
				$elementDefinition = $this->getDefinitions();
				
				foreach ($modelArr as $attr => $value) {
					CrelishBaseContentProcessor::processFieldData($this->ctype, $elementDefinition, $attr, $value, $finalArr);
				}
				
				$allModels[] = $finalArr;
			}
			
			return $allModels;
		}
		
		private function processSingle($data)
		{
			
			$finalArr = [];
			
			$modelArr = (array)$data;
			
			// Handle specials like in frontend.
			$elementDefinition = $this->getDefinitions();
			
			// todo: Handle special fields... uncertain about this.
			foreach ($modelArr as $attr => $value) {
				CrelishBaseContentProcessor::processFieldData($this->ctype, $elementDefinition, $attr, $value, $finalArr);
			}
			
			return $finalArr;
		}
		
		private function array_orderby()
		{
			
			$args = func_get_args();
			$data = array_shift($args);
			
			foreach ($args as $n => $field) {
				
				if (is_string($field)) {
					
					$tmp = [];
					
					foreach ($data as $key => $row) {
						
						if (strpos($field, ".") !== FALSE) {
							$tmp[$key] = \_\get($row, $field);
						} else {
							$tmp[$key] = $row[$field];
						}
					}
					$args[$n] = $tmp;
				}
			}

      $args[] = &$data;
			@call_user_func_array('array_multisort', $args);

			return array_pop($args);
		}
	}

