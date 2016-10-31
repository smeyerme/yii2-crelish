<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 03.02.16
 * Time: 20:57
 */

namespace giantbits\crelish\components;

use Underscore\Parse;
use Underscore\Underscore;
use Underscore\Types\Arrays;
use yii\base\Component;
use yii\data\ArrayDataProvider;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\helpers\Html;
use yii\helpers\VarDumper;
use yii\widgets\LinkPager;
use yii\grid\ActionColumn;
use yii\helpers\Url;

class CrelishJsonDataProvider extends Component
{

  private $ctype;
  private $allModels;
  private $rawModels;
  private $definitions;
  private $key = 'uuid';
  private $uuid;
  private $pathAlias;

  /**
   * Resolve language specific data source folder / file.
   * If no folder for the current language is found take the default language
   * source.
   * @param  string $uuid [description]
   * @return [type]       [description]
   */
  private function resolveDataSource($uuid = '')
  {
    $ds = DIRECTORY_SEPARATOR;
    $fileDataSource = '';

    $langDataFolder = (\Yii::$app->params['defaultLanguage'] != \Yii::$app->language) ? $ds . \Yii::$app->language : '';
    $fileDataSource = \Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $langDataFolder . $ds . $uuid . '.json';

    if(!file_exists(\Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $langDataFolder . $ds . $uuid . '.json')) {
      $fileDataSource = \Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $ds . $uuid . '.json';
    }

    return $fileDataSource;
  }

  public function __construct($ctype, $settings = [], $uuid = null)
  {
    $this->ctype = $ctype;
    $this->pathAlias = ($this->ctype == 'elements') ? '@app/workspace' : '@app/workspace/data';

    if (!empty($uuid)) {
      $this->uuid = $uuid;
      if ($theFile = @file_get_contents($this->resolveDataSource($uuid)))
        $this->allModels[] = $this->processSingle(\yii\helpers\Json::decode($theFile), true);
      else
        $this->allModels[] = [];
    } else {

      $dataModels = \Yii::$app->cache->get('crc_' . $ctype);

      if ($dataModels === false) {
        // $data is not found in cache, calculate it from scratch
        $dataModels = $this->parseFolderContent($this->ctype);
        // store $data in cache so that it can be retrieved next time
        \Yii::$app->cache->set('crc_' . $ctype, $dataModels);
      }
      $this->allModels = $dataModels;
    }

    if (Arrays::has($settings, 'filter')) {
      if(!empty($settings['filter'])) {
        $this->filterModels($settings['filter']);
      }
    }

    if (Arrays::has($settings, 'sort')) {
      if(!empty($settings['sort'])) {
        $this->sortModels($settings['sort']);
      }
    }

    parent::__construct();
  }

  private function processSingle($data)
  {
    $finalArr = [];
    $modelArr = (array)$data;

    // todo: Handle special fields... uncertain about this.
    foreach ($modelArr as $attr => $value) {

      if (strpos($attr, '__cr_include') !== false) {
        // Include data.
        $include = new CrelishJsonDataProvider($value['ctype'], [], $value['uuid']);
        $finalArr[str_replace('__cr_include', '', $attr)] = $include->one();
      } /* else if (strpos($attr, 'asset') !== false) {
        // Include asset data.
        $include = new CrelishJsonDataProvider('asset', [], $value['uuid']);
        $finalArr[$attr] = $include->one();
      }*/ else {
        $finalArr[$attr] = $value;
      }
    }

    return $finalArr;
  }

  private function filterModels($filter)
  {
    if (is_array($filter)) {

      foreach ($filter as $key => $keyValue) {

        if (is_array($keyValue)) {
          if ($keyValue[0] == 'strict') {
            $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
              return $value[$key] == $keyValue[1];
            });
          }
        } elseif (is_bool($keyValue)) {
          $this->allModels = Arrays::filterBy($this->allModels, $key, $keyValue);
        } else {
          // todo: Optimize filter with param for "like" and "equal" filtering
          if ($key === 'slug') {
            $this->allModels = Arrays::filterBy($this->allModels, $key, $keyValue);
          } else {
            $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
              if (!empty($value[$key]) && is_array($value[$key])) {

                $value[$key] = Arrays::implode($value[$key], "||");
              } elseif (strpos($key, "|") !== false) {
                $key = str_replace("|", ".", $key);
              }

              $array = new Underscore($value);

              return (stripos($array->get($key), html_entity_decode($keyValue)) !== false);
            });
          }
        }
      }

    }
  }

  /**
   * [sortModels description]
   * @param  [type] $sort [description]
   * @return [type]       [description]
   */
  private function sortModels($sort)
  {
    $this->allModels = Arrays::sort($this->allModels, function ($model) use ($sort) {
      return $model[$sort['by']];
    }, $sort['dir']);
  }

  /**
   * [parseFolderContent description]
   * @param  [type] $folder [description]
   * @return [type]         [description]
   */
  public function parseFolderContent($folder)
  {
    $filesArr = [];
    $allModels = [];

    $fullFolder = \Yii::getAlias($this->pathAlias) . DIRECTORY_SEPARATOR . $folder;

    if (!file_exists($fullFolder)) {
      mkdir($fullFolder);
    }

    $files = FileHelper::findFiles($fullFolder, ['recursive'=>false]);

    if (isset($files[0])) {
      foreach ($files as $file) {
        $filesArr[] = $file;
      }
    }

    foreach ($filesArr as $file) {
      $finalArr = [];
      $content = file_get_contents($file);
      $modelArr = json_decode($content, true);
      if (is_null($modelArr)) {
        $segments = explode(DIRECTORY_SEPARATOR,$file);
        CrelishBaseController::addError("Invalid JSON in " . array_pop($segments));
        continue;
    }
      $modelArr['id'] = $file;
      $modelArr['ctype'] = $this->ctype;

      // todo: Handle special fields... uncertain about this.
      foreach ($modelArr as $attr => $value) {

        if (strpos($attr, '__cr_include') !== false) {
          // Include data.
          $include = new CrelishJsonDataProvider($value['ctype'], [], $value['uuid']);
          $finalArr[str_replace('__cr_include', '', $attr)] = $include->one();
        } /* else if (strpos($attr, 'asset') !== false) {
          // Include data.
          $include = new CrelishJsonDataProvider($value['type'], [], $value['uuid']);
          $finalArr[str_replace('asset', '', $attr)] = $include->one();
        } */ else {
          $finalArr[$attr] = $value;
        }
      }

      $allModels[] = $finalArr;
    }

    return $allModels;
  }

  public function all()
  {
    $provider = new ArrayDataProvider([
      'key' => 'id',
      'allModels' => $this->allModels,
      'pagination' => [
        'totalCount' => count($this->allModels),
        'pageSize' => 50,
        'forcePageParam' => true
      ],
    ]);

    $models = $provider->getModels();

    $pager = LinkPager::widget([
      'pagination' => $provider->getPagination(),
      'maxButtonCount' => 10
    ]);

    $result = ['models' => array_values($models), 'pager' => $pager];

    return $result;
  }

  public function rawAll()
  {
    return $this->allModels;
  }

  public function one()
  {
    if(!empty($this->allModels[0])) {
        return $this->allModels[0];
    }

    return null;

  }

  public function raw()
  {
    $provider = new ArrayDataProvider([
      'key' => $this->key,
      'allModels' => $this->allModels,
      'sort' => [
        'attributes' => [$this->key, 'systitle'],
      ],
      'pagination' => [
        'totalCount' => count($this->allModels),
        'pageSize' => 20,
        'forcePageParam' => true,
        'route' => (!empty(\Yii::$app->getRequest()->getQueryParam('pathRequested'))) ? '/' . \Yii::$app->getRequest()->getQueryParam('pathRequested') : null,
        'params' => [
          'page' => !empty($_GET['page']) ? $_GET['page'] : '',
          'category' => !empty($_GET['category']) ? $_GET['category'] : '',
          'branch' => !empty($_GET['branch']) ? $_GET['branch'] : '',
          'title' => !empty($_GET['title']) ? $_GET['title'] : '',
          'sort' => !empty($_GET['sort']) ? $_GET['sort'] : '',
          'per-page' => !empty($_GET['per-page']) ? $_GET['per-page'] : '',
          'kind' => !empty($_GET['kind']) ? $_GET['kind'] : '',
          'ctype' => !empty($_GET['ctype']) ? $_GET['ctype'] : '',
        ]
      ],
    ]);

    return $provider;
  }

  public function delete()
  {
    $ds = DIRECTORY_SEPARATOR;
    if (@unlink(\Yii::getAlias($this->pathAlias) . $ds . $this->type . $ds . $this->uuid . '.json')) {
      \Yii::$app->cache->flush();
    }

    return;
  }

  public function getDefinitions()
  {
    $filePath = \Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . $this->ctype . '.json';
    $this->definitions = new \stdClass();

    // Add core fields.
    $this->definitions->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
    $this->definitions->fields = array_merge($this->definitions->fields, Json::decode(file_get_contents($filePath), false)->fields);
    $this->definitions->fields[] = Json::decode('{ "label": "State", "key": "state", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
    //$this->definitions->fields[] = Json::decode('{ "label": "Created", "key": "created", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
    //$this->definitions->fields[] = Json::decode('{ "label": "Updated", "key": "updated", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
    //$this->definitions->fields[] = Json::decode('{ "label": "Publish from", "key": "from", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
    //$this->definitions->fields[] = Json::decode('{ "label": "Publish to", "key": "to", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
    //$this->definitions->fields[] = Json::decode('{ "label": "Element", "key": "elementType", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]]}', false);

    return $this->definitions;
  }

  public function getColumns()
  {
    $columns = [];

    foreach ($this->getDefinitions()->fields as $field) {
      if (!empty($field->visibleInGrid) && $field->visibleInGrid) {
        $columns[] = $field->key;
      }
    }

    $columns[] = [
      'class' => ActionColumn::className(),
      'template' => '{update}',
      'buttons' => [
        'update' => function ($url, $model) {
          return Html::a('<span class="glyphicon glyphicon-edit"></span>', $url, [
            'title' => \Yii::t('app', 'Edit'),
            'data-pjax' => '0'
          ]);
        }
      ],
      'urlCreator' => function ($action, $model, $key, $index) {
        if ($action === 'update') {
          $url = Url::toRoute(['content/update', 'ctype' => $this->ctype, 'uuid' => $model['uuid']]);
          return $url;
        }
      }
    ];

    return array_values($columns);
  }
}
