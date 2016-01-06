<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 30.12.15
 * Time: 13:33
 */

namespace giantbits\crelish\plugin\core;

use giantbits\crelish\components\CrelishBaseTypeProcessor;
use yii\base\View;


class ListingTypeProcessor extends CrelishBaseTypeProcessor {

  private $collection;

  public function init() {

    $this->buildCollection();
    parent::init();
  }

  protected function buildCollection() {
    $collection = [];

    if (!$this->meta['content']['items']) {
      return;
    }

    //Get path of file.
    $path = substr($this->requestFile, 0, strrpos($this->requestFile, DIRECTORY_SEPARATOR));

    $this->collection = $this->fileHandler->parseFolderContent($path);

    //Sort collection.
    if(!empty($this->meta['content']['order'])) {
      $orderedCollection = [];

      //Check if custom sorting is defined.
      if(!empty($this->meta['content']['order']['custom'])){
        foreach($this->meta['content']['order']['custom'] as $item){
          foreach($this->collection as $unsortedItem) {
            if(strpos($unsortedItem, $item) !== false) {
              $orderedCollection[] = $unsortedItem;
            }
          }
        }

        $this->collection = $orderedCollection;
      }
    }

    //Exclude toplevel.
    if(($key = array_search($this->requestFile, $this->collection)) !== false) {
      unset($this->collection[$key]);
    }
  }

  protected function getListItem($type = 'meta', $file) {
    $rawContent = $this->fileHandler->loadFileContent($file);

    //$headers = $this->getMetaHeaders();
    $meta = $this->fileHandler->parseFileMeta($rawContent);

    if($type=='meta'){
      return $meta;
    }

    $processedContent = $this->fileHandler->prepareFileContent($rawContent, $meta, TRUE);
    $processedContent = $this->fileHandler->parseFileContent($processedContent);

    //Render the thing.
    return $processedContent;
  }

  public function getProcessorOutput() {

    foreach($this->collection as $file){
      //$this->content .= $this->processModularFile($file);
      $this->meta['collection'][] = ['data'=>$this->getListItem('meta', $file), 'content'=>$this->getListItem('content', $file)];
    }

    return $this->content;
  }

}
