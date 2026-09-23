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
	 * @return object|null The resolved model, or null if no record matches the uuid
	 */
	public static function resolveModel(array $modelInfo): ?object
	{
		$ctype = $modelInfo['ctype'];
		$uuid = $modelInfo['uuid'];
		
		// Handle database models with db: prefix
		if (str_contains($ctype, 'db:')) {
			$ctype = str_replace('db:', '', $ctype);
			$modelClass = CrelishModelResolver::getModelClass($ctype);
			return $modelClass::find()->where(['uuid' => $uuid])->one();
		}
		
		// Use the storage factory to get the appropriate storage implementation
		$storage = CrelishStorageFactory::getStorage($ctype);
		$data = $storage->findOne($ctype, $uuid);
		
		// Kein Treffer: der Verweis zeigt auf einen Datensatz, den es nicht (mehr)
		// gibt — eine gelöschte Relation oder ein Klartextwert aus einem Import.
		// Ein leeres Modell half hier nie weiter: dessen init() nullt die uuid
		// seinerseits, das hohle Objekt lief aber bis in die Formulare durch und
		// zerbrach dort am (string)-Cast. Alle Aufrufer prüfen das Ergebnis
		// ohnehin auf falsy, und der db:-Zweig oben hält es seit jeher so.
		if (!$data) {
			return null;
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
