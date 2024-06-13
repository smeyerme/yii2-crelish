<?php
	
	namespace giantbits\crelish\controllers;
	
	use Yii;
	use yii\helpers\Html;
	use yii\web\Controller;
	use yii\helpers\Json;
	use yii\web\Response;
	
	class TranslationController extends Controller
	{
		
		public $nonce;
		public $layout = 'crelish.twig';
		
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
		
		
		public function actionIndex($language = 'fr')
		{
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
