<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 30.11.15
 * Time: 18:25
 */

namespace giantbits\crelish\components;

use yii\base\Component;
use yii\helpers\Url;

class CrelishConfig extends Component {

  public $config;
  protected $configDir;
  protected $rootDir;
  protected $pluginsDir;
  protected $themesDir;

  public function getRootDir() {
    return $this->rootDir;
  }

  public function getPluginsDir() {
    return $this->pluginsDir;
  }

  public function getThemesDir() {
    return $this->themesDir;
  }

  public function getConfigDir() {
    return $this->configDir;
  }

  public function loadConfig() {
    $config = NULL;

    $defaultConfig = array(
      'site_title' => 'Coop Bau & Hobby -',
      'base_url' => '',
      'rewrite_url' => NULL,
      'theme' => 'index',
      'date_format' => '%D %T',
      'pages_order_by' => 'alpha',
      'pages_order' => 'asc',
      'content_dir' => 'workspace/pages/',
      'content_ext' => '.md',
      'timezone' => ''
    );

    $configFile = $this->getConfigDir() . 'config.yaml';
    if (file_exists($configFile)) {
      require $configFile;
    }

    $this->config = is_array($this->config) ? $this->config : array();
    $this->config += is_array($config) ? $config + $defaultConfig : $defaultConfig;

    if (empty($this->config['base_url'])) {
      $this->config['base_url'] = Url::base();
    }
    else {
      $this->config['base_url'] = rtrim($this->config['base_url'], '/') . '/';
    }

    if (empty($this->config['content_dir'])) {
      // try to guess the content directory
      if (is_dir($this->getRootDir() . 'content')) {
        $this->config['content_dir'] = $this->getRootDir() . 'content/';
      }
      else {
        $this->config['content_dir'] = $this->getRootDir() . 'workspace/pages/';
      }
    }
    else {
      $this->config['content_dir'] = $this->getAbsolutePath($this->config['content_dir']);
    }

    if (empty($this->config['timezone'])) {
      // explicitly set a default timezone to prevent a E_NOTICE
      // when no timezone is set; the `date_default_timezone_get()`
      // function always returns a timezone, at least UTC
      $this->config['timezone'] = date_default_timezone_get();
    }
    date_default_timezone_set($this->config['timezone']);
  }

  public function setConfig(array $config) {
    if ($this->locked) {
      throw new RuntimeException("You cannot modify Pico's config after processing has started");
    }

    $this->config = $config;
  }

  public function getConfig($configName = NULL) {
    if ($configName !== NULL) {
      return isset($this->config[$configName]) ? $this->config[$configName] : NULL;
    }
    else {
      return $this->config;
    }
  }

  protected function getAbsolutePath($path) {
    if (substr($path, 0, 1) !== '/') {
      $path = $this->getRootDir() . $path;
    }
    return rtrim($path, '/') . '/';
  }
}
