<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishTranslationService;
use Yii;
use yii\helpers\Html;
use yii\web\Response;

class TranslationController extends CrelishBaseController
{
	
	public $nonce;
	public $layout = 'crelish.twig';
	
	/**
	 * Override the setupHeaderBar method for translation-specific components
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
				// For translation index, add custom components
				$this->view->params['headerBarLeft'][] = 'translation-search'; // Custom search component
				$this->view->params['headerBarRight'][] = ['save', false];
				break;
				
			default:
				// For other actions, just keep the defaults
				break;
		}
	}
	
	public function actionSave($language)
	{
		$allTranslations = Yii::$app->request->post('Translations', []);
		foreach ($allTranslations as $category => $translations) {
			$content = "<?php\nreturn " . var_export($translations, true) . ";\n";
			$path = Yii::getAlias('@app/messages/' . $language . '/' . $category . '.php');
			if (file_put_contents($path, $content) === false) {
				Yii::$app->session->setFlash('error', "Failed to update translations for {$category}.");
				return $this->redirect(['/crelish/translation/index', 'language' => $language]);
			}
		}
		Yii::$app->session->setFlash('success', 'Translations updated successfully.');
		return $this->redirect(['/crelish/translation/index', 'language' => $language]);
	}
	
	
	public function actionIndex($language = null)
	{
      if(!$language) {
        $language = Yii::$app->language;
      }

		$translations = $this->getAllTranslations($language);
		
		return $this->render('edit.twig', [
				'translations' => $translations,
				'language' => $language,
				'_csrfParam' => Yii::$app->request->csrfParam,
				'_csrfToken' => Yii::$app->request->csrfToken,
			]
		);
	}
	
	protected function getTranslationFiles($language)
	{
		$path = Yii::getAlias('@app/messages/' . $language);
		$files = [];
		if (is_dir($path)) {
			$directoryIterator = new \DirectoryIterator($path);
			foreach ($directoryIterator as $fileinfo) {
				if (!$fileinfo->isDot() && $fileinfo->isFile() && $fileinfo->getExtension() === 'php') {
					$files[] = $fileinfo->getFilename();
				}
			}
		}
		return $files;
	}
	
	protected function getAllTranslations($language)
	{
		$files = $this->getTranslationFiles($language);
		$translations = [];
		foreach ($files as $file) {
			$filePath = Yii::getAlias('@app/messages/' . $language . '/' . $file);
			$category = basename($file, '.php');
			$translations[$category] = include($filePath);
		}
		return $translations;
	}

	/**
	 * AJAX action to translate model fields using DeepL
	 *
	 * Expects JSON POST data with:
	 * - ctype: Content type (element type)
	 * - uuid: UUID of the model to translate
	 * - targetLanguage: Target language code
	 *
	 * @return array JSON response with translations or error
	 */
	public function actionTranslate()
	{
		Yii::$app->response->format = Response::FORMAT_JSON;

		// Disable CSRF validation for AJAX requests
		$this->enableCsrfValidation = false;

		// Check if request is POST
		if (!Yii::$app->request->isPost) {
			return [
				'success' => false,
				'error' => Yii::t('app', 'Invalid request method')
			];
		}

		// Get JSON input
		$rawInput = Yii::$app->request->rawBody;
		$input = json_decode($rawInput, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return [
				'success' => false,
				'error' => Yii::t('app', 'Invalid JSON data')
			];
		}

		$ctype = $input['ctype'] ?? null;
		$uuid = $input['uuid'] ?? null;
		$targetLanguage = $input['targetLanguage'] ?? null;

		// Validate required parameters
		if (empty($ctype)) {
			return [
				'success' => false,
				'error' => Yii::t('app', 'Content type is required')
			];
		}

		if (empty($uuid)) {
			return [
				'success' => false,
				'error' => Yii::t('app', 'Please save the content first before translating')
			];
		}

		if (empty($targetLanguage)) {
			return [
				'success' => false,
				'error' => Yii::t('app', 'Target language is required')
			];
		}

		// Check if translation service is available
		if (!CrelishTranslationService::isAvailable()) {
			return [
				'success' => false,
				'error' => Yii::t('app', 'Translation service is not available. Please check your configuration.')
			];
		}

		// Check if we should offer translation for this language
		if (!CrelishTranslationService::shouldOfferTranslation($targetLanguage)) {
			return [
				'success' => false,
				'error' => Yii::t('app', 'Translation to this language is not supported')
			];
		}

		try {
			$translationService = new CrelishTranslationService();
			$translations = $translationService->translateModel($ctype, $uuid, $targetLanguage);

			if (empty($translations)) {
				return [
					'success' => false,
					'error' => Yii::t('app', 'No translatable fields found or translation failed')
				];
			}

			return [
				'success' => true,
				'translations' => $translations,
				'targetLanguage' => $targetLanguage,
				'fieldsTranslated' => count($translations)
			];

		} catch (\Exception $e) {
			Yii::error('Model translation failed: ' . $e->getMessage(), 'crelish.translation');

			return [
				'success' => false,
				'error' => Yii::t('app', 'Translation failed: {error}', ['error' => $e->getMessage()])
			];
		}
	}

	/**
	 * Override behaviors to allow AJAX access
	 */
	public function behaviors()
	{
		$behaviors = parent::behaviors();

		// Allow AJAX requests for translate action
		return $behaviors;
	}

	/**
	 * Disable CSRF for translate action
	 */
	public function beforeAction($action)
	{
		if ($action->id === 'translate') {
			$this->enableCsrfValidation = false;
		}
		return parent::beforeAction($action);
	}
}