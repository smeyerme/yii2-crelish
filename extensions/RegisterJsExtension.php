<?php
	
	namespace giantbits\crelish\extensions;
	
	use MatthiasMullie\Minify\JS;
	use Twig\Extension\AbstractExtension;
	use Twig\TwigFilter;
	use yii\web\View;
	use Yii;
	
	class RegisterJsExtension extends AbstractExtension
	{
		public function getFilters()
		{
			return [
				new TwigFilter('registerJs', [$this, 'registerJsFilter'], ['is_safe' => ['html']]),
			];
		}
		
		/**
		 * $js ending in a semicolon, so a snippet joined after it starts a statement of its own
		 */
		public static function terminated(string $js): string
		{
			$js = rtrim($js);

			return $js === '' || str_ends_with($js, ';') ? $js : $js . ';';
		}

		public function registerJsFilter($js, $position = View::POS_END, $key = null)
		{
			$options = [];
			
			// Add nonce if available
			if(!empty(Yii::$app->controller->nonce)) {
				$options['nonce'] = Yii::$app->controller->nonce;
			}
			
			// Minify JS in production mode
			if (!YII_DEBUG && !YII_ENV_DEV) {
				try {
					$minifier = new JS();
					$minifier->add($js);
					$js = $minifier->minify();
					// The minifier drops a snippet's last semicolon, and Yii joins the
					// snippets of a position with only a line break: "$(...).on(...)" and
					// a following "(function(){...})()" became one call that failed.
					$js = self::terminated($js);
				} catch (\Exception $e) {
					// In production, silently continue with unminified JS
					// Logging might not be available in all production configurations
				}
			}
			
			// Register the JS with proper parameters
			Yii::$app->view->registerJs($js, $position, $key, $options);
			
			return '';
		}
	}
