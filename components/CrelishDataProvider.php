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
	use yii\db\Exception;
	use yii\helpers\FileHelper;
	use yii\helpers\Json;
	use yii\widgets\LinkPager;
	use function _\filter;
	use function _\get;
	
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
		
		/**
		 * @throws Exception
		 */
		public function __construct($ctype, $settings = [], $uuid = null, $forceFull = false, $onlyMeta = false)
		{
			$this->ctype = $ctype;
			$this->forceFull = $forceFull;
			$this->settings = $settings;
			$this->definitions = $this->getDefinitions();
			$this->pathAlias = ($this->ctype == 'elements') ? '@app/workspace' : '@app/workspace/data';
			$this->modelClass = '\app\workspace\models\\' . ucfirst($this->ctype);
			
			if (array_key_exists('filter', $settings)) {
				if (!empty($settings['filter'])) {
					$this->filterSettings = $settings['filter'];
				}
			}
			
			$processedData = [];
			
			if (!$onlyMeta) {
				switch ($this->definitions->storage) {
					case 'db':
						$modelTable = call_user_func('app\workspace\models\\' . ucfirst($this->ctype) . '::tableName');
						
						if (!empty($uuid)) {
							$this->uuid = $uuid;
							$dataModels = \Yii::$app->db->createCommand('SELECT * FROM ' . $modelTable . ' WHERE uuid = "' . $this->uuid . '"')->queryAll();
							
							// Process data
							foreach ($dataModels as $dataModel) {
								$dataModel['ctype'] = $this->ctype;
								$processedData[$dataModel['uuid']] = $this->processSingle($dataModel);
							}
							
							$this->allModels = $dataModels;
						} else {
							$processedDataIn = false;
							$this->allModels = $this->modelClass::find()->all();
						}
						
						if (!empty($this->allModels)) {
							if (array_key_exists( 'filter', $settings)) {
								if (!empty($settings['filter'])) {
									$this->filterModels($settings['filter']);
								}
							}
							
							if (array_key_exists( 'sort', $settings)) {
								if (!empty($settings['sort'])) {
									$this->sortModels($settings['sort']);
								}
							}
							
							if (array_key_exists( 'limit', $settings)) {
								if (!empty($settings['limit'])) {
									$this->pageSize = $settings['limit'];
								}
							}
						}
						
						break;
					default:
						if (!empty($uuid)) {
							$this->uuid = $uuid;
							if ($theFile = @file_get_contents($this->resolveDataSource($uuid))) {
								$this->allModels[] = $this->processSingle(\yii\helpers\Json::decode($theFile), true);
							} else {
								$this->allModels[] = [];
							}
							
						} else {
							$dataModels = \Yii::$app->cache->get('crc_' . $ctype);
							
							if ($dataModels === false || $forceFull) {
								// $data is not found in cache, calculate it from scratch
								$dataModels = $this->parseFolderContent($this->ctype);
								// store $data in cache so that it can be retrieved next time
								\Yii::$app->cache->set('crc_' . $ctype, $dataModels);
							}
							
							$this->allModels = $dataModels;
							
							// todo: process data.
							if (array_key_exists('filter', $settings)) {
								if (!empty($settings['filter'])) {
									$this->filterModels($settings['filter']);
								}
							}
							
							if (array_key_exists( 'sort', $settings)) {
								if (!empty($settings['sort'])) {
									$this->sortModels($settings['sort']);
								}
							}
							
							if (array_key_exists( 'limit', $settings)) {
								if (!empty($settings['limit'])) {
									$this->pageSize = $settings['limit'];
								}
							}
						}
				}
			}
			
			parent::__construct();
		}
		
		public function getQuery($query, $filter = null)
		{

      // Add the actual filtering.
			if (is_array($filter)) {
				foreach ($filter as $key => $keyValue) {
					if (!empty($keyValue)) {
						if (is_array($keyValue)) {
							if ($keyValue[0] == 'noempty') {
								$query->andWhere(['not', [$key => null]]);
							}
							
							if ($keyValue[0] == 'strict') {
								$query->andWhere(['=', $key, $keyValue[1]]);
							}
							
							if ($keyValue[0] == 'lt') {
								$query->andWhere(['>', $key, $keyValue[1]]);
							}
							
							if ($keyValue[0] == 'gt') {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									$comparer = $value[$key];
									if ($this->getFieldConfig($key)->transform == 'date' && !is_int((int)$comparer)) {
										$comparer = strtotime($comparer);
									}
									return $comparer > $keyValue[1];
								});
							}
							
							if ($keyValue[0] == 'between') {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									return (($value[$key] >= $keyValue[1] && $value[$key] <= $keyValue[2]) || ($value[$key] >= $keyValue[2] && $value[$key] <= $keyValue[1]));
								});
							}
							
							if ($keyValue[0] == 'neq') {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									return $value['uuid'] <> $keyValue[1];
								});
							}
							
							if ($keyValue[0] == 'in') {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									return in_array($value[$key], $keyValue[1]);
								});
							}
							
							if ($keyValue[0] == '*' && !empty($keyValue[1])) {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									$isMatch = true;
									$itemString = strtolower($value[$key]);
									$searchFragments = explode(" ", trim($keyValue[1]));
									
									foreach ($searchFragments as $fragment) {
										if (!str_contains($itemString, strtolower($fragment))) {
											$isMatch = false;
										}
									}
									
									return $isMatch;
								});
							}
							
						} elseif (is_bool($keyValue)) {
							$this->allModels = filter($this->allModels, function($value) use ($key, $keyValue) {
								return $value[$key] === $keyValue;
							});
						} else {
							// todo: Optimize filter with param for "like" and "equal" filtering
							if ($key === 'slug') {
								$query->andWhere(['=', 'slug', $keyValue]);
							} elseif ($key === 'state') {
								$this->allModels = filter($this->allModels, function($value) use ($key, $keyValue) {
									return $value[$key] === (string)$keyValue;
								});
							} elseif ($key === 'freesearch') {
								$searchFragments = explode(" ", trim($keyValue));
								
								$fragmentPattern = implode('', array_map(function ($item) {
									return "(.*$item)";
								}, $searchFragments));
								
								$fieldPattern = implode(', ', array_filter(array_map(function ($item) {
									if ($item->key != 'ctype' && (!property_exists($item, 'virtual') || !$item->virtual)) {
                    if (property_exists($item, 'gridField')) {
                      $fieldItm = explode('.', $item->gridField);
                      if (property_exists($item, 'config')) {
                        return '`' . $item->config->ctype . '`.`' . $fieldItm[1] . '`';
                      }
                      return '`' . $item->key . '`.`' . $fieldItm[1] . '`';
                    }
										return '`' . $this->ctype . '`.`' . $item->key . "`";
                  } else {
                    return null;
                  }
								}, $this->getDefinitions()->fields)));
								
								$query->andWhere("CONCAT_WS(' ', $fieldPattern) RLIKE '$fragmentPattern'");
							} else {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									if (!empty($value[$key]) && is_array($value[$key])) {
										$value[$key] = implode("||", $value[$key]);
									} elseif (str_contains($key, "|")) {
										$key = str_replace("|", ".", $key);
									}
									
									$array = (array) $value;
									$finalFilters = explode(";", $keyValue);
									
									// Multifilter.
									$isMatch = false;
									foreach ($finalFilters as $subFilter) {
										if ((stripos(html_entity_decode(serialize($array->get($key))), html_entity_decode(serialize($subFilter))) !== false)) {
											$isMatch = true;
										}
									}
									return $isMatch;
								});
							}
						}
					}
				}
			}
			
			return $query;
		}
		
		public function filterModels($filter)
		{
			if (is_array($filter)) {
				foreach ($filter as $key => $keyValue) {
					if (!empty($keyValue)) {
						if (is_array($keyValue)) {
							if ($keyValue[0] == 'noempty') {
								$this->allModels = array_values(filter($this->allModels, function ($value) use ($key, $keyValue) {
									return $value[$key] != '';
								}));
							}
							
							if ($keyValue[0] == 'strict') {
								$this->allModels = array_values(filter($this->allModels, function ($value) use ($key, $keyValue) {
									return $value[$key] == $keyValue[1];
								}));
							}
							
							if ($keyValue[0] == 'lt') {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									$comparer = $value[$key];
									if ($this->getFieldConfig($key)->transform == 'date') {
										$comparer = strtotime($comparer);
									}
									return $comparer < $keyValue[1];
								});
							}
							
							if ($keyValue[0] == 'gt') {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									$comparer = $value[$key];
									if ($this->getFieldConfig($key)->transform == 'date' && !is_int((int)$comparer)) {
										$comparer = strtotime($comparer);
									}
									return $comparer > $keyValue[1];
								});
							}
							
							if ($keyValue[0] == 'between') {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									return (($value[$key] >= $keyValue[1] && $value[$key] <= $keyValue[2]) || ($value[$key] >= $keyValue[2] && $value[$key] <= $keyValue[1]));
								});
							}
							
							if ($keyValue[0] == 'neq') {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									return $value['uuid'] <> $keyValue[1];
								});
							}
							
							if ($keyValue[0] == 'in') {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									return in_array($value[$key], $keyValue[1]);
								});
							}
							
							if ($keyValue[0] == '*' && !empty($keyValue[1])) {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									$isMatch = true;
									$itemString = strtolower($value[$key]);
									$searchFragments = explode(" ", trim($keyValue[1]));
									
									foreach ($searchFragments as $fragment) {
										if (!str_contains($itemString, strtolower($fragment))) {
											$isMatch = false;
										}
									}
									
									return $isMatch;
								});
							}
							
						} elseif (is_bool($keyValue)) {
							$this->allModels = filter($this->allModels, function($value) use ( $key, $keyValue) {
								return $value[$key] === $keyValue;
							});
						} else {
							// todo: Optimize filter with param for "like" and "equal" filtering
							if ($key === 'slug') {
								$this->allModels = filter($this->allModels, function($value) use ( $key, $keyValue) {
									return $value[$key] === $keyValue;
								});
							} elseif ($key === 'state') {
								$this->allModels = filter($this->allModels, function($value) use ( $key, $keyValue) {
									return $value[$key] === (string)$keyValue;
								});
							} elseif ($key === 'freesearch') {
								$this->allModels = filter($this->allModels, function ($value) use ($keyValue) {
									$isMatch = true;
									$itemString = strtolower(serialize($value));
									$searchFragments = explode(" ", trim($keyValue));
									foreach ($searchFragments as $fragment) {
										if (!str_contains($itemString, strtolower($fragment))) {
											$isMatch = false;
										}
									}
									return $isMatch;
								});
							} else {
								$this->allModels = filter($this->allModels, function ($value) use ($key, $keyValue) {
									if (!empty($value[$key]) && is_array($value[$key])) {
										$value[$key] = implode( "||", $value[$key]);
									} elseif (str_contains($key, "|")) {
										$key = str_replace("|", ".", $key);
									}
									
									$array = (array) $value;
									$finalFilters = explode(";", $keyValue);
									
									// Multifilter.
									$isMatch = false;
									foreach ($finalFilters as $subFilter) {
										if ((stripos(html_entity_decode(serialize(get($array, $key))), html_entity_decode(serialize($subFilter))) !== false)) {
											$isMatch = true;
										}
									}
									return $isMatch;
								});
							}
						}
					}
				}
			}
		}
		
		public function sortModels($sort)
		{
			
			$sortparams[] = $this->allModels;
			
			if (is_array($sort['by'])) {
				foreach ($sort['by'] as $item) {
					switch ($item) {
						case 'asc':
							$sortparams[] = SORT_ASC;
							break;
						case 'desc':
							$sortparams[] = SORT_DESC;
							break;
						default:
							$sortparams[] = $item;
					}
				}
			}
			
			$this->allModels = call_user_func_array([
				$this,
				'array_orderby',
			], $sortparams);
		}
		
		public function all()
		{
			$provider = new ArrayDataProvider([
				'key' => 'id',
				'allModels' => $this->allModels,
				'pagination' => [
					'totalCount' => count($this->allModels),
					'pageSize' => $this->pageSize,
					'forcePageParam' => true,
				],
			]);
			
			$models = $provider->getModels();
			
			$pager = LinkPager::widget([
				'pagination' => $provider->getPagination(),
				'maxButtonCount' => 10,
			]);
			$result = ['models' => array_values($models), 'pager' => $pager];
			
			return $result;
		}
		
		public function one()
		{
			if (!empty($this->allModels)) {
				return array_values($this->allModels)[0];
			}
			return null;
		}
		
		public function raw()
		{
			$getParams = $_GET;
			
			if (!$this->allModels) {
				return new ArrayDataProvider([
					'key' => $this->key]);
			}
			
			$provider = new ArrayDataProvider([
				'key' => $this->key,
				'allModels' => $this->allModels,
				'sort' => $this->getSorting(),
				'pagination' => [
					'totalCount' => count($this->allModels),
					'pageSize' => $this->pageSize,
					'forcePageParam' => true,
					'route' => (!empty(\Yii::$app->getRequest()
						->getQueryParam('pathRequested'))) ? '/' . \Yii::$app->getRequest()
							->getQueryParam('pathRequested') : null,
					'params' => array_merge([
						'page' => !empty($_GET['page']) ? $_GET['page'] : '',
						'category' => !empty($_GET['category']) ? $_GET['category'] : '',
						'branch' => !empty($_GET['branch']) ? $_GET['branch'] : '',
						'title' => !empty($_GET['title']) ? $_GET['title'] : '',
						'sort' => !empty($_GET['sort']) ? $_GET['sort'] : '',
						'per-page' => !empty($_GET['per-page']) ? $_GET['per-page'] : '',
						'kind' => !empty($_GET['kind']) ? $_GET['kind'] : '',
						'ctype' => !empty($_GET['ctype']) ? $_GET['ctype'] : '',
					], $getParams),
				],
			]);
			
			return $provider;
		}
		
		public function getProvider()
		{
			$query = $this->getQuery($this->modelClass::find(), $this->filterSettings);
			$modelProvider = new ActiveDataProvider(
				[
					'query' => $query,
					'pagination' => [
						'pageSize' => $this->pageSize,
					],
					'sort' => [
						'defaultOrder' => [
							'created' => SORT_DESC
						]
					],
				]
			);
			
			return $modelProvider;
		}
		
		public function getArrayProvider(): ArrayDataProvider
		{
			
			$dataModels = \Yii::$app->cache->get('crc_' . $this->ctype);
			
			if ($dataModels === false || $this->forceFull) {
				// $data is not found in cache, calculate it from scratch
				$dataModels = $this->parseFolderContent($this->ctype);
				// store $data in cache so that it can be retrieved next time
				\Yii::$app->cache->set('crc_' . $this->ctype, $dataModels);
			}
			
			$this->allModels = $dataModels;
			
			// todo: process data.
			if (array_key_exists( 'filter', $this->settings)) {
				if (!empty($this->settings['filter'])) {
					$this->filterModels($this->settings['filter']);
				}
			}
			
			if (array_key_exists( 'sort', $this->settings)) {
				if (!empty($this->settings['sort'])) {
					$this->sortModels($this->settings['sort']);
				}
			}
			
			if (array_key_exists( 'limit', $this->settings)) {
				if (!empty($this->settings['limit'])) {
					$this->pageSize = $this->settings['limit'];
				}
			}
			
			return new ArrayDataProvider([
				'key' => 'id',
				'allModels' => $this->allModels,
				'pagination' => [
					'totalCount' => count($this->allModels),
					'pageSize' => $this->pageSize,
					'forcePageParam' => true,
				],
			]);
		}
		
		public function rawAll()
		{
			return $this->allModels;
		}
		
		public function delete()
		{
			$ds = DIRECTORY_SEPARATOR;
			if (@unlink(\Yii::getAlias($this->pathAlias) . $ds . $this->type . $ds . $this->uuid . '.json')) {
				\Yii::$app->cache->flush();
			}
			
			return;
		}
		
		public function getDefinitions(): \stdClass
		{
			$this->definitions = new \stdClass();
			$this->definitions->fields = [];
			$this->definitions->ctype = $this->ctype;
			$this->definitions->storage = 'json';
			
			if ($this->ctype !== 'elements') {
				
				$filePath = \Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . $this->ctype . '.json';
				
				if (file_exists($filePath)) {
					$elementStructure = Json::decode(file_get_contents($filePath), false);
				}
				
				// Set storage.
				$this->definitions->storage = (!empty($elementStructure->storage)) ? $elementStructure->storage : 'json';
				
				// Add core fields.
				$this->definitions->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
				$this->definitions->fields[] = Json::decode('{ "label": "ctype", "key": "ctype", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
				if (!empty($elementStructure) && property_exists($elementStructure, 'fields')) {
					$this->definitions->fields = array_merge($this->definitions->fields, $elementStructure->fields);
					$this->definitions->fields[] = Json::decode('{ "label": "State", "key": "state", "type": "textInput", "visibleInGrid": true, "sortable": true, "transform": "state", "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
					
					if (!empty($elementStructure) && property_exists($elementStructure, 'sortDefault')) {
						$this->definitions->sortDefault = $elementStructure->sortDefault;
					}
				}
			}
			
			return $this->definitions;
		}
		
		public function getSorting()
		{
			$sorting = [];
			$attributes = [];
			
			if (!empty($this->definitions)) {
				foreach ($this->definitions->fields as $field) {
					if (property_exists($field, 'sortable') && $field->sortable == true) {
						if (!is_array($field->sortable)) {
							$attributes[] = (property_exists($field, 'gridField') && !empty($field->gridField)) ? $field->gridField : $field->key;
						} else {
							$attributes[$field->key] = [];
							
							if (property_exists($field, 'sortDefault')) {
								$attributes[$field->key]['default'] = constant($field->sortDefault);
							}
						}
					}
				}
				
				$sorting['attributes'] = $attributes;
				
				if (property_exists($this->definitions, "sortDefault")) {
					foreach ($this->definitions->sortDefault as $key => $value) {
						$sorting['defaultOrder'] = [$key => constant($value)];
					}
				}
			}
			
			return $sorting;
		}
		
		public function getFilters(): CrelishDynamicJsonModel
		{
			$model = new CrelishDynamicJsonModel(['systitle'], [
				'ctype' => $this->ctype,
			]);
			
			if (!empty($_GET['CrelishDynamicModel'])) {
				foreach ($_GET['CrelishDynamicModel'] as $filter => $value) {
					if (!empty($value)) {
						$filters[$filter] = $value;
						$_GET[$filter] = $value;
					}
				}
			}
			
			if (!empty($_GET['CrelishDynamicModel'])) {
				$model->attributes = $_GET['CrelishDynamicModel'];
			}
			
			return $model;
		}
		
		public function getColumns()
		{
			$columns = [];
			
			foreach ($this->getDefinitions()->fields as $field) {
				
				if (!empty($field->visibleInGrid)) {
					$label = (property_exists($field, 'label') && !empty($field->label)) ? $field->label : null;
					$format = (property_exists($field, 'format') && !empty($field->format)) ? $field->format : 'text';
					$columns[] = (property_exists($field, 'gridField') && !empty($field->gridField)) ? [
						'attribute' => $field->gridField,
						'label' => $label,
						'format' => $format,
					] : [
						'attribute' => $field->key,
						'label' => $label,
						'format' => $format,
					];
				}
			}
			
			return array_values($columns);
		}
		
		private function resolveDataSource($uuid = ''): string
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
		
		private function parseFolderContent($folder): array
    {
			$filesArr = [];
			$allModels = [];
			$fullFolder = \Yii::getAlias($this->pathAlias) . DIRECTORY_SEPARATOR . $folder;
			
			if (!file_exists($fullFolder)) {
				FileHelper::createDirectory($fullFolder);
			}
			
			$files = FileHelper::findFiles($fullFolder, ['recursive' => false]);
			
			if (isset($files[0])) {
				foreach ($files as $file) {
					$filesArr[] = $file;
				}
			}
			
			foreach ($filesArr as $file) {
				$finalArr = [];
				$content = file_get_contents($file);
				$modelArr = json_decode($content, true);
				
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
		
		private function processSingle($data): array
		{
			$finalArr = [];
			$modelArr = (array)$data;
			
			// Handle specials like in frontend.
			$elementDefinition = $this->getDefinitions();
			
			foreach ($modelArr as $attr => $value) {
				CrelishBaseContentProcessor::processFieldData($this->ctype, $elementDefinition, $attr, $value, $finalArr);
			}
			
			return $finalArr;
		}

    public function setRelations(&$query): void
    {

      foreach ($this->definitions->fields as $field) {
        if(property_exists($field, 'gridField') && property_exists($field, 'config')) {
          $config = $field->config;
          if(property_exists($config, 'ctype')) {
            $relation = explode('.', $field->gridField) ;
            $query->joinWith($relation[0]);
          }
        }
      }

      //die();
    }

    private function array_orderby()
		{
			$args = func_get_args();
			$data = array_shift($args);
			
			foreach ($args as $n => $field) {
				if (is_string($field)) {
					$tmp = [];
					foreach ($data as $key => $row) {
						if (strpos($field, ".") !== false) {
							$tmp[$key] = get($row, $field);
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
		
		private function getFieldConfig($handle)
		{
			
			$fieldConfig = filter($this->definitions->fields, function ($field) use ($handle) {
				return $field->key == $handle;
			});
			return reset($fieldConfig);
		}
	}
