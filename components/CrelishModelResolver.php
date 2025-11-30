<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\InvalidArgumentException;

/**
 * Centralized model class resolver for Crelish CMS
 *
 * This class handles the mapping between content type identifiers (ctype) and their
 * corresponding PHP model classes. It uses auto-discovery based on the $ctype property
 * defined in model classes, allowing developers to use proper PSR-4 naming conventions.
 *
 * Example:
 * - A model class `EventCategory` with `public $ctype = 'eventcategory'`
 * - Will be automatically discovered and mapped
 * - No manual configuration required
 *
 * Backwards Compatible:
 * - Existing models using the old naming convention (e.g., `Eventcategory`) continue to work
 * - The resolver first checks the auto-discovered map, then falls back to ucfirst() convention
 */
class CrelishModelResolver
{
    /**
     * @var array|null Cached model map [ctype => fully qualified class name]
     */
    private static ?array $modelMap = null;

    /**
     * @var int|null Timestamp of when the cache was built
     */
    private static ?int $cacheTime = null;

    /**
     * @var string Cache file path
     */
    private static string $cacheFile = '@runtime/crelish/model_map.php';

    /**
     * Get the fully qualified model class name for a content type
     *
     * @param string $ctype Content type identifier
     * @return string Fully qualified class name
     * @throws InvalidArgumentException If no model is found for the ctype
     */
    public static function getModelClass(string $ctype): string
    {
        $map = self::getModelMap();

        // First, check the auto-discovered map
        if (isset($map[$ctype])) {
            return $map[$ctype];
        }

        // Fallback to legacy ucfirst() convention for backwards compatibility
        $legacyClass = "app\\workspace\\models\\" . ucfirst($ctype);
        if (class_exists($legacyClass)) {
            return $legacyClass;
        }

        throw new InvalidArgumentException("No model found for content type: $ctype");
    }

    /**
     * Check if a model exists for the given content type
     *
     * @param string $ctype Content type identifier
     * @return bool True if a model exists
     */
    public static function modelExists(string $ctype): bool
    {
        try {
            self::getModelClass($ctype);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Get all registered content types and their model classes
     *
     * @return array Map of [ctype => class name]
     */
    public static function getAllModels(): array
    {
        return self::getModelMap();
    }

    /**
     * Get the model map, building it if necessary
     *
     * @return array Model map [ctype => fully qualified class name]
     */
    private static function getModelMap(): array
    {
        // Return from memory cache if available
        if (self::$modelMap !== null) {
            return self::$modelMap;
        }

        // Try to load from file cache in production
        if (!YII_DEBUG) {
            $cached = self::loadFromFileCache();
            if ($cached !== null) {
                self::$modelMap = $cached;
                return self::$modelMap;
            }
        }

        // Build the map by scanning model files
        self::$modelMap = self::buildModelMap();

        // Save to file cache in production
        if (!YII_DEBUG) {
            self::saveToFileCache(self::$modelMap);
        }

        return self::$modelMap;
    }

    /**
     * Build the model map by scanning the workspace/models directory
     *
     * @return array Model map [ctype => fully qualified class name]
     */
    private static function buildModelMap(): array
    {
        $map = [];
        $modelsPath = Yii::getAlias('@app/workspace/models');

        if (!is_dir($modelsPath)) {
            Yii::warning("Models directory not found: $modelsPath", __METHOD__);
            return $map;
        }

        $files = glob($modelsPath . '/*.php');

        foreach ($files as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $fullClass = "app\\workspace\\models\\$className";

            // Skip if class doesn't exist or can't be loaded
            if (!class_exists($fullClass)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($fullClass);

                // Skip abstract classes and interfaces
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                // Check if the class has a ctype property
                if ($reflection->hasProperty('ctype')) {
                    $ctypeProp = $reflection->getProperty('ctype');

                    // Get the default value if it's a public property
                    if ($ctypeProp->isPublic()) {
                        // For PHP 8+, we can get default value directly
                        if (PHP_VERSION_ID >= 80000 && $ctypeProp->hasDefaultValue()) {
                            $ctypeValue = $ctypeProp->getDefaultValue();
                        } else {
                            // For older PHP, instantiate without constructor
                            $instance = $reflection->newInstanceWithoutConstructor();
                            $ctypeValue = $ctypeProp->getValue($instance);
                        }

                        if (!empty($ctypeValue) && is_string($ctypeValue)) {
                            $map[$ctypeValue] = $fullClass;
                        }
                    }
                }
            } catch (\ReflectionException $e) {
                Yii::warning("Could not reflect class $fullClass: " . $e->getMessage(), __METHOD__);
                continue;
            } catch (\Throwable $e) {
                Yii::warning("Error processing model $fullClass: " . $e->getMessage(), __METHOD__);
                continue;
            }
        }

        return $map;
    }

    /**
     * Load model map from file cache
     *
     * @return array|null Model map or null if cache is invalid/missing
     */
    private static function loadFromFileCache(): ?array
    {
        $cacheFile = Yii::getAlias(self::$cacheFile);

        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check if models directory has been modified since cache was created
        $modelsPath = Yii::getAlias('@app/workspace/models');
        $cacheTime = filemtime($cacheFile);
        $modelsTime = self::getDirectoryModTime($modelsPath);

        if ($modelsTime > $cacheTime) {
            // Cache is stale, rebuild
            return null;
        }

        try {
            $cached = require $cacheFile;
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            Yii::warning("Could not load model map cache: " . $e->getMessage(), __METHOD__);
        }

        return null;
    }

    /**
     * Save model map to file cache
     *
     * @param array $map Model map to cache
     */
    private static function saveToFileCache(array $map): void
    {
        $cacheFile = Yii::getAlias(self::$cacheFile);
        $cacheDir = dirname($cacheFile);

        try {
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0775, true);
            }

            $content = "<?php\n// Auto-generated by CrelishModelResolver\n// Generated: " . date('Y-m-d H:i:s') . "\nreturn " . var_export($map, true) . ";\n";
            file_put_contents($cacheFile, $content, LOCK_EX);
        } catch (\Throwable $e) {
            Yii::warning("Could not save model map cache: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Get the most recent modification time of files in a directory
     *
     * @param string $path Directory path
     * @return int Unix timestamp
     */
    private static function getDirectoryModTime(string $path): int
    {
        $maxTime = 0;

        if (!is_dir($path)) {
            return $maxTime;
        }

        $files = glob($path . '/*.php');
        foreach ($files as $file) {
            $fileTime = filemtime($file);
            if ($fileTime > $maxTime) {
                $maxTime = $fileTime;
            }
        }

        return $maxTime;
    }

    /**
     * Clear the cached model map
     * Forces a rebuild on next access
     */
    public static function clearCache(): void
    {
        self::$modelMap = null;
        self::$cacheTime = null;

        $cacheFile = Yii::getAlias(self::$cacheFile);
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    /**
     * Refresh the model map
     * Useful after adding new models at runtime
     *
     * @return array The refreshed model map
     */
    public static function refresh(): array
    {
        self::clearCache();
        return self::getModelMap();
    }
}
