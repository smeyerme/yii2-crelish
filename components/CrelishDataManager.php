<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\Component;
use yii\data\DataProviderInterface;

/**
 * Unified data manager for Crelish CMS
 *
 * This class provides a unified interface for working with content in Crelish CMS,
 * regardless of the underlying storage mechanism (JSON files or database).
 */
class CrelishDataManager extends Component
{
  /**
   * @var string Content type
   */
  private $ctype;

  /**
   * @var array Settings
   */
  private $settings = [];

  /**
   * @var string UUID
   */
  private $uuid;

  /**
   * @var CrelishDataStorage Storage implementation
   */
  private $storage;

  /**
   * @var \stdClass Element definition
   */
  private $definitions;

  /**
   * Constructor
   *
   * @param string $ctype Content type
   * @param array $settings Settings
   * @param string|null $uuid UUID
   * @param bool $autoSetRelations Whether to automatically set relations for queries
   */
  public function __construct(string $ctype, array $settings = [], string $uuid = null, bool $autoSetRelations = false)
  {
    $this->ctype = $ctype;
    $this->settings = $settings;
    $this->uuid = $uuid;
    $this->storage = CrelishStorageFactory::getStorage($ctype);
    $this->definitions = $this->getDefinitions();

    parent::__construct();
    
    // Auto-set relations if requested
    if ($autoSetRelations) {
      $this->initRelations();
    }
  }

  /**
   * Get a single record
   *
   * @return array|null Record data
   */
  public function one(): ?array
  {
    if (empty($this->uuid)) {
      return null;
    }

    return $this->storage->findOne($this->ctype, $this->uuid);
  }

  /**
   * Get all records
   *
   * @param bool $withRelations Whether to include relations in the query
   * @return array Array of records and pagination
   */
  public function all(bool $withRelations = false): array
  {
    $filter = $this->settings['filter'] ?? [];
    $sort = $this->settings['sort'] ?? [];
    $pageSize = $this->settings['pageSize'] ?? 30;

    // Ensure sort is in the correct format
    if (!empty($sort) && !isset($sort['defaultOrder'])) {
      $sort = ['defaultOrder' => $sort];
    }

    if(empty($sort) && !empty($this->definitions->sortDefault)) {
      // Get sort defaults from definition
      $defaultSort = (array) $this->definitions->sortDefault;
      $processedSort = [];
      
      // Process each sort field
      foreach ($defaultSort as $field => $direction) {
        // Convert string constants to actual constant values or default to SORT_ASC
        if (is_string($direction)) {
          if ($direction === 'SORT_ASC' || strtolower($direction) === 'asc') {
            $processedSort[$field] = SORT_ASC;
          } elseif ($direction === 'SORT_DESC' || strtolower($direction) === 'desc') {
            $processedSort[$field] = SORT_DESC;
          } else {
            // Default to SORT_ASC for backward compatibility
            $processedSort[$field] = SORT_ASC;
          }
        } else {
          // Use the provided value if it's already a number
          $processedSort[$field] = $direction;
        }
      }
      
      $sort = ['defaultOrder' => $processedSort];
    }

    $dataProvider = $this->storage->getDataProvider($this->ctype, $filter, $sort, $pageSize);

    // Set up relations if needed
    if ($withRelations && $dataProvider instanceof \yii\data\ActiveDataProvider) {
      $this->setRelations($dataProvider->query);
    }

    return [
      'models' => $dataProvider->getModels(),
      'pagination' => $dataProvider->getPagination(),
    ];
  }

  /**
   * Get a data provider
   * 
   * @param bool $withRelations Whether to include relations in the query
   * @return DataProviderInterface Data provider
   */
  public function getProvider(bool $withRelations = false): DataProviderInterface
  {
    $filter = $this->settings['filter'] ?? [];
    $sort = $this->settings['sort'] ?? [];
    $pageSize = $this->settings['pageSize'] ?? 30;

    // Ensure sort is in the correct format
    if (!empty($sort) && !isset($sort['defaultOrder'])) {
      $sort = ['defaultOrder' => $sort];
    }
    
    if(empty($sort) && !empty($this->definitions->sortDefault)) {
      // Get sort defaults from definition
      $defaultSort = (array) $this->definitions->sortDefault;
      $processedSort = [];
      
      // Process each sort field
      foreach ($defaultSort as $field => $direction) {
        // Convert string constants to actual constant values or default to SORT_ASC
        if (is_string($direction)) {
          if ($direction === 'SORT_ASC' || strtolower($direction) === 'asc') {
            $processedSort[$field] = SORT_ASC;
          } elseif ($direction === 'SORT_DESC' || strtolower($direction) === 'desc') {
            $processedSort[$field] = SORT_DESC;
          } else {
            // Default to SORT_ASC for backward compatibility
            $processedSort[$field] = SORT_ASC;
          }
        } else {
          // Use the provided value if it's already a number
          $processedSort[$field] = $direction;
        }
      }
      
      $sort = ['defaultOrder' => $processedSort];
    }

    $dataProvider = $this->storage->getDataProvider($this->ctype, $filter, $sort, $pageSize);
    
    // Set up relations if needed
    if ($withRelations && $dataProvider instanceof \yii\data\ActiveDataProvider) {
      $this->setRelations($dataProvider->query);
    }
    
    return $dataProvider;
  }

  /**
   * Get all records as raw data
   *
   * @param bool $withRelations Whether to include relations in the query
   * @return array Array of records
   */
  public function rawAll(bool $withRelations = false): array
  {
    $filter = $this->settings['filter'] ?? [];
    $sort = $this->settings['sort'] ?? [];

    // Ensure sort is in the correct format
    if (!empty($sort) && !isset($sort['defaultOrder'])) {
      $sort = ['defaultOrder' => $sort];
    }
    
    if(empty($sort) && !empty($this->definitions->sortDefault)) {
      // Get sort defaults from definition
      $defaultSort = (array) $this->definitions->sortDefault;
      $processedSort = [];
      
      // Process each sort field
      foreach ($defaultSort as $field => $direction) {
        // Convert string constants to actual constant values or default to SORT_ASC
        if (is_string($direction)) {
          if ($direction === 'SORT_ASC' || strtolower($direction) === 'asc') {
            $processedSort[$field] = SORT_ASC;
          } elseif ($direction === 'SORT_DESC' || strtolower($direction) === 'desc') {
            $processedSort[$field] = SORT_DESC;
          } else {
            // Default to SORT_ASC for backward compatibility
            $processedSort[$field] = SORT_ASC;
          }
        } else {
          // Use the provided value if it's already a number
          $processedSort[$field] = $direction;
        }
      }
      
      $sort = ['defaultOrder' => $processedSort];
    }
    
    // If relations are needed, we need to get a query object, 
    // add the relation joins, and then execute the query manually
    if ($withRelations && $this->storage instanceof CrelishDbStorage) {
      $query = $this->getQuery();
      if ($query) {
        $this->setRelations($query);
        
        // Apply filters
        if (!empty($filter)) {
          foreach ($filter as $attribute => $value) {
            if ($attribute === 'freesearch') {
              // Handle freesearch (implementation should match storage class)
              $searchFragments = explode(" ", trim($value));
              $orConditions = ['or'];
              
              $modelClass = $this->storage->getModelClass($this->ctype);
              $tableSchema = $modelClass::getTableSchema();
              
              foreach ($tableSchema->columns as $column) {
                if (in_array($column->type, ['string', 'text', 'char'])) {
                  foreach ($searchFragments as $fragment) {
                    // Use table qualified column names to avoid ambiguous column errors
                    $orConditions[] = ['like', $this->ctype . '.' . $column->name, $fragment];
                  }
                }
              }
              
              $query->andWhere($orConditions);
            } elseif (is_array($value) && isset($value[0]) && $value[0] === 'strict') {
              // Handle dot notation for relations (e.g., company.systitle)
              if (strpos($attribute, '.') !== false) {
                $query->andWhere([$attribute => $value[1]]);
              } else {
                // Use table qualified column names for non-relation fields
                $query->andWhere([$this->ctype . '.' . $attribute => $value[1]]);
              }
            } else {
              // Handle dot notation for relations (e.g., company.systitle)
              if (strpos($attribute, '.') !== false) {
                $query->andWhere(['like', $attribute, $value]);
              } else {
                // Use table qualified column names for non-relation fields
                $query->andWhere(['like', $this->ctype . '.' . $attribute, $value]);
              }
            }
          }
        }
        
        // Apply sorting
        if (!empty($sort)) {
          if (isset($sort['defaultOrder'])) {
            $qualifiedSort = [];
            foreach ($sort['defaultOrder'] as $column => $direction) {
              // If column already has a table qualifier (contains a dot), use as is
              if (strpos($column, '.') !== false) {
                $qualifiedSort[$column] = $direction;
              } else {
                // Otherwise qualify with the content type table name
                $qualifiedSort[$this->ctype . '.' . $column] = $direction;
              }
            }
            $query->orderBy($qualifiedSort);
          } else {
            $qualifiedSort = [];
            foreach ($sort as $column => $direction) {
              // If column already has a table qualifier (contains a dot), use as is
              if (strpos($column, '.') !== false) {
                $qualifiedSort[$column] = $direction;
              } else {
                // Otherwise qualify with the content type table name
                $qualifiedSort[$this->ctype . '.' . $column] = $direction;
              }
            }
            $query->orderBy($qualifiedSort);
          }
        }
        
        $models = $query->all();
        $result = [];
        
        foreach ($models as $model) {
          $result[] = $model->attributes;
        }
        
        return $result;
      }
    }
    
    // Default behavior when relations are not needed
    return $this->storage->findAll($this->ctype, $filter, $sort);
  }

  /**
   * Delete a record
   *
   * @return bool Whether the deletion was successful
   */
  public function delete(): bool
  {
    if (empty($this->uuid)) {
      return false;
    }

    return $this->storage->delete($this->ctype, $this->uuid);
  }

  /**
   * Get element definitions
   *
   * @return false|\stdClass
   */
  public function getDefinitions(): \stdClass
  {
    $definition = CrelishDynamicModel::loadElementDefinition($this->ctype);
    if(empty($definition)) {
      var_dump($this->ctype);
    }
    return CrelishDynamicModel::loadElementDefinition($this->ctype);
  }

  /**
   * Get sorting configuration
   *
   * @return array Sorting configuration
   */
  public function getSorting(): array
  {
    $sorting = [];
    $attributes = [];

    if (!empty($this->definitions)) {
      foreach ($this->definitions->fields as $field) {
        if (property_exists($field, 'sortable') && $field->sortable == true) {
          if (!is_array($field->sortable)) {
            $attributes[] = (property_exists($field, 'gridField') && !empty($field->gridField)) ? $field->gridField : $field->key;
          } else {
            $attributes[$field->key] = [];

            if (property_exists($field, 'sortDefault')) {
              $attributes[$field->key]['default'] = constant($field->sortDefault);
            }
          }
        }
      }

      $sorting['attributes'] = $attributes;

      if (property_exists($this->definitions, "sortDefault")) {
        foreach ($this->definitions->sortDefault as $key => $value) {
          $sorting['defaultOrder'] = [$key => constant($value)];
        }
      }
    }

    return $sorting;
  }

  /**
   * Get filters
   *
   * @return CrelishDynamicJsonModel Filters
   */
  public function getFilters(): CrelishDynamicJsonModel
  {
    $model = new CrelishDynamicJsonModel(['systitle'], [
      'ctype' => $this->ctype,
    ]);

    if (!empty($_GET['CrelishDynamicJsonModel'])) {
      $model->attributes = $_GET['CrelishDynamicJsonModel'];
    }

    return $model;
  }

  /**
   * Get columns configuration
   *
   * @return array Columns configuration
   */
  public function getColumns(): array
  {
    $columns = [];

    foreach ($this->getDefinitions()->fields as $field) {
      if (!empty($field->visibleInGrid) && $field->visibleInGrid) {
        $label = (property_exists($field, 'label') && !empty($field->label)) ? $field->label : null;
        $format = (property_exists($field, 'format') && !empty($field->format)) ? $field->format : 'text';
        $columns[] = (property_exists($field, 'gridField') && !empty($field->gridField)) ? [
          'attribute' => $field->gridField,
          'label' => $label,
          'format' => $format,
        ] : [
          'attribute' => $field->key,
          'label' => $label,
          'format' => $format,
        ];
      }
    }

    return array_values($columns);
  }

  /**
   * Set relations for a query
   *
   * @param mixed $query Query to set relations for
   * @return void
   */
  public function setRelations(&$query): void
  {
    if (!$this->definitions) {
      return;
    }

    foreach ($this->definitions->fields as $field) {
      if (property_exists($field, 'type') && $field->type === 'relationSelect' && property_exists($field, 'config')) {
        $config = $field->config;

        if (property_exists($config, 'ctype')) {
          $query->joinWith($field->key);
        }
      }
    }
  }

  /**
   * Initialize relations for the current content type
   * This is used for automatically setting up relations in the storage layer
   *
   * @return void
   */
  public function initRelations(): void
  {
    if ($this->storage instanceof CrelishDbStorage) {
      $query = $this->getQuery();
      if ($query) {
        $this->setRelations($query);
      }
    }
  }

  /**
   * Get the query object for the current content type
   * This is useful for direct query manipulation and relation setup
   *
   * @return \yii\db\ActiveQuery|null The query object or null if not using database storage
   */
  public function getQuery(): ?\yii\db\ActiveQuery
  {
    if ($this->storage instanceof CrelishDbStorage) {
      $modelClass = $this->storage->getModelClass($this->ctype);
      return $modelClass::find();
    }
    
    return null;
  }
} 