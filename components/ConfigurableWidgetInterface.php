<?php

namespace giantbits\crelish\components;

/**
 * Interface for widgets that can be configured through the CMS WidgetConnector.
 *
 * Implementing this interface allows widgets to expose their configuration schema
 * to the CMS, enabling dynamic form generation for widget configuration.
 *
 * Example implementation:
 * ```php
 * class MyWidget extends Widget implements ConfigurableWidgetInterface
 * {
 *     public static function getWidgetMeta(): array
 *     {
 *         return [
 *             'label' => 'My Widget',
 *             'description' => 'A widget that does something useful',
 *             'category' => 'content',
 *         ];
 *     }
 *
 *     public static function getConfigSchema(): array
 *     {
 *         return [
 *             'mode' => [
 *                 'type' => 'select',
 *                 'label' => 'Display Mode',
 *                 'options' => [
 *                     'list' => 'List View',
 *                     'grid' => 'Grid View',
 *                 ],
 *                 'default' => 'list',
 *             ],
 *             'limit' => [
 *                 'type' => 'number',
 *                 'label' => 'Item Limit',
 *                 'default' => 10,
 *                 'min' => 1,
 *                 'max' => 100,
 *             ],
 *         ];
 *     }
 * }
 * ```
 */
interface ConfigurableWidgetInterface
{
    /**
     * Get widget metadata for display in the CMS.
     *
     * @return array{
     *     label: string,
     *     description?: string,
     *     category?: string,
     *     icon?: string
     * }
     *
     * Supported keys:
     * - label: (required) Human-readable widget name
     * - description: Short description of what the widget does
     * - category: Widget category for grouping (e.g., 'content', 'media', 'navigation')
     * - icon: Icon identifier (e.g., 'image', 'list', 'grid')
     */
    public static function getWidgetMeta(): array;

    /**
     * Get the configuration schema for the widget.
     *
     * @return array<string, array{
     *     type: string,
     *     label: string,
     *     default?: mixed,
     *     options?: array,
     *     required?: bool,
     *     hint?: string,
     *     min?: int|float,
     *     max?: int|float,
     *     step?: int|float,
     *     placeholder?: string,
     *     dependsOn?: array
     * }>
     *
     * Supported field types:
     * - 'text': Single line text input
     * - 'textarea': Multi-line text input
     * - 'number': Numeric input (supports min, max, step)
     * - 'select': Dropdown selection (requires 'options')
     * - 'checkbox': Boolean toggle
     * - 'radio': Radio button group (requires 'options')
     * - 'color': Color picker
     * - 'asset': Asset selector (file/image picker)
     * - 'relation': Related content selector (requires 'ctype', optional 'multiple', 'displayField')
     *
     * Field configuration:
     * - type: (required) Field type from the list above
     * - label: (required) Human-readable field label
     * - default: Default value
     * - options: For select/radio - array of value => label pairs
     * - required: Whether the field is required
     * - hint: Help text shown below the field
     * - min/max/step: For number fields
     * - placeholder: Placeholder text for text inputs
     * - dependsOn: Conditional visibility ['field' => 'value'] or ['field' => ['value1', 'value2']]
     * - ctype: For relation fields - the content type to select from (e.g., 'event', 'page')
     * - multiple: For relation fields - allow multiple selections (default: false)
     * - displayField: For relation fields - field to display in selector (default: 'systitle')
     */
    public static function getConfigSchema(): array;
}
