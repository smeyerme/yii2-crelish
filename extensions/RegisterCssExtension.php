<?php
	
	namespace giantbits\crelish\extensions;
	
	use MatthiasMullie\Minify\CSS;
	use Twig\Extension\AbstractExtension;
	use Twig\TwigFilter;
	use Yii;
	
	class RegisterCssExtension extends AbstractExtension
	{
		public function getFilters()
		{
			return [
				new TwigFilter('registerCss', [$this, 'registerCssFilter'], ['is_safe' => ['html']]),
			];
		}
		
		public function registerCssFilter($css, $key = null)
		{
			$options = [];
			
			// Add nonce if available
			if(!empty(Yii::$app->controller->nonce)) {
				$options['nonce'] = Yii::$app->controller->nonce;
			}
			
			// Minify CSS in production mode
			if (!YII_DEBUG && !YII_ENV_DEV) {
				try {
					$minifier = new CSS();
					$minifier->add($css);
					$css = $minifier->minify();
				} catch (\Exception $e) {
					// In production, silently continue with unminified CSS
					// Logging might not be available in all production configurations
				}
			}
			
			// Register the CSS
			Yii::$app->view->registerCss($css, $options, $key);
			
			return '';
		}
	}
