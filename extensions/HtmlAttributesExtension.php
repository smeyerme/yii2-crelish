<?php

namespace giantbits\crelish\extensions;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use yii\helpers\Html;

/**
 * Twig extension for rendering HTML attributes
 */
class HtmlAttributesExtension extends AbstractExtension
{
    /**
     * @inheritdoc
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('html_attributes', [$this, 'renderHtmlAttributes'], ['is_safe' => ['html']]),
        ];
    }
    
    /**
     * Render HTML attributes using Yii's Html helper
     * 
     * @param array $attributes The attributes to render
     * @return string The rendered attributes
     */
    public function renderHtmlAttributes($attributes)
    {
        return Html::renderTagAttributes($attributes);
    }
} 