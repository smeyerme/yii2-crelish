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
			$htmlString = mb_convert_encoding($htmlString, 'HTML-ENTITIES', 'UTF-8');
			
			$dom = new \DOMDocument();
			libxml_use_internal_errors(true);
			$dom->loadHTML($htmlString);
			libxml_clear_errors();
			
			$paragraphs = $dom->getElementsByTagName($tag);
			return $paragraphs->item(0) ? $dom->saveHTML($paragraphs->item(0)) : '';
		}
	}
