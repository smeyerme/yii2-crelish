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
	use giantbits\crelish\components\CrelishDataManager;
	use giantbits\crelish\components\CrelishGlobals;
	
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

    public $actionParams = [];
		
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

      // Canonical handliog.
      Yii::$app->canonicalHelper->register([], true);

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
			//$this->data = new CrelishDynamicModel( ['ctype' => $this->entryPoint['ctype'], 'uuid' => $this->entryPoint['uuid']]);
			
			// Make sure the language is set correctly before loading the model
			if (isset(\Yii::$app->request->getQueryParams()['language']) && !empty(\Yii::$app->request->getQueryParams()['language'])) {
				\Yii::$app->language = \Yii::$app->request->getQueryParams()['language'];
			}
			
			$modelClass = CrelishModelResolver::getModelClass($this->entryPoint['ctype']);
			$this->data = $modelClass::find()->where(['uuid' => $this->entryPoint['uuid']])->one();

      // Track page view if analytics component is available
      if (isset(Yii::$app->crelishAnalytics) && $this->entryPoint['uuid']) {
        Yii::$app->crelishAnalytics->trackPageView($this->entryPoint);
      }

			// Set layout.
			$this->setLayout();
			
			// Set view template.
			$this->setViewTemplate();
			
			// Process data and render.
			Yii::$app->params['content'] = $this->data;
			$data = CrelishBaseContentProcessor::processContent($this->entryPoint['ctype'], $this->data);
			
			// Check if any widget returned a Response object (e.g., redirect)
			if (Yii::$app->has('widgetResponse')) {
				$response = Yii::$app->get('widgetResponse');
				Yii::$app->clear('widgetResponse'); // Clean up
        return $response; // Return the response directly (redirect, etc.)
			}

			if (isset(Yii::$app->params['crelish']['pageTitleAttribute']) && isset($data[Yii::$app->params['crelish']['pageTitleAttribute']])) {
				if (isset(Yii::$app->params['crelish']['pageTitle'])) {
					$this->view->title = str_replace('{title}', $data[Yii::$app->params['crelish']['pageTitleAttribute']], Yii::$app->params['crelish']['pageTitle']);
				} else {
					$this->view->title = $data[Yii::$app->params['crelish']['pageTitleAttribute']];
				}
			}
			
			if (isset($data['metakeywords']) && strlen($data['metakeywords']) > 4) {
				\Yii::$app->view->registerMetaTag([
					'name' => 'keywords',
					'content' => $data['metakeywords']
				]);
			}
			
			if (isset($data['metadescription']) && strlen($data['metadescription']) > 4) {
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

			// Track if we need to show 404 due to unsupported language
			$unsupportedLanguage = false;
			$requestedLanguage = null;

			if (!empty($params = \Yii::$app->request->getQueryParams())) {
				$slug = $params['pathRequested'] ?? $slug;
				// Check language from query params
				if (isset($params['language']) && !empty($params['language'])) {
					$requestedLanguage = $params['language'];
				}
			}

			// Also check URL path for language prefix (e.g., /ru/page) as fallback
			if ($requestedLanguage === null) {
				$pathInfo = \Yii::$app->request->getPathInfo();
				if (preg_match('/^([a-z]{2})(?:\/|$)/', $pathInfo, $matches)) {
					$requestedLanguage = $matches[1];
				}
			}

      // Validate and set language
			if ($requestedLanguage !== null) {
				if ($this->isLanguageSupported($requestedLanguage)) {
					\Yii::$app->language = $requestedLanguage;
				} else {
					// Unsupported language - will show 404 page
					$unsupportedLanguage = true;
				}
			}

			// Get the current language for model loading
			$langCode = \Yii::$app->language;
			// Extract 2-letter code from full code like 'en-US'
			if (preg_match('/([a-z]{2})-[A-Z]{2}/', $langCode, $sub)) {
				$langCode = $sub[1];
			}

			$entryModel = null;

			// Only look for content if language is supported
			if (!$unsupportedLanguage) {
				$entryDataJoint = new CrelishDataManager($ctype, ['filter' => ['slug' => ['strict', $slug]]]);
				if (!empty($entryDataJoint->getProvider()->models[0])) {
					$entryModel = $entryDataJoint->getProvider()->models[0];
				}
			}

			// 404 Not found fallback (also handles unsupported language)
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
			
			$path = \Yii::$app->view->theme->basePath . $ds . 'layouts' . $ds . $this->entryPoint['template'];
			
			// 1. Was a template given?
			if (file_exists($path)) {
				$this->layout = '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme . "/layouts/" . $this->entryPoint['template'];
			} else {
				$path = \Yii::$app->view->theme->basePath . $ds . 'layouts' . $ds . $this->entryPoint['slug'] . '.twig';
				if (file_exists($path)) {
					$this->layout = '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme . "/layouts/" . $this->entryPoint['slug'] . '.twig';
				} else {
					// 3. Take default main template.
					$this->layout = '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme . "/layouts/main.twig";
				}
			}
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

		/**
		 * Check if the requested language is in the list of supported languages.
		 *
		 * @param string $language The language code to check
		 * @return bool True if language is supported, false otherwise
		 */
		protected function isLanguageSupported(string $language): bool
		{
			// Get supported languages from params (check both locations for flexibility)
			$supportedLanguages = Yii::$app->params['crelish']['languages']
				?? Yii::$app->params['languages']
				?? [];

			// If no languages configured, allow all (backwards compatibility)
			if (empty($supportedLanguages)) {
				return true;
			}

			// Normalize language code (handle cases like 'en-US' -> 'en')
			$normalizedLanguage = $language;
			if (preg_match('/^([a-z]{2})-[A-Z]{2}$/', $language, $matches)) {
				$normalizedLanguage = $matches[1];
			}

			// Check if language is supported
			return in_array($normalizedLanguage, $supportedLanguages, true) ||
				in_array($language, $supportedLanguages, true);
		}
	}