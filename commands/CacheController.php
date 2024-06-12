<?php
	
	namespace giantbits\crelish\commands;
	
	use yii\console\Controller;
	
	class CacheController extends Controller
	{
		public function actionFlushCaches()
		{
			\Yii::$app->cache->flush();
			
			// Check for APC
			if (extension_loaded('apc') && ini_get('apc.enabled')) {
				apc_clear_cache();
				apc_clear_cache('user');
				apc_clear_cache('opcode');
				echo "APC cache cleared.\n";
			} else {
				echo "APC cache is not enabled or not loaded.\n";
			}
			
			// Check for OPcache
			if (extension_loaded('Zend OPcache') && ini_get('opcache.enable')) {
				opcache_reset();
				echo "OPcache reset.\n";
			} else {
				echo "OPcache is not enabled or not loaded.\n";
			}
		}
	}
