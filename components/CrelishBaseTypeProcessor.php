<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 03.01.16
 * Time: 13:52
 */

namespace crelish\components;


use yii\base\Component;

class CrelishBaseTypeProcessor extends Component {

  public $requestUrl;
  public $fileHandler;
  public $configHandler;
  public $requestFile;
  public $meta = [];
  public $rawContent;
  public $content;

  public function __construct($requestUrl, $requestFile, &$meta, $rawContent, $fileHandler, $configHandler, $config = [])
  {

    $this->requestUrl= $requestUrl;
    $this->requestFile = $requestFile;
    $this->configHandler = $configHandler;
    $this->fileHandler = $fileHandler;
    $this->meta = &$meta;
    $this->rawContent = $rawContent;

    parent::__construct($config);
  }

  public function getProcessorOutput() {

    return $this->content;
  }
}
