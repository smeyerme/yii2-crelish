<?php

namespace giantbits\crelish\extensions;

use giantbits\crelish\components\CrelishGlobals;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension that exposes CrelishGlobals methods to Twig templates
 */
class CrelishGlobalsExtension extends AbstractExtension
{
    /**
     * {@inheritdoc}
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('getCurrentSlug', [$this, 'getCurrentSlug']),
            new TwigFunction('isHomePage', [$this, 'isHomePage']),
            new TwigFunction('getCurrentPage', [$this, 'getCurrentPage']),
        ];
    }

    /**
     * Get the current page slug
     * 
     * @return string The current page slug
     */
    public function getCurrentSlug()
    {
        return CrelishGlobals::getCurrentSlug();
    }

    /**
     * Check if the current page is the homepage
     * 
     * @return bool True if the current page is the homepage
     */
    public function isHomePage()
    {
        return CrelishGlobals::isHomePage();
    }

    /**
     * Get the current page data
     * 
     * @return array|object|null The current page data
     */
    public function getCurrentPage()
    {
        return CrelishGlobals::getCurrentPage();
    }
}