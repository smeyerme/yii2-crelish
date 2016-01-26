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

        $processedContent = $this->fileHandler->prepareFileContent($rawContent, $meta, TRUE);
        $processedContent = $this->fileHandler->parseFileContent($processedContent);
        $template = $this->fileHandler->selectTemplate($this->requestUrl, $meta, $file);

        //Render the thing.
        return \Yii::$app->controller->renderPartial($template, [
            'content' => $processedContent,
            'data' => $meta
        ]);
    }

    public function getProcessorOutput()
    {

        foreach ($this->collection as $file) {
            $this->content .= $this->processModularFile($file);
        }

        return $this->content;
    }

}
