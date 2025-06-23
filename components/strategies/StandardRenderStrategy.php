<?php
namespace giantbits\crelish\components\strategies;

use giantbits\crelish\components\interfaces\CrelishWidgetInterface;

/**
 * Class StandardRenderStrategy
 * 
 * Standard rendering strategy for form context
 * 
 * @package giantbits\crelish\components\strategies
 */
class StandardRenderStrategy implements WidgetRenderStrategy
{
    /**
     * {@inheritdoc}
     */
    public function render(CrelishWidgetInterface $widget, array $context = [])
    {
        // Standard rendering - just call the widget's render method
        return $widget->renderWidget();
    }

    /**
     * {@inheritdoc}
     */
    public function supports(CrelishWidgetInterface $widget)
    {
        // Standard strategy supports all widgets
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getInitScript(CrelishWidgetInterface $widget, array $context = [])
    {
        return $widget->getInitializationScript();
    }
}