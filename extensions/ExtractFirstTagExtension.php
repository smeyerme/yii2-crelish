<?php

namespace giantbits\crelish\extensions;

class ExtractFirstTagExtension extends \Twig\Extension\AbstractExtension
{
  public function getFunctions(): array
  {
    return [
      new \Twig\TwigFunction('extract_first_tag', [$this, 'extractFirstTag']),
    ];
  }

  public function extractFirstTag($tag, $htmlString): bool|string
  {
    // Replace the deprecated method with this approach
    // Add UTF-8 meta tag to help DOMDocument with encoding
    $htmlString = '<?xml encoding="UTF-8">' . $htmlString;

    $dom = new \DOMDocument();
    $dom->encoding = 'UTF-8'; // Set encoding explicitly
    libxml_use_internal_errors(true);
    $dom->loadHTML($htmlString, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $paragraphs = $dom->getElementsByTagName($tag);
    return $paragraphs->item(0) ? $dom->saveHTML($paragraphs->item(0)) : '';
  }
}