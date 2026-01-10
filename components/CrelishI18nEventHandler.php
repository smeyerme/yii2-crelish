<?php

namespace giantbits\crelish\components;

use Yii;
use yii\i18n\MissingTranslationEvent;

/**
 * Event handler for missing translations in Crelish CMS
 *
 * This handler automatically translates missing translations using DeepL API
 * when enabled and stores them in translation files for future use.
 *
 * Uses CrelishTranslationService for the actual translation.
 *
 * Configuration in params.php:
 * - languages: Array of supported languages (e.g., ['de', 'en', 'fr'])
 * - crelish.enable_autotranslation: Boolean to enable/disable auto-translation
 *
 * Environment variables:
 * - DEEPL_API_KEY: Your DeepL API key for translation service
 */
class CrelishI18nEventHandler
{

  /**
   * Handle missing translation events
   *
   * @param MissingTranslationEvent $event The missing translation event
   * @return void
   */
  public static function handleMissingTranslation(MissingTranslationEvent $event): void
  {
    if (empty($event->message)) {
      return;
    }

    // Check if the language is in the list of supported languages
    $supportedLanguages = Yii::$app->params['languages'] ?? [];
    if (!empty($supportedLanguages) && !in_array($event->language, $supportedLanguages)) {
      // Language not supported, don't translate
      $event->translatedMessage = $event->message;
      return;
    }

    // Check if target language is same as source language
    $sourceLanguage = Yii::$app->sourceLanguage ?? 'de';
    if ($event->language === $sourceLanguage ||
      str_starts_with($event->language, $sourceLanguage) ||
      str_starts_with($sourceLanguage, $event->language)) {
      // Same language, no need to translate
      $event->translatedMessage = $event->message;
      return;
    }

    // Initialize vars
    $translatedText = null;
    $category = $event->category;
    $message = $event->message;

    // Initialize file system
    $file = $event->sender->basePath . "/" . $event->language;

    if (!is_dir(Yii::getAlias($file))) {
      mkdir(Yii::getAlias($file));
    }

    $file = Yii::getAlias($file) . "/" . $category . ".php";

    if (file_exists($file)) {
      $translation = include($file);
    } else {
      $translation = [];
    }

    // Use CrelishTranslationService for translation
    if (CrelishTranslationService::isAvailable()) {
      $translationService = new CrelishTranslationService($sourceLanguage);

      // Extract and protect placeholders before translation
      $placeholders = [];
      $messageToTranslate = self::extractPlaceholders($message, $placeholders);

      // Translate the message with placeholders protected
      $translatedText = $translationService->translateText($messageToTranslate, $event->language);

      // Restore placeholders in the translated text
      if ($translatedText !== null) {
        $translatedText = self::restorePlaceholders($translatedText, $placeholders);
      }
    }

    // Update translation file
    if ($translatedText) {
      $translation[$event->message] = $translatedText;
    } else {
      $translation[$event->message] = $event->message;
    }

    ksort($translation);
    self::writeTranslationFile($file, $translation);

    $event->translatedMessage = $translatedText ?? $event->message;
  }

  /**
   * Extract placeholders from a message and replace them with protected tokens
   *
   * @param string $message The message containing placeholders like {name}
   * @param array &$placeholders Reference to array that will store the placeholder mappings
   * @return string The message with placeholders replaced by tokens
   */
  private static function extractPlaceholders(string $message, array &$placeholders): string
  {
    // Match Yii2 placeholders: {word} or {word_with_underscore} etc.
    $pattern = '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/';

    return preg_replace_callback($pattern, function ($matches) use (&$placeholders) {
      $placeholder = $matches[0]; // e.g., {eventTitle}
      $index = count($placeholders);
      // Use XML-like tags that DeepL will preserve (DeepL respects XML/HTML tags)
      $token = "<x id=\"{$index}\"/>";
      $placeholders[$token] = $placeholder;
      return $token;
    }, $message);
  }

  /**
   * Restore original placeholders in the translated text
   *
   * @param string $translatedText The translated text with tokens
   * @param array $placeholders The placeholder mappings (token => original)
   * @return string The translated text with original placeholders restored
   */
  private static function restorePlaceholders(string $translatedText, array $placeholders): string
  {
    foreach ($placeholders as $token => $placeholder) {
      $translatedText = str_replace($token, $placeholder, $translatedText);
    }
    return $translatedText;
  }

  /**
   * Write translation array to PHP file
   *
   * @param string $file File path
   * @param array $translation Translation array
   * @return void
   */
  private static function writeTranslationFile(string $file, array $translation): void
  {
    // Ensure the directory exists (handles nested categories like yii/bootstrap5)
    $directory = dirname($file);
    if (!is_dir($directory)) {
      mkdir($directory, 0755, true);
    }

    $content = "<?php \n";
    $content .= "// This file was autogenerated by CrelishI18n-Event Handler \n";
    $content .= "// You can safely edit it to your needs.\n";
    $content .= "// Last update: " . date("d.m.Y H:i:s", time()) . "\n\n";
    $content .= "return " . var_export($translation, true) . "; \n";

    file_put_contents($file, $content);
  }
}
