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
   */
  public function __construct(string $ctype, array $settings = [], string $uuid = null)
  {
    $this->ctype = $ctype;
    $this->settings = $settings;
    $this->uuid = $uuid;
    $this->storage = CrelishStorageFactory::getStorage($ctype);
    $this->definitions = $this->getDefinitions();

    parent::__construct();
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
   * @return array Array of records and pagination
   */
  public function all(): array
  {
    $filter = $this->settings['filter'] ?? [];
    $sort = $this->settings['sort'] ?? [];
    $pageSize = $this->settings['pageSize'] ?? 30;

    // Ensure sort is in the correct format
    if (!empty($sort) && !isset($sort['defaultOrder'])) {
      $sort = ['defaultOrder' => $sort];
    }

    $dataProvider = $this->storage->getDataProvider($this->ctype, $filter, $sort, $pageSize);

    return [
      'models' => $dataProvider->getModels(),
      'pagination' => $dataProvider->getPagination(),
    ];
  }

  /**
   * Get a data provider
   *
   * @return DataProviderInterface Data provider
   */
  public function getProvider(): DataProviderInterface
  {
    $filter = $this->settings['filter'] ?? [];
    $sort = $this->settings['sort'] ?? [];
    $pageSize = $this->settings['pageSize'] ?? 30;

    // Ensure sort is in the correct format
    if (!empty($sort) && !isset($sort['defaultOrder'])) {
      $sort = ['defaultOrder' => $sort];
    }

    return $this->storage->getDataProvider($this->ctype, $filter, $sort, $pageSize);
  }

  /**
   * Get all records as raw data
   *
   * @return array Array of records
   */
  public function rawAll(): array
  {
    $filter = $this->settings['filter'] ?? [];
    $sort = $this->settings['sort'] ?? [];

    // Ensure sort is in the correct format
    if (!empty($sort) && !isset($sort['defaultOrder'])) {
      $sort = ['defaultOrder' => $sort];
    }

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
   * @return \stdClass Element definitions
   */
  public function getDefinitions(): \stdClass
  {
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
} 