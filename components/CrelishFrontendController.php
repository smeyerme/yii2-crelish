<?php
	/**
	 * Created by PhpStorm.
	 * User: devop
	 * Date: 29.11.15
	 * Time: 17:17
	 */
	
	namespace giantbits\crelish\components;
	
	use app\workspace\models\Page;
	use Yii;
	use yii\base\Controller;
	
	/**
	 * Class CrelishFrontendController
	 * @package giantbits\crelish\components
	 */
	class CrelishFrontendController extends Controller
	{
		/**
		 * [$entryPoint description]
		 * @var [type]
		 */
		public $entryPoint;
		/**
		 * [$requestUrl description]
		 * @var [type]
		 */
		private $requestUrl;
		/**
		 * [$viewTemplate description]
		 * @var [type]
		 */
		private $viewTemplate;
		public $data;
		public $nonce;
		
		/**
		 * [init description]
		 * @return [type] [description]
		 */
		public function init()
		{
			parent::init();
			
			// Create nonce.
			if (!YII_DEBUG && 1 == 2) {
				$this->nonce = base64_encode(random_bytes(16));
				Yii::$app->view->registerMetaTag([
					'http-equiv' => 'Content-Security-Policy',
					'content' => "script-src 'nonce-{$this->nonce}' 'strict-dynamic'; object-src 'none';"
				]);
			}
			
			// Set theme.
			// @todo: Move to config.
			$this->view->theme = new \yii\base\Theme([
				'pathMap' => ['@app/views' => '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme],
				'basePath' => '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme,
				'baseUrl' => '@web/themes/' . \giantbits\crelish\Module::getInstance()->theme,
			]);
			
			// Force theming.
			$this->setViewPath('@app/themes/' . \giantbits\crelish\Module::getInstance()->theme . '/' . $this->id);
			
			// Define entry point.
			$this->resolvePathRequested();
		}
		
		/**
		 * [actionRun description]
		 * @return [type] [description]
		 */
		public function actionRun()
		{
			$ds = DIRECTORY_SEPARATOR;
			// 1. Determine entry point.
			// 2. Load entry point content.
			// 3. Assemble sub content from parent entry point content.
			
			// Add content aka. do the magic.
			//$this->data = new CrelishDynamicModel([], ['ctype' => $this->entryPoint['ctype'], 'uuid' => $this->entryPoint['uuid']]);
			$this->data = call_user_func('app\workspace\models\\' . ucfirst($this->entryPoint['ctype']) . '::find')->where(['uuid' => $this->entryPoint['uuid']])->one();
			
			// Set layout.
			$this->setLayout();
			
			// Set view template.
			$this->setViewTemplate();
			
			// Process data and render.
			Yii::$app->params['content'] = $this->data;
			$data = CrelishBaseContentProcessor::processContent($this->entryPoint['ctype'], $this->data);
			
			if (isset(Yii::$app->params['crelish']['pageTitleAttribute']) && isset($data[Yii::$app->params['crelish']['pageTitleAttribute']])) {
				if (isset(Yii::$app->params['crelish']['pageTitle'])) {
					$this->view->title = str_replace('{title}', $data[Yii::$app->params['crelish']['pageTitleAttribute']], Yii::$app->params['crelish']['pageTitle']);
					
				} else {
					$this->view->title = $data[Yii::$app->params['crelish']['pageTitleAttribute']];
				}
			}
			
			if (isset($data['metakeywords'])) {
				\Yii::$app->view->registerMetaTag([
					'name' => 'keywords',
					'content' => $data['metakeywords']
				]);
			}
			
			if (isset($data['metadescription'])) {
				\Yii::$app->view->registerMetaTag([
					'name' => 'description',
					'content' => $data['metadescription']
				]);
			}
			
			return $this->render($this->viewTemplate, ['data' => $data]);
		}
		
		/**
		 * [resolvePathRequested description]
		 * @return [type] [description]
		 */
		private function resolvePathRequested()
		{
			$path = '/';
			$slug = (empty(\Yii::$app->params['crelish']['entryPoint']['slug'])) ? 'slug' : \Yii::$app->params['crelish']['entryPoint']['slug'];
			$ctype = (empty(\Yii::$app->params['crelish']['entryPoint']['ctype'])) ? 'page' : \Yii::$app->params['crelish']['entryPoint']['ctype'];
			
			$this->requestUrl = \Yii::$app->request->getPathInfo();
			
			if (!empty($params = \Yii::$app->request->getQueryParams())) {
				$slug = $params['pathRequested'];
			}
			
			$entryDataJoint = new CrelishDataProvider($ctype, ['filter' => ['slug' => $slug]]);
			if (empty($entryDataJoint->getProvider()->models[0])) {
				$entryModel = null;
			} else {
				$entryModel = $entryDataJoint->getProvider()->models[0];
			}
			
			// 404 Not found fallback
			if ($entryModel == null && isset(Yii::$app->params['crelish']['404slug'])) {
				$slug = Yii::$app->params['crelish']['404slug'];
				$entryModel = Page::find()->where(['=', 'slug', $slug])->one();
				Yii::$app->response->statusCode = 404;
			}
			
			$this->entryPoint = ['ctype' => $ctype, 'slug' => $slug, 'path' => $path, 'uuid' => $entryModel['uuid'], 'template' => $entryModel['template']];
			
		}
		
		/**
		 * [setLayout description]
		 */
		private function setLayout()
		{
			$ds = DIRECTORY_SEPARATOR;
			
			// 1. Was a template given?
			//if(!empty($this->entryPoint['template'])) {
			//$this->layout = '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme . "/layouts/" . $this->entryPoint['template'];
			//} else {
			// 2. Do we have a template file matching the slug?
			$path = \Yii::$app->view->theme->basePath . $ds . 'layouts' . $ds . $this->entryPoint['slug'] . '.twig';
			if (file_exists($path)) {
				$this->layout = '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme . "/layouts/" . $this->entryPoint['slug'] . '.twig';
			} else {
				// 3. Take default main template.
				$this->layout = '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme . "/layouts/main.twig";
			}
			//}
		}
		
		/**
		 * [setViewTemplate description]
		 */
		private function setViewTemplate()
		{
			$ds = DIRECTORY_SEPARATOR;
			$path = \Yii::$app->view->theme->basePath . $ds . \Yii::$app->controller->id . $ds . 'slug' . $ds . $this->entryPoint['slug'] . '.twig';
			$pathByType = \Yii::$app->view->theme->basePath . $ds . \Yii::$app->controller->id . $ds . $this->entryPoint['ctype'] . '.twig';
			$pathByConfig = (!empty($this->data['template'])) ? \Yii::$app->view->theme->basePath . $ds . \Yii::$app->controller->id . $ds . $this->data['template'] : '';
			
			if (file_exists($path)) {
				$this->viewTemplate = 'slug/' . $this->entryPoint['slug'] . '.twig';
			} elseif (file_exists($pathByConfig)) {
				$this->viewTemplate = $this->data['template'];
			} elseif (file_exists($pathByType)) {
				$this->viewTemplate = $this->entryPoint['ctype'] . '.twig';
			} else {
				$this->viewTemplate = 'main.twig';
			}
		}
		
		public function afterAction($action, $result)
		{
			$result = parent::afterAction($action, $result);
			
			if (!YII_DEBUG && 1 == 2) {
				$nonce = Yii::$app->controller->nonce;
				Yii::$app->response->headers->add('Content-Security-Policy', "base-uri 'self'; script-src 'nonce-{$nonce}' 'strict-dynamic'; style-src 'unsafe-inline'; object-src 'none';");
			}
			
			return $result;
		}
	}
