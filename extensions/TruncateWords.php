<?php
	
	namespace giantbits\crelish\extensions;
	
	use Twig\Extension\AbstractExtension;
	use Twig\TwigFilter;
	
	class TruncateWords extends AbstractExtension
	{
		public function getFilters()
		{
			return [
				new TwigFilter('truncateWords', [$this, 'registerTruncateWordsFilter'], ['is_safe' => ['html']]),
			];
		}
		
		public function registerTruncateWordsFilter($text, $limit)
		{
			// Basic word truncation
			$words = explode(' ', $text);
			if (count($words) > $limit) {
				$text = implode(' ', array_slice($words, 0, $limit)) . ' ...';
			}
			
			// Simple HTML parsing (replace with a more robust library if needed)
			$tagStack = [];
			$output = '';
			foreach (preg_split('/(<[^>]+>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $token) {
				if (preg_match('/^<([^>]+)>/', $token, $matches)) { // Tag handling
					$tagName = strtolower($matches[1]);
					if (substr($tagName, 0, 1) === '/') {  // Closing tag
						if (end($tagStack) === substr($tagName, 1)) {
							array_pop($tagStack);
							$output .= $token;
						} else {
							// Mismatched closing tag - ignore for now
						}
					} else { // Opening tag
						$tagStack[] = $tagName;
						$output .= $token;
					}
				} else {
					$output .= $token;
				}
			}
			
			// Close any remaining open tags
			while (!empty($tagStack)) {
				$output .= '</' . array_pop($tagStack) . '>';
			}
			
			return $output;
		}
	}
