<?php
	
namespace giantbits\crelish\components;

use yii\data\DataProviderInterface;

/**
 * Class CrelishDataResolver
 * 
 * Resolves models and data providers based on content type
 */
class CrelishDataResolver
{
	/**
	 * Resolve a model based on content type and UUID
	 * 
	 * @param array $modelInfo Model information
	 * @return mixed The resolved model
	 */
	public static function resolveModel(array $modelInfo)
	{
		$ctype = $modelInfo['ctype'];
		$uuid = $modelInfo['uuid'];
		
		// Handle database models with db: prefix
		if (str_contains($ctype, 'db:')) {
			$ctype = str_replace('db:', '', $ctype);
			return call_user_func_array('app\workspace\models\\' . ucfirst($ctype) . '::find', ['uuid' => $uuid])->one();
		}
		
		// Use the storage factory to get the appropriate storage implementation
		$storage = CrelishStorageFactory::getStorage($ctype);
		$data = $storage->findOne($ctype, $uuid);
		
		if (!$data) {
			return new CrelishDynamicJsonModel([], ['ctype' => $ctype, 'uuid' => $uuid]);
		}
		
		return new CrelishDynamicJsonModel($data, ['ctype' => $ctype, 'uuid' => $uuid]);
	}
	
	/**
	 * Resolve a data provider based on content type and options
	 * 
	 * @param string $ctype Content type
	 * @param array $options Options for the data provider
	 * @return DataProviderInterface The resolved data provider
	 */
	public static function resolveProvider(string $ctype, array $options): DataProviderInterface
	{
		$filter = $options['filter'] ?? [];
		$sort = $options['sort'] ?? [];
		$pageSize = $options['pageSize'] ?? 30;
		
		// Use the storage factory to get the appropriate storage implementation
		$storage = CrelishStorageFactory::getStorage($ctype);
		
		return $storage->getDataProvider($ctype, $filter, $sort, $pageSize);
	}
}
