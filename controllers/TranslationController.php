<?php
	
namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
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
}