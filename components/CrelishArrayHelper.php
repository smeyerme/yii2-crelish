<?php

namespace giantbits\crelish\components;

/**
 * Array helper utilities replacing lodash-php functions with native PHP.
 *
 * This class provides lodash-like functionality using native PHP,
 * ensuring PHP 8.4+ compatibility without deprecated implicit nullable types.
 */
class CrelishArrayHelper
{
    /**
     * Finds the first element in an array that matches the predicate.
     * Replacement for lodash `find()`.
     *
     * @param iterable $array The array to search
     * @param callable $predicate The function to test each element
     * @return mixed|null The first matching element or null
     */
    public static function find(iterable $array, callable $predicate): mixed
    {
        foreach ($array as $key => $value) {
            if ($predicate($value, $key, $array)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Filters elements of an array using a callback function.
     * Replacement for lodash `filter()`.
     *
     * @param iterable $array The array to filter
     * @param callable $predicate The function to test each element
     * @return array The filtered array
     */
    public static function filter(iterable $array, callable $predicate): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if ($predicate($value, $key, $array)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Applies a callback to each element of an array.
     * Replacement for lodash `map()`.
     *
     * @param iterable $array The array to map
     * @param callable $callback The function to apply to each element
     * @return array The mapped array
     */
    public static function map(iterable $array, callable $callback): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $result[$key] = $callback($value, $key, $array);
        }
        return $result;
    }

    /**
     * Gets a value from a nested array/object using dot notation.
     * Replacement for lodash `get()`.
     *
     * @param array|object $data The data structure to query
     * @param string $path The path using dot notation (e.g., 'user.name')
     * @param mixed $default The default value if path doesn't exist
     * @return mixed The value at the path or the default
     */
    public static function get(array|object $data, string $path, mixed $default = null): mixed
    {
        $keys = explode('.', $path);
        $result = $data;

        foreach ($keys as $key) {
            if (is_array($result) && array_key_exists($key, $result)) {
                $result = $result[$key];
            } elseif (is_object($result) && property_exists($result, $key)) {
                $result = $result->$key;
            } elseif (is_object($result) && method_exists($result, '__get')) {
                $result = $result->$key;
            } else {
                return $default;
            }
        }

        return $result;
    }

    /**
     * Flattens a nested array by one level.
     * Replacement for lodash `flatten()`.
     *
     * @param iterable $array The array to flatten
     * @return array The flattened array
     */
    public static function flatten(iterable $array): array
    {
        $result = [];
        foreach ($array as $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $result[] = $item;
                }
            } else {
                $result[] = $value;
            }
        }
        return $result;
    }

    /**
     * Converts a string to UPPER CASE with spaces between words.
     * Replacement for lodash `upperCase()`.
     *
     * @param string $string The string to convert
     * @return string The uppercase string with spaces
     */
    public static function upperCase(string $string): string
    {
        // Split on camelCase, PascalCase, underscores, hyphens, and spaces
        $words = preg_split('/(?<=[a-z])(?=[A-Z])|[_\-\s]+/', $string);
        return strtoupper(implode(' ', array_filter($words)));
    }
}