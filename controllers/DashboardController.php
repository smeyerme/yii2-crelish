<?php
	/**
	 * Created by PhpStorm.
	 * User: devop
	 * Date: 29.11.15
	 * Time: 17:19
	 */
	
	namespace giantbits\crelish\controllers;
	
	use giantbits\crelish\components\CrelishBaseController;
	use Yii;
	use yii\db\Query;
	use yii\filters\AccessControl;
	use yii\helpers\ArrayHelper;
	use yii\helpers\FileHelper;
	use yii\helpers\Json;
	
	class DashboardController extends CrelishBaseController
	{
		
		public $layout = 'crelish.twig';
		
		public function behaviors()
		{
			return [
				'access' => [
					'class' => AccessControl::class,
					'only' => ['create', 'index', 'delete'],
					'rules' => [
						[
							'allow' => TRUE,
							'roles' => ['@'],
						],
					],
				],
			];
		}
		
		public function init()
		{
			parent::init(); // TODO: Change the autogenerated stub
		}
		
		/**
		 * Override the setupHeaderBar method for dashboard-specific components
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
					// For dashboard index, just show the toggle sidebar
					break;
					
				default:
					// For other actions, just keep the defaults
					break;
			}
		}
		
		public function actionIndex()
		{
			// Get content service
			$contentService = Yii::$app->get('contentService');
			
			// Get all content types
			$contentTypes = $this->getContentTypes();
			
			// Get content statistics
			$contentStats = $this->getContentStatistics($contentTypes);
			
			// Get recent content
			$recentContent = $this->getRecentContent();
			
			// Get system information
			$systemInfo = $this->getSystemInfo();
			
			return $this->render('index.twig', [
				'contentTypes' => $contentTypes,
				'contentStats' => $contentStats,
				'recentContent' => $recentContent,
				'systemInfo' => $systemInfo
			]);
		}
		
		/**
		 * Get all available content types
		 * 
		 * @return array List of content types
		 */
		private function getContentTypes(): array
		{
			$contentTypes = [];
			
			// First try to get content types from workspace/elements directory
			$elementsPath = Yii::getAlias('@app/workspace/elements');
			
			try {
				if (is_dir($elementsPath)) {
					$files = FileHelper::findFiles($elementsPath, ['only' => ['*.json']]);
					
					foreach ($files as $file) {
						$content = file_get_contents($file);
						$element = Json::decode($content, true);
						
						// Skip elements that are not selectable
						if (isset($element['selectable']) && $element['selectable'] === false) {
							continue;
						}
						
						// Extract the element key from the filename
						$type = basename($file, '.json');
						
						$contentTypes[$type] = [
							'name' => $type,
							'label' => $element['label'] ?? ucfirst($type),
							'description' => $element['description'] ?? '',
						];
					}
				}
			} catch (\Exception $e) {
				Yii::warning("Error scanning elements directory: " . $e->getMessage());
			}
			
			// If no content types found, try the content service path
			if (empty($contentTypes)) {
				$contentService = Yii::$app->get('contentService');
				$contentTypesPath = Yii::getAlias($contentService->contentTypesPath);
				
				try {
					if (is_dir($contentTypesPath)) {
						$files = scandir($contentTypesPath);
						foreach ($files as $file) {
							if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
								$type = pathinfo($file, PATHINFO_FILENAME);
								try {
									$definition = $contentService->getContentTypeDefinition($type);
									$contentTypes[$type] = [
										'name' => $type,
										'label' => $definition['label'] ?? ucfirst($type),
										'description' => $definition['description'] ?? '',
									];
								} catch (\Exception $e) {
									Yii::warning("Could not load content type definition for {$type}: " . $e->getMessage());
								}
							}
						}
					}
				} catch (\Exception $e) {
					Yii::warning("Error scanning content types directory: " . $e->getMessage());
				}
			}
			
			// Sort content types by label
			uasort($contentTypes, function($a, $b) {
				return strcmp($a['label'], $b['label']);
			});
			
			return $contentTypes;
		}
		
		/**
		 * Get content statistics for each content type
		 * 
		 * @param array $contentTypes List of content types
		 * @return array Statistics for each content type
		 */
		private function getContentStatistics(array $contentTypes): array
		{
			$stats = [];
			
			foreach ($contentTypes as $type => $info) {
				try {
					$query = new Query();
					$count = $query->from("{{%{$type}}}")->count();
					
					$stats[$type] = [
						'count' => $count,
						'label' => $info['label'],
					];
				} catch (\Exception $e) {
					Yii::warning("Could not get statistics for content type {$type}: " . $e->getMessage());
				}
			}
			
			return $stats;
		}
		
		/**
		 * Get recent content items
		 * 
		 * @param int $limit Maximum number of items to return
		 * @return array Recent content items
		 */
		private function getRecentContent(int $limit = 10): array
		{
			$contentTypes = $this->getContentTypes();
			$recentItems = [];
			
			foreach ($contentTypes as $type => $info) {
				try {
					$query = new Query();
					$items = $query->from("{{%{$type}}}")
						->select(['id', 'title', 'created', 'updated'])
						->orderBy(['updated' => SORT_DESC])
						->limit($limit)
						->all();
					
					foreach ($items as $item) {
						if (empty($item['updated'])) {
							continue; // Skip items without updated timestamp
						}
						
						$recentItems[] = [
							'id' => $item['id'],
							'title' => $item['title'] ?? ('Untitled ' . $info['label']),
							'type' => $type,
							'typeLabel' => $info['label'],
							'updated' => $item['updated'],
						];
					}
				} catch (\Exception $e) {
					Yii::warning("Could not get recent items for content type {$type}: " . $e->getMessage());
				}
			}
			
			// Sort by updated
			ArrayHelper::multisort($recentItems, ['updated'], [SORT_DESC]);
			
			// Limit the total number of items
			return array_slice($recentItems, 0, $limit);
		}
		
		/**
		 * Get system information
		 * 
		 * @return array System information
		 */
		private function getSystemInfo(): array
		{
			return [
				'version' => Yii::$app->params['crelish']['version'] ?? 'Unknown',
				'php_version' => PHP_VERSION,
				'yii_version' => Yii::getVersion(),
				'environment' => YII_ENV,
				'debug_mode' => YII_DEBUG ? 'Enabled' : 'Disabled',
			];
		}
	}
