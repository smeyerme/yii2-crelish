<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 30.11.15
 * Time: 18:21
 */

namespace app\components;

use yii\base\Component;
use yii\helpers\FileHelper;

class CrelishFileHandler extends Component {

  protected $config;
  protected $pageFiles;
  protected $content;

  public function getBaseUrl($config = NULL) {
    if ($config != NULL) {
      $baseUrl = $config->getConfig('base_url');
      if (!empty($baseUrl)) {
        return $baseUrl;
      }

      if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off')
        || ($_SERVER['SERVER_PORT'] == 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
      ) {
        $protocol = 'https';
      }
      else {
        $protocol = 'http';
      }

      $config->config['base_url'] =
        $protocol . "://" . $_SERVER['HTTP_HOST']
        . dirname($_SERVER['SCRIPT_NAME']) . '/';

      return $config->getConfig('base_url');
    }
    else {
      return NULL;
    }

  }

  public function setConfig($config) {
    $this->config = $config;
  }

  protected function getPageFiles() {

    $pageFiles = FileHelper::findFiles(\Yii::$app->params['pageFilePath']);

    return $pageFiles;
  }

  protected function getConfigFile() {
    $pageFiles = FileHelper::findFiles(\Yii::$app->params['pageFilePath']);
    return $pageFiles;
  }

  public function parseFileMeta($rawContent, array $headers = []) {
    $meta = array();
    $pattern = "/^(\/(\*)|---)[[:blank:]]*(?:\r)?\n"
      . "(.*?)(?:\r)?\n(?(2)\*\/|---)[[:blank:]]*(?:(?:\r)?\n|$)/s";
    if (preg_match($pattern, $rawContent, $rawMetaMatches)) {
      $yamlParser = new \Symfony\Component\Yaml\Parser();
      $meta = $yamlParser->parse($rawMetaMatches[3]);
      $meta = array_change_key_case($meta, CASE_LOWER);

      foreach ($headers as $fieldId => $fieldName) {
        $fieldName = strtolower($fieldName);
        if (isset($meta[$fieldName])) {
          // rename field (e.g. remove whitespaces)
          if ($fieldId != $fieldName) {
            $meta[$fieldId] = $meta[$fieldName];
            unset($meta[$fieldName]);
          }
        }
        else {
          // guarantee array key existance
          $meta[$fieldId] = '';
        }
      }

      if (!empty($meta['date'])) {
        $meta['time'] = strtotime($meta['date']);
        $meta['date_formatted'] = utf8_encode(strftime($this->config->getConfig('date_format'), $meta['time']));
      }
      else {
        $meta['time'] = $meta['date_formatted'] = '';
      }
    }
    else {
      // guarantee array key existance
      foreach ($headers as $id => $field) {
        $meta[$id] = '';
      }

      $meta['time'] = $meta['date_formatted'] = '';
    }

    return $meta;
  }

  public function getFileMeta() {
    return $this->meta;
  }

  public function prepareFileContent($rawContent, array $meta, $isModular = false) {
    // remove meta header
    $metaHeaderPattern = "/^(\/(\*)|---)[[:blank:]]*(?:\r)?\n"
      . "(.*?)(?:\r)?\n(?(2)\*\/|---)[[:blank:]]*(?:(?:\r)?\n|$)/s";
    $content = preg_replace($metaHeaderPattern, '', $rawContent, 1);

    // replace %site_title%
    $content = str_replace('%site_title%', $this->config->getConfig('site_title'), $content);

    // replace %base_url%
    if (1 == 1) {
      // always use `%base_url%?sub/page` syntax for internal links
      // we'll replace the links accordingly, depending on enabled rewriting
      $content = str_replace('%base_url%?', $this->getBaseUrl(), $content);
    }
    else {
      // actually not necessary, but makes the URL look a little nicer
      $content = str_replace('%base_url%?', $this->getBaseUrl() . '?', $content);
    }
    $content = str_replace('%base_url%', rtrim($this->getBaseUrl(), '/'), $content);

    // replace %theme_url%
    $themeUrl = $this->getBaseUrl() . basename($this->config->getThemesDir()) . '/' . $this->config->getConfig('theme');
    $content = str_replace('%theme_url%', $themeUrl, $content);

    // replace %meta.*%
    if (!empty($meta) && !$isModular) {
      $metaKeys = $metaValues = array();
      foreach ($meta as $metaKey => $metaValue) {
        if (is_scalar($metaValue) || ($metaValue === NULL)) {
          \Yii::$app->view->registerMetaTag([
            'name' => $metaKey,
            'content' => strval($metaValue)
          ]);
        }
      }
      $content = str_replace($metaKeys, $metaValues, $content);
    }

    return $content;
  }

  public function parseFileContent($content) {
    return \yii\helpers\Markdown::process($content, 'extra');
  }

  public function getFileContent() {
    return $this->out;
  }

  public function getRequestFile() {
    return $this->requestFile;
  }

  public function parseFolderContent() {

  }
}
