<?php
	
	/**
	 * Created by PhpStorm.
	 * User: devop
	 * Date: 03.02.16
	 * Time: 20:57
	 */
	
	namespace giantbits\crelish\components;
	
	use yii\base\Component;
	use yii\data\ActiveDataProvider;
	use yii\data\ArrayDataProvider;
	use yii\data\DataProviderInterface;
	use yii\db\Exception;
	use yii\helpers\FileHelper;
	use yii\helpers\Json;
	use yii\widgets\LinkPager;
	use function _\filter;
	use function _\get;
	
	/**
	 * @deprecated since version 2.0.0, use CrelishDataManager instead.
	 * This class is maintained for backward compatibility and will be removed in a future version.
	 */
	class CrelishDataProvider extends Component
	{
		private $ctype;
		private $allModels;
		private $definitions;
		private $key = 'uuid';
		private $uuid;
		private $settings = [];
		private $forceFull = false;
		private $pathAlias;
		public $pageSize = 30;
		private $modelClass;
		private $filterSettings = [];
		private $dataManager;
		
		/**
		 * Constructor
		 * 
		 * @param string $ctype Content type
		 * @param array $settings Settings
		 * @param string|null $uuid UUID
		 * @param bool $forceFull Force full load
		 * @param bool $onlyMeta Only load metadata
		 */
		public function __construct($ctype, $settings = [], $uuid = null, $forceFull = false, $onlyMeta = false)
		{
			$this->ctype = $ctype;
			$this->forceFull = $forceFull;
			$this->settings = $settings;
			$this->uuid = $uuid;
			
			// Use the new data manager
			$this->dataManager = new CrelishDataManager($ctype, $settings, $uuid);
			$this->definitions = $this->dataManager->getDefinitions();
			
			if (!$onlyMeta) {
				if (!empty($uuid)) {
					$this->allModels = [$this->dataManager->one()];
				} else {
					$this->allModels = $this->dataManager->rawAll();
				}
			}
			
			parent::__construct();
		}
		
		/**
		 * Get a query
		 * 
		 * @param mixed $query Query
		 * @param array|null $filter Filter
		 * @return mixed Query
		 */
		public function getQuery($query, $filter = null)
		{
			if ($filter) {
				foreach ($filter as $key => $value) {
					if (is_array($value) && $value[0] === 'strict') {
						$query->andWhere([$key => $value[1]]);
					} elseif ($key === 'freesearch') {
						$searchFragments = explode(" ", trim($value));
						$orConditions = ['or'];
						
						foreach ($this->definitions->fields as $field) {
							if (!property_exists($field, 'virtual') || !$field->virtual) {
								foreach ($searchFragments as $fragment) {
									$orConditions[] = ['like', $field->key, $fragment];
								}
							}
						}
						
						$query->andWhere($orConditions);
					} else {
						$query->andWhere(['like', $key, $value]);
					}
				}
			}
			
			return $query;
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
			$this->settings['sort'] = $sort;
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
		public function getProvider()
		{
			return $this->dataManager->getProvider();
		}
		
		/**
		 * Get an array data provider
		 * 
		 * @return DataProviderInterface Array data provider
		 */
		public function getArrayProvider(): DataProviderInterface
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
		
		/**
		 * Set relations for a query
		 * 
		 * @param mixed $query Query
		 * @return void
		 */
		public function setRelations(&$query): void
		{
			$this->dataManager->setRelations($query);
		}
	}

