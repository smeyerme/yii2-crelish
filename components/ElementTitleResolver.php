<?php

namespace giantbits\crelish\components;

use Yii;
use yii\db\Query;

/**
 * Centralized element title resolver for analytics
 *
 * Resolves element titles by first checking a per-project config file
 * (direct DB table lookups), then falling back to CrelishModelResolver
 * (auto-discovery from model classes).
 *
 * Config file: @app/config/analytics-element-types.php
 * Format:
 *   return [
 *       'news' => ['table' => 'news', 'titleFields' => ['systitle', 'title']],
 *       'asset' => ['table' => 'asset', 'titleFields' => ['title', 'fileName'], 'extraFields' => ['mime', 'size']],
 *   ];
 */
class ElementTitleResolver
{
    /** @var array|null Cached config from analytics-element-types.php */
    private static ?array $config = null;

    /**
     * Resolve the title for an element by UUID and type.
     *
     * @param string $uuid Element UUID
     * @param string $type Element type (ctype)
     * @return string|null Title or null if not found
     */
    public static function resolve(string $uuid, string $type): ?string
    {
        $result = self::resolveFromConfig($uuid, $type, false);
        if ($result !== null) {
            return $result['title'];
        }

        return self::resolveViaCrelishModel($uuid, $type);
    }

    /**
     * Resolve the title and extra fields for an element.
     *
     * Returns an associative array with 'title' and any extra fields
     * defined in the config (e.g. 'mime', 'size' for assets).
     *
     * @param string $uuid Element UUID
     * @param string $type Element type (ctype)
     * @return array|null ['title' => ..., ...extraFields] or null
     */
    public static function resolveWithExtras(string $uuid, string $type): ?array
    {
        $result = self::resolveFromConfig($uuid, $type, true);
        if ($result !== null) {
            return $result;
        }

        // Fallback: CrelishModelResolver (no extra fields)
        $title = self::resolveViaCrelishModel($uuid, $type);
        if ($title !== null) {
            return ['title' => $title];
        }

        return null;
    }

    /**
     * Resolve from the project config file via direct DB query.
     *
     * @param string $uuid
     * @param string $type
     * @param bool $includeExtras Whether to fetch extraFields
     * @return array|null ['title' => ..., ...extras] or null
     */
    private static function resolveFromConfig(string $uuid, string $type, bool $includeExtras): ?array
    {
        $config = self::getConfig();

        if (!isset($config[$type])) {
            return null;
        }

        $typeConfig = $config[$type];
        $table = $typeConfig['table'] ?? null;
        $titleFields = $typeConfig['titleFields'] ?? ['systitle', 'title'];
        $extraFields = ($includeExtras && isset($typeConfig['extraFields']))
            ? $typeConfig['extraFields']
            : [];

        if (empty($table)) {
            return null;
        }

        try {
            $selectFields = array_unique(array_merge(['uuid'], $titleFields, $extraFields));

            $row = (new Query())
                ->select($selectFields)
                ->from('{{%' . $table . '}}')
                ->where(['uuid' => $uuid])
                ->limit(1)
                ->one();

            if (!$row) {
                return null;
            }

            // Determine title from the first non-empty title field
            $title = null;
            foreach ($titleFields as $field) {
                if (!empty($row[$field])) {
                    $title = $row[$field];
                    break;
                }
            }

            if ($title === null) {
                return null;
            }

            $result = ['title' => $title];

            // Attach extra fields
            foreach ($extraFields as $field) {
                $result[$field] = $row[$field] ?? null;
            }

            return $result;
        } catch (\Exception $e) {
            Yii::warning("ElementTitleResolver: config lookup failed for {$type}/{$uuid}: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Fallback: resolve via CrelishModelResolver auto-discovery.
     *
     * @param string $uuid
     * @param string $type
     * @return string|null
     */
    private static function resolveViaCrelishModel(string $uuid, string $type): ?string
    {
        try {
            if (!CrelishModelResolver::modelExists($type)) {
                return null;
            }

            $modelClass = CrelishModelResolver::getModelClass($type);
            $element = $modelClass::find()
                ->where(['uuid' => $uuid])
                ->one();

            if (!$element) {
                return null;
            }

            if (!empty($element['systitle'])) {
                return $element['systitle'];
            }
            if (!empty($element['title'])) {
                return $element['title'];
            }
            if (!empty($element['name'])) {
                return $element['name'];
            }
        } catch (\Exception $e) {
            Yii::warning("ElementTitleResolver: model lookup failed for {$type}/{$uuid}: " . $e->getMessage(), __METHOD__);
        }

        return null;
    }

    /**
     * Load and cache the config from @app/config/analytics-element-types.php.
     *
     * @return array
     */
    private static function getConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $configPath = Yii::getAlias('@app/config/analytics-element-types.php');

        if (file_exists($configPath)) {
            try {
                $loaded = require $configPath;
                self::$config = is_array($loaded) ? $loaded : [];
            } catch (\Throwable $e) {
                Yii::warning("ElementTitleResolver: failed to load config: " . $e->getMessage(), __METHOD__);
                self::$config = [];
            }
        } else {
            self::$config = [];
        }

        return self::$config;
    }

    /**
     * Clear cached config (useful for testing).
     */
    public static function clearCache(): void
    {
        self::$config = null;
    }
}
