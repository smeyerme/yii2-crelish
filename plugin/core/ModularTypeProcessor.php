<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 30.12.15
 * Time: 13:33
 */

namespace crelish\plugin\core;

use crelish\components\CrelishBaseTypeProcessor;
use crelish\components\CrelishFileDataProvider;
use yii\base\View;


class ModularTypeProcessor extends CrelishBaseTypeProcessor
{

  private $collection;

  public function init()
  {

    $this->buildCollection();
    parent::init();
  }

  protected function buildCollection()
  {
    $collection = [];

    if (!$this->meta['data']['items']) {
      return;
    }

    //Get path of file.
    $path = substr($this->requestFile, 0, strrpos($this->requestFile, DIRECTORY_SEPARATOR));
    $this->collection = $this->fileHandler->parseFolderContent($path);

    //Sort collection.
    if (!empty($this->meta['data']['order'])) {
      $orderedCollection = [];

      //Check if custom sorting is defined.
      if (!empty($this->meta['data']['order']['custom'])) {
        foreach ($this->meta['data']['order']['custom'] as $item) {
          foreach ($this->collection as $unsortedItem) {
            if (strpos($unsortedItem, $item) !== false) {
              $orderedCollection[] = $unsortedItem;
            }
          }
        }
        $this->collection = $orderedCollection;
      }
    }
  }

  protected function processModularFile($file)
  {
    $rawContent = $this->fileHandler->loadFileContent($file);

    //$headers = $this->getMetaHeaders();
    $meta = $this->fileHandler->parseFileMeta($rawContent, [], $file);

    // Add collection(s) data to the mix.
    if (!empty($meta['collections']) && count($meta['collections']) > 0) {
      $collections = $this->buildCollections($meta);
      $meta = array_merge($collections, $meta);
    }

    $processedContent = $this->fileHandler->prepareFileContent($rawContent, $meta, TRUE);
    $processedContent = $this->fileHandler->parseFileContent($processedContent);
    $template = $this->fileHandler->selectTemplate($this->requestUrl, $meta, $file);

    //Render the thing.
    return \Yii::$app->controller->renderPartial($template, [
      'content' => $processedContent,
      'page' => $meta
    ]);
  }

  protected function buildCollections($meta)
  {
    $collectionsArray = [];

    foreach ($meta['collections'] as $key => $settings) {
      $data =  new CrelishFileDataProvider($key, $settings);
      $collectionsArray[$key] = $data->all();
    }

    return $collectionsArray;
  }

  public function getProcessorOutput()
  {
    foreach ($this->collection as $file) {

      if (!empty(\Yii::$app->language)) {
        if (strpos($file, \Yii::$app->language . '.md') !== false) {
          $this->content .= $this->processModularFile($file);
        }
      } else if (substr_count(substr($file, -6), '.') == 1) {
        $this->content .= $this->processModularFile($file);
      }
    }

    return $this->content;
  }

}
