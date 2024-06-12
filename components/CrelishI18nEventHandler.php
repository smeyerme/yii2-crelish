<?php
	
	namespace giantbits\crelish\components;
	
	use Doctrine\Inflector\Language;
	use Scn\DeeplApiConnector\DeeplClient;
	use Scn\DeeplApiConnector\Exception\RequestException;
	use Scn\DeeplApiConnector\Model\TranslationConfig;
	use yii\i18n\MissingTranslationEvent;
	
	/**
	 *
	 */
	class CrelishI18nEventHandler
	{
		
		/**
		 * [handleMissingTranslation description]
		 * @param MissingTranslationEvent $event [description]
		 * @return [type]                         [description]
		 */
		public static function handleMissingTranslation(MissingTranslationEvent $event)
		{
			
			if(empty($event->message)){
				return;
			}
			
			$translationObject = null;
			$file = $event->sender->basePath . "/" . $event->language;
			
			if (!is_dir(\Yii::getAlias($file))) {
				mkdir(\Yii::getAlias($file));
			}
			
			$file = \Yii::getAlias($file) . "/" . $event->category . ".php";
			
			if (file_exists($file)) {
				$translation = include($file);
			} else {
				$translation = [];
			}
			
			// Try to leverage Deepl services now.
			$deepl = DeeplClient::create('81e2ffda-aa5a-e813-52e8-b416fd65f4ec:fx');
			
			try {
				$deepTranslation = new \Scn\DeeplApiConnector\Model\TranslationConfig(
					$event->message,
					strtoupper($event->language),
					\Scn\DeeplApiConnector\Enum\LanguageEnum::LANGUAGE_DE,
					['html']
				);
				
				$translationObject = $deepl->getTranslation($deepTranslation);
			} catch (RequestException $exception) {
				var_dump('Got an exception: ' . $exception->getMessage());
			}
			
			if($translationObject) {
				$translation[$event->message] = $translationObject->getText();
				ksort($translation);
				
				file_put_contents($file, "<?php \n// This file was autogenerated by CrelishI18n-Event Handler \n// You can savely edit it to your needs.\n// Last update: " . date("d.m.Y H:i:s", time()) . "\n\n return " . var_export($translation, true) . "; \n");
			}
			
			$event->translatedMessage = $event->message;
		}
	}
