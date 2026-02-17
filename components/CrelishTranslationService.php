<?php

namespace giantbits\crelish\components;

use DeepL\DeepLException;
use DeepL\Translator;
use Yii;

/**
 * Service class for translating model fields using DeepL API
 *
 * This service translates all translatable fields of a model from the source
 * language to a target language using the DeepL translation API.
 */
class CrelishTranslationService
{
    private ?Translator $translator = null;
    private string $sourceLanguage;

    /**
     * Language mapping for DeepL API
     * Maps application language codes to DeepL format
     */
    public const LANGUAGE_MAP = [
        'en' => 'EN-US',
        'en-US' => 'EN-US',
        'en-GB' => 'EN-GB',
        'de' => 'DE',
        'de-DE' => 'DE',
        'de-AT' => 'DE',
        'de-CH' => 'DE',
        'fr' => 'FR',
        'fr-FR' => 'FR',
        'es' => 'ES',
        'es-ES' => 'ES',
        'it' => 'IT',
        'it-IT' => 'IT',
        'nl' => 'NL',
        'nl-NL' => 'NL',
        'pl' => 'PL',
        'pl-PL' => 'PL',
        'pt' => 'PT-PT',
        'pt-PT' => 'PT-PT',
        'pt-BR' => 'PT-BR',
        'ru' => 'RU',
        'ru-RU' => 'RU',
        'ja' => 'JA',
        'ja-JP' => 'JA',
        'zh' => 'ZH',
        'zh-CN' => 'ZH',
        'ko' => 'KO',
        'ko-KR' => 'KO',
    ];

    public function __construct(?string $sourceLanguage = null)
    {
        $this->sourceLanguage = $sourceLanguage ?? Yii::$app->sourceLanguage ?? 'de';
    }

    /**
     * Get DeepL language code from application language code
     *
     * @param string $language Application language code (e.g., 'en', 'de')
     * @return string DeepL language code (e.g., 'EN-US', 'DE')
     */
    public static function getDeeplLanguageCode(string $language): string
    {
        return self::LANGUAGE_MAP[$language] ?? strtoupper($language);
    }

    /**
     * Check if auto-translation is enabled and API key is available
     */
    public static function isAvailable(): bool
    {
        $autoTranslationEnabled = Yii::$app->params['crelish']['enable_autotranslation'] ?? false;
        $apiKeyAvailable = !empty($_ENV['DEEPL_API_KEY']);

        return $autoTranslationEnabled && $apiKeyAvailable;
    }

    /**
     * Check if translation should be offered for the given language
     */
    public static function shouldOfferTranslation(string $targetLanguage): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        $sourceLanguage = Yii::$app->sourceLanguage ?? 'de';

        // Don't offer translation if target is same as source
        if ($targetLanguage === $sourceLanguage ||
            str_starts_with($targetLanguage, $sourceLanguage) ||
            str_starts_with($sourceLanguage, $targetLanguage)) {
            return false;
        }

        return true;
    }

    /**
     * Initialize the DeepL translator
     */
    private function initTranslator(): bool
    {
        if ($this->translator !== null) {
            return true;
        }

        $apiKey = $_ENV['DEEPL_API_KEY'] ?? null;
        if (empty($apiKey)) {
            Yii::error('DeepL API key not found in environment variables', 'crelish.translation');
            return false;
        }

        try {
            $this->translator = new Translator($apiKey);
            return true;
        } catch (\Exception $e) {
            Yii::error('Failed to initialize DeepL translator: ' . $e->getMessage(), 'crelish.translation');
            return false;
        }
    }

    /**
     * Translate a single text string
     */
    public function translateText(string $text, string $targetLanguage): ?string
    {
        if (empty($text)) {
            return $text;
        }

        if (!$this->initTranslator()) {
            return null;
        }

        try {
            $deeplTargetLang = self::getDeeplLanguageCode($targetLanguage);
            $deeplSourceLang = self::getDeeplLanguageCode($this->sourceLanguage);

            // DeepL source_lang does not accept regional variants (e.g. EN-US, PT-BR).
            // Strip the region suffix so EN-US becomes EN, PT-PT becomes PT, etc.
            if (str_contains($deeplSourceLang, '-')) {
                $deeplSourceLang = explode('-', $deeplSourceLang, 2)[0];
            }

            $result = $this->translator->translateText(
                $text,
                $deeplSourceLang,
                $deeplTargetLang
            );

            return $result->text;
        } catch (DeepLException $e) {
            Yii::error('DeepL translation failed: ' . $e->getMessage(), 'crelish.translation');
            return null;
        } catch (\Exception $e) {
            Yii::error('Translation failed: ' . $e->getMessage(), 'crelish.translation');
            return null;
        }
    }

    /**
     * Translate a model by loading it from storage and translating all translatable fields
     *
     * Uses CrelishDynamicModel to load the model and its field definitions,
     * then translates all fields marked as translatable from source to target language.
     *
     * @param string $ctype The content type (element type)
     * @param string $uuid The UUID of the model to translate
     * @param string $targetLanguage The target language code
     * @return array Associative array of field keys to translated values
     */
    public function translateModel(string $ctype, string $uuid, string $targetLanguage): array
    {
        $translatedFields = [];

        // Load the model using CrelishDynamicModel - this handles all storage/data loading
        $model = new CrelishDynamicModel([
            'ctype' => $ctype,
            'uuid' => $uuid
        ]);

        if (empty($model->uuid)) {
            Yii::warning("Model not found: ctype={$ctype}, uuid={$uuid}", 'crelish.translation');
            return [];
        }

        // Get field definitions from the model - this comes from the element JSON schema
        $fieldDefinitions = $model->fieldDefinitions;

        if (empty($fieldDefinitions) || empty($fieldDefinitions->fields)) {
            Yii::warning("No field definitions found for ctype: {$ctype}", 'crelish.translation');
            return [];
        }

        // Iterate over all fields and translate those marked as translatable
        foreach ($fieldDefinitions->fields as $field) {
            if (!property_exists($field, 'translatable') || $field->translatable !== true) {
                continue;
            }

            $fieldKey = $field->key;

            // Get source value - the model stores source language values directly on the attribute
            $sourceValue = $model->{$fieldKey} ?? null;

            if (empty($sourceValue)) {
                continue;
            }

            // Translate based on field type
            $fieldType = $field->type ?? 'textInput';

            if ($fieldType === 'jsonEditor' || is_array($sourceValue)) {
                $translatedFields[$fieldKey] = $this->translateJsonContent($sourceValue, $targetLanguage);
            } else {
                $translatedText = $this->translateText($sourceValue, $targetLanguage);
                if ($translatedText !== null) {
                    $translatedFields[$fieldKey] = $translatedText;
                }
            }
        }

        if (empty($translatedFields)) {
            Yii::info("No translatable content found for ctype: {$ctype}, uuid: {$uuid}", 'crelish.translation');
        }

        return $translatedFields;
    }

    /**
     * Recursively translate string values in JSON/array content
     */
    private function translateJsonContent(mixed $content, string $targetLanguage): mixed
    {
        if (is_string($content)) {
            // Try to decode if it's a JSON string
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->translateJsonContent($decoded, $targetLanguage);
            }

            // Regular string - translate it
            return $this->translateText($content, $targetLanguage) ?? $content;
        }

        if (is_array($content)) {
            $translated = [];
            foreach ($content as $key => $value) {
                // Skip certain keys that shouldn't be translated (IDs, types, etc.)
                if (in_array($key, ['uuid', 'id', 'type', 'ctype', 'key', 'slug', 'url', 'src', 'href', 'class', 'style'])) {
                    $translated[$key] = $value;
                } else {
                    $translated[$key] = $this->translateJsonContent($value, $targetLanguage);
                }
            }
            return $translated;
        }

        // Non-string, non-array values are returned as-is
        return $content;
    }

    /**
     * Get the list of translatable field keys for a content type
     */
    public static function getTranslatableFieldKeys(string $ctype): array
    {
        $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);

        if (empty($elementDefinition) || empty($elementDefinition->fields)) {
            return [];
        }

        $translatableKeys = [];
        foreach ($elementDefinition->fields as $field) {
            if (property_exists($field, 'translatable') && $field->translatable === true) {
                $translatableKeys[] = $field->key;
            }
        }

        return $translatableKeys;
    }
}