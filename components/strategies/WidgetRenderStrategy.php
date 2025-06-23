<?php
namespace giantbits\crelish\components\strategies;

use giantbits\crelish\components\interfaces\CrelishWidgetInterface;

/**
 * Interface WidgetRenderStrategy
 * 
 * Strategy pattern for rendering widgets in different contexts
 * 
 * @package giantbits\crelish\components\strategies
 */
interface WidgetRenderStrategy
{
    /**
     * Render the widget
     * 
     * @param CrelishWidgetInterface $widget
     * @param array $context Additional context data
     * @return string
     */
    public function render(CrelishWidgetInterface $widget, array $context = []);

    /**
     * Whether this strategy supports the given widget
     * 
     * @param CrelishWidgetInterface $widget
     * @return bool
     */
    public function supports(CrelishWidgetInterface $widget);

    /**
     * Get initialization script for the widget
     * 
     * @param CrelishWidgetInterface $widget
     * @param array $context
     * @return string|null
     */
    public function getInitScript(CrelishWidgetInterface $widget, array $context = []);
}