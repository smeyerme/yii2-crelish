<?php

/**
 * Created by PhpStorm.
 * User: devop
 * Date: 03.02.16
 * Time: 20:57
 */

namespace giantbits\crelish\components;

use Underscore\Underscore;
use Underscore\Types\Arrays;
use yii\base\Component;
use yii\data\ArrayDataProvider;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\widgets\LinkPager;

class CrelishDataProvider extends Component
{
  private $ctype;
  private $allModels;
  private $definitions;
  private $key = 'uuid';
  private $uuid;
  private $pathAlias;
  private $pageSize = 30;

  public function __construct($ctype, $settings = [], $uuid = null, $forceFull = false)
  {
    $this->ctype = $ctype;
    $this->definitions = $this->getDefinitions();
    $this->pathAlias = ($this->ctype == 'elements') ? '@app/workspace' : '@app/workspace/data';

    switch ($this->definitions->storage) {
      case 'db':
        if (!empty($uuid)) {
          $this->uuid = $uuid;

          $dataModels = call_user_func_array('app\workspace\models\\' . ucfirst($this->ctype) . '::find', ['uuid' => $this->uuid])->all();
        } else {
          $dataModels = false; //\Yii::$app->cache->get('crc_' . $ctype);

          if ($dataModels === false || $forceFull) {
            // $data is not found in cache, calculate it from scratch
            $dataModels = call_user_func('app\workspace\models\\' . ucfirst($this->ctype) . '::find')->all();
            // store $data in cache so that it can be retrieved next time
            //\Yii::$app->cache->set('crc_' . $ctype, $dataModels);
          }
        }

        foreach ($dataModels as $dataModel) {
          $tmpModel = array_merge(['ctype' => $this->ctype], $dataModel->attributes);
          $this->allModels[$dataModel['uuid']] = $this->processSingle($tmpModel);
        }

        if (!empty($this->allModels)) {
          if (Arrays::has($settings, 'filter')) {
            if (!empty($settings['filter'])) {
              $this->filterModels($settings['filter']);
            }
          }

          if (Arrays::has($settings, 'sort')) {
            if (!empty($settings['sort'])) {
              $this->sortModels($settings['sort']);
            }
          }

          if (Arrays::has($settings, 'limit')) {
            if (!empty($settings['limit'])) {
              $this->pageSize = $settings['limit'];
            }
          }
        }

        break;
      default:
        if (!empty($uuid)) {
          $this->uuid = $uuid;
          if ($theFile = @file_get_contents($this->resolveDataSource($uuid))) {
            $this->allModels[] = $this->processSingle(\yii\helpers\Json::decode($theFile), true);
          } else {
            $this->allModels[] = [];
          }

        } else {
          $dataModels = \Yii::$app->cache->get('crc_' . $ctype);

          if ($dataModels === false || $forceFull) {
            // $data is not found in cache, calculate it from scratch
            $dataModels = $this->parseFolderContent($this->ctype);
            // store $data in cache so that it can be retrieved next time
            \Yii::$app->cache->set('crc_' . $ctype, $dataModels);
          }

          $this->allModels = $dataModels;

          // todo: process data.
          if (Arrays::has($settings, 'filter')) {
            if (!empty($settings['filter'])) {
              $this->filterModels($settings['filter']);
            }
          }

          if (Arrays::has($settings, 'sort')) {
            if (!empty($settings['sort'])) {
              $this->sortModels($settings['sort']);
            }
          }

          if (Arrays::has($settings, 'limit')) {
            if (!empty($settings['limit'])) {
              $this->pageSize = $settings['limit'];
            }
          }
        }
    }

    parent::__construct();
  }

  public function filterModels($filter)
  {
    if (is_array($filter)) {
      foreach ($filter as $key => $keyValue) {
        if (!empty($keyValue)) {
          if (is_array($keyValue)) {
            if ($keyValue[0] == 'noempty') {
              $this->allModels = array_values(Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                return $value[$key] != '';
              }));
            }

            if ($keyValue[0] == 'strict') {
              $this->allModels = array_values(Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                return $value[$key] == $keyValue[1];
              }));
            }

            if ($keyValue[0] == 'lt') {
              $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                return $value[$key] < $keyValue[1];
              });
            }

            if ($keyValue[0] == 'gt') {
              $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                return $value[$key] > $keyValue[1];
              });
            }

            if ($keyValue[0] == 'between') {
              $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                return (($value[$key] >= $keyValue[1] && $value[$key] <= $keyValue[2]) || ($value[$key] >= $keyValue[2] && $value[$key] <= $keyValue[1]));
              });
            }

          } elseif (is_bool($keyValue)) {
            $this->allModels = Arrays::filterBy($this->allModels, $key, $keyValue);
          } else {
            // todo: Optimize filter with param for "like" and "equal" filtering
            if ($key === 'slug') {
              $this->allModels = Arrays::filterBy($this->allModels, $key, $keyValue);
            } elseif ($key === 'state') {
              $this->allModels = Arrays::filterBy($this->allModels, $key, $keyValue);
            } elseif ($key === 'freesearch') {
              $this->allModels = Arrays::filter($this->allModels, function ($value) use ($keyValue) {
                $isMatch = true;
                //$itemString = strtolower(implode("#", Arrays::flatten($value)));
                $itemString = strtolower(serialize($value));
                $searchFragments = explode(" ", trim($keyValue));

                foreach ($searchFragments as $fragment) {
                  if (strpos($itemString, strtolower($fragment)) === false) {
                    $isMatch = false;
                  }
                }

                return $isMatch;
              });
            } else {
              $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                if (!empty($value[$key]) && is_array($value[$key])) {
                  $value[$key] = Arrays::implode($value[$key], "||");
                } elseif (strpos($key, "|") !== false) {
                  $key = str_replace("|", ".", $key);
                }

                $array = new Underscore($value);
                $finalFilters = explode(";", $keyValue);

                // Multifilter.
                $isMatch = false;
                foreach ($finalFilters as $subFilter) {
                  if ((stripos(html_entity_decode(serialize($array->get($key))), html_entity_decode(serialize($subFilter))) !== false)) {
                    $isMatch = true;
                  }
                }
                return $isMatch;
              });
            }
          }
        }
      }
    }
  }

  public function sortModels($sort)
  {

    $sortparams[] = $this->allModels;

    if (is_array($sort['by'])) {
      foreach ($sort['by'] as $item) {
        switch ($item) {
          case 'asc':
            $sortparams[] = SORT_ASC;
            break;
          case 'desc':
            $sortparams[] = SORT_DESC;
            break;
          default:
            $sortparams[] = $item;
        }
      }
    }

    $this->allModels = call_user_func_array([
      $this,
      'array_orderby',
    ], $sortparams);
  }

  public function all()
  {
    $provider = new ArrayDataProvider([
      'key' => 'id',
      'allModels' => $this->allModels,
      'pagination' => [
        'totalCount' => count($this->allModels),
        'pageSize' => $this->pageSize,
        'forcePageParam' => true,
      ],
    ]);

    $models = $provider->getModels();

    $pager = LinkPager::widget([
      'pagination' => $provider->getPagination(),
      'maxButtonCount' => 10,
    ]);
    $result = ['models' => array_values($models), 'pager' => $pager];

    return $result;
  }

  public function one()
  {

    if (!empty($this->allModels)) {
      return array_values($this->allModels)[0];
    }
    return null;
  }

  public function raw()
  {
    $getParams = $_GET;

    $provider = new ArrayDataProvider([
      'key' => $this->key,
      'allModels' => $this->allModels,
      'sort' => $this->getSorting(),
      'pagination' => [
        'totalCount' => count($this->allModels),
        'pageSize' => $this->pageSize,
        'forcePageParam' => true,
        'route' => (!empty(\Yii::$app->getRequest()
          ->getQueryParam('pathRequested'))) ? '/' . \Yii::$app->getRequest()
            ->getQueryParam('pathRequested') : null,
        'params' => array_merge([
          'page' => !empty($_GET['page']) ? $_GET['page'] : '',
          'category' => !empty($_GET['category']) ? $_GET['category'] : '',
          'branch' => !empty($_GET['branch']) ? $_GET['branch'] : '',
          'title' => !empty($_GET['title']) ? $_GET['title'] : '',
          'sort' => !empty($_GET['sort']) ? $_GET['sort'] : '',
          'per-page' => !empty($_GET['per-page']) ? $_GET['per-page'] : '',
          'kind' => !empty($_GET['kind']) ? $_GET['kind'] : '',
          'ctype' => !empty($_GET['ctype']) ? $_GET['ctype'] : '',
        ], $getParams),
      ],
    ]);

    return $provider;
  }

  public function rawAll()
  {
    return $this->allModels;
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
    $this->definitions = new \stdClass();
    $this->definitions->fields = [];
    $this->definitions->ctype = $this->ctype;
    $this->definitions->storage = 'json';

    if ($this->ctype !== 'elements') {

      $filePath = \Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . $this->ctype . '.json';

      if (file_exists($filePath)) {
        $elementStructure = Json::decode(file_get_contents($filePath), false);
      }

      // Set storage.
      $this->definitions->storage = (!empty($elementStructure->storage)) ? $elementStructure->storage : 'json';

      // Add core fields.
      $this->definitions->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
      $this->definitions->fields[] = Json::decode('{ "label": "ctype", "key": "ctype", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
      if (!empty($elementStructure) && property_exists($elementStructure, 'fields')) {
        $this->definitions->fields = array_merge($this->definitions->fields, $elementStructure->fields);
        $this->definitions->fields[] = Json::decode('{ "label": "State", "key": "state", "type": "textInput", "visibleInGrid": true, "sortable": true, "transform": "state", "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);

        if (!empty($elementStructure) && property_exists($elementStructure, 'sortDefault')) {
          $this->definitions->sortDefault = $elementStructure->sortDefault;
        }
      }
    }

    return $this->definitions;
  }

  public function getSorting()
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

  public function getFilters()
  {
    $model = new CrelishDynamicJsonModel(['systitle'], [
      'ctype' => $this->ctype,
    ]);

    if (!empty($_GET['CrelishDynamicJsonModel'])) {
      foreach ($_GET['CrelishDynamicJsonModel'] as $filter => $value) {
        if (!empty($value)) {
          $filters[$filter] = $value;
          $_GET[$filter] = $value;
        }
      }
    }

    if (!empty($_GET['CrelishDynamicJsonModel'])) {
      $model->attributes = $_GET['CrelishDynamicJsonModel'];
    }

    return $model;
  }

  public function getColumns()
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

  private function resolveDataSource($uuid = '')
  {
    $ds = DIRECTORY_SEPARATOR;
    $fileDataSource = '';

    $langDataFolder = (\Yii::$app->params['defaultLanguage'] != \Yii::$app->language) ? $ds . \Yii::$app->language : '';
    $fileDataSource = \Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $langDataFolder . $ds . $uuid . '.json';

    if (!file_exists(\Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $langDataFolder . $ds . $uuid . '.json')) {
      $fileDataSource = \Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $ds . $uuid . '.json';
    }

    return $fileDataSource;
  }

  private function parseFolderContent($folder)
  {
    $filesArr = [];
    $allModels = [];
    $fullFolder = \Yii::getAlias($this->pathAlias) . DIRECTORY_SEPARATOR . $folder;

    if (!file_exists($fullFolder)) {
      FileHelper::createDirectory($fullFolder);
    }

    $files = FileHelper::findFiles($fullFolder, ['recursive' => false]);

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
        $segments = explode(DIRECTORY_SEPARATOR, $file);
        CrelishBaseController::addError("Invalid JSON in " . array_pop($segments));
        continue;
      }

      $modelArr['id'] = $file;
      $modelArr['ctype'] = $this->ctype;

      // Handle specials like in frontend.
      $elementDefinition = $this->getDefinitions();

      foreach ($modelArr as $attr => $value) {
        CrelishBaseContentProcessor::processFieldData($elementDefinition, $attr, $value, $finalArr);
      }
      $allModels[] = $finalArr;
    }
    return $allModels;
  }

  private function processSingle($data)
  {
    $finalArr = [];
    $modelArr = (array)$data;

    // Handle specials like in frontend.
    $elementDefinition = $this->getDefinitions();

    foreach ($modelArr as $attr => $value) {
      CrelishBaseContentProcessor::processFieldData($this->ctype, $elementDefinition, $attr, $value, $finalArr);
    }

    return $finalArr;
  }

  private function array_orderby()
  {
    $args = func_get_args();
    $data = array_shift($args);

    foreach ($args as $n => $field) {
      if (is_string($field)) {
        $tmp = [];
        foreach ($data as $key => $row) {
          if (strpos($field, ".") !== false) {
            $tmp[$key] = Arrays::get($row, $field);
          } else {
            $tmp[$key] = $row[$field];
          }
        }
        $args[$n] = $tmp;
      }
    }

    $args[] = &$data;
    @call_user_func_array('array_multisort', $args);
    return array_pop($args);
  }
}
