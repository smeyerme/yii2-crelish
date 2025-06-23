<?php
namespace giantbits\crelish\components\interfaces;

/**
 * Interface CrelishContentProcessorInterface
 * 
 * Defines the contract for all Crelish content processors
 * 
 * @package giantbits\crelish\components\interfaces
 */
interface CrelishContentProcessorInterface
{
    /**
     * Process data before rendering (for display)
     * 
     * @param string $key The field key
     * @param mixed $data The raw data
     * @param array &$processedData The processed data array (passed by reference)
     * @param \stdClass|null $fieldConfig The field configuration
     * @return void
     */
    public static function processData($key, $data, &$processedData, $fieldConfig = null);

    /**
     * Process data before saving to storage
     * 
     * @param string $key The field key
     * @param mixed $data The data to save
     * @param \stdClass|null $fieldConfig The field configuration
     * @param mixed &$parent The parent object/array (passed by reference)
     * @return void
     */
    public static function processDataPreSave($key, $data, $fieldConfig, &$parent);

    /**
     * Process data after saving to storage
     * 
     * @param string $key The field key
     * @param mixed $data The saved data
     * @param \stdClass|null $fieldConfig The field configuration
     * @param mixed &$parent The parent object/array (passed by reference)
     * @return void
     */
    public static function processDataPostSave($key, $data, $fieldConfig, &$parent);

    /**
     * Process JSON data (legacy method for compatibility)
     * 
     * @param string $ctype The content type
     * @param string $key The field key
     * @param mixed $data The data
     * @param array &$processedData The processed data array
     * @return void
     */
    public static function processJson($ctype, $key, $data, &$processedData);
}