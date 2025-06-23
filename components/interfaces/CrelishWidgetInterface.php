<?php
namespace giantbits\crelish\components\interfaces;

/**
 * Interface CrelishWidgetInterface
 * 
 * Defines the contract for all Crelish form widgets
 * 
 * @package giantbits\crelish\components\interfaces
 */
interface CrelishWidgetInterface
{
    /**
     * Process and prepare the widget's data
     * 
     * @param mixed $data The raw data value
     * @return mixed The processed data
     */
    public function processData($data);

    /**
     * Get the widget's current value
     * 
     * @return mixed
     */
    public function getValue();

    /**
     * Set the widget's value
     * 
     * @param mixed $value
     * @return void
     */
    public function setValue($value);

    /**
     * Get the field definition object
     * 
     * @return \stdClass|null
     */
    public function getFieldDefinition();

    /**
     * Register widget assets (CSS, JS)
     * 
     * @return void
     */
    public function registerAssets();

    /**
     * Render the widget HTML
     * 
     * @return string
     */
    public function renderWidget();

    /**
     * Get widget-specific JavaScript initialization code
     * 
     * @return string|null
     */
    public function getInitializationScript();

    /**
     * Whether this widget supports AJAX rendering
     * 
     * @return bool
     */
    public function supportsAjaxRendering();

    /**
     * Get widget metadata for client-side initialization
     * 
     * @return array
     */
    public function getClientConfig();
}