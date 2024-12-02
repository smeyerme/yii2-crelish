<?php

namespace giantbits\crelish\components;

use Yii;

class CrelishSidebarManager
{
  private $_items = [];

  public function init(): void
  {
    $this->loadDefaultNavigation();
    $this->loadCustomNavigation();
  }

  protected function loadDefaultNavigation(): void
  {
    $file = Yii::getAlias('@giantbits/crelish/config/sidebar.json');

    if (file_exists($file)) {
      $this->_items = json_decode(file_get_contents($file), true)['items'];
    }
  }

  protected function loadCustomNavigation(): void
  {
    // Look for custom navigation in workspace
    $customFile = Yii::getAlias('@app/workspace/crelish/sidebar.json');

    if (file_exists($customFile)) {
      $customItems = json_decode(file_get_contents($customFile), true)['items'];
      // Merge or override default items
      $this->_items = $this->mergeNavigation($this->_items, $customItems);
    }
  }

  protected function mergeNavigation($default, $custom)
  {
    $result = $default;
    foreach ($custom as $item) {
      $index = $this->findItemById($result, $item['id']);
      if ($index !== false) {
        // Update existing item
        $result[$index] = array_merge($result[$index], $item);
      } else {
        // Add new item
        $result[] = $item;
      }
    }
    // Sort by order
    usort($result, function($a, $b) {
      return ($a['order'] ?? 999) - ($b['order'] ?? 999);
    });
    return $result;
  }

  protected function findItemById($items, $id): bool|int|string
  {
    foreach ($items as $index => $item) {
      if ($item['id'] === $id) {
        return $index;
      }
    }
    return false;
  }

  public function getItems(): array
  {
    return $this->_items;
  }

  public function filterItems($items)
  {
    // Add any conditional filtering here
    // For example, checking permissions or conditions like your portal check
    return array_filter($items, function($item) {
      if (isset($item['condition'])) {
        return $this->evaluateCondition($item['condition']);
      }
      return true;
    });
  }

  protected function evaluateCondition($condition): bool
  {
    // Example condition evaluation
    if ($condition === 'portal') {
      return str_contains(Yii::$app->basePath, 'portal');
    }
    return true;
  }

}