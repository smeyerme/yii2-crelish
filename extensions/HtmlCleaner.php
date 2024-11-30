<?php
	
	namespace giantbits\crelish\extensions;
	
	class HtmlCleaner
	{
		public static function cleanHtml($html)
		{
			// Allowed tags
			$allowed_tags = '<p><b><strong><i><ul><li><h1><h2><h3><h4><h5><h6>';
			
			// Force UTF-8 encoding by adding a meta tag and correcting invalid encoding characters
			$html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
			
			// Load HTML into DOMDocument
			$dom = new \DOMDocument();
			// Suppress errors for HTML5 tags (optional) and load with UTF-8
			@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
			
			// Remove unwanted attributes like class and style
			self::removeUnwantedAttributes($dom);
			
			// Get the cleaned HTML
			$clean_html = $dom->saveHTML();
			
			// Strip tags that are not in the allowed list
			return strip_tags($clean_html, $allowed_tags);
		}
		
		private static function removeUnwantedAttributes($dom)
		{
			$xpath = new \DOMXPath($dom);
			
			// Select all elements that have class or style attributes
			foreach ($xpath->query('//*[@class or @style]') as $node) {
				// Remove specific unwanted attributes
				$node->removeAttribute('class');
				$node->removeAttribute('style');
				$node->removeAttribute('mso-special'); // example of MSO-specific attribute
				// Add more MSO-specific attributes here if needed
			}
		}
	}
