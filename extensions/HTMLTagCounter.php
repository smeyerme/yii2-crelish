<?php
	
	namespace giantbits\crelish\extensions;
	class HTMLTagCounter
	{
		private $stack = [];
		
		public function __construct()
		{
			$this->stack = [];
		}
		
		public function handleStartTag($tag, $attrs)
		{
			$this->stack[] = $tag;
		}
		
		public function handleEndTag($tag)
		{
			if (!empty($this->stack) && end($this->stack) === $tag) {
				array_pop($this->stack);
			}
		}
		
		public function getOpenTags()
		{
			return $this->stack;
		}
	}

