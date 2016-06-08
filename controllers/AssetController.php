<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:19
 */

namespace giantbits\crelish\controllers;

use yii\base\Controller;
use yii\web\UploadedFile;
use yii\helpers\Json;
use yii\helpers\Url;
use giantbits\crelish\components\CrelishJsonDataProvider;
use giantbits\crelish\components\CrelishDynamicModel;

class AssetController extends Controller
{

  public $layout = 'crelish.twig';

  public function init()
  {
    parent::init(); // TODO: Change the autogenerated stub
  }

  public function actionIndex()
  {
    $modelProvider = new CrelishJsonDataProvider('asset', [
      'sort' => ['by' => 'systitle', 'dir' => 'desc']
    ], null);

    $alerts = '';
    foreach (\Yii::$app->session->getAllFlashes() as $key => $message) {
      $alerts .= '<div class="c-alerts__alert c-alerts__alert--' . $key . '">' . $message . '</div>';
    }

    return $this->render('index.twig', [
      'dataProvider' => $modelProvider->raw(),
      'alerts' => $alerts
    ]);
  }

  public function actionView()
  {
    $id = !empty( \Yii::$app->getRequest()->getQueryParam('uuid') ) ?  \Yii::$app->getRequest()->getQueryParam('uuid') : null;
    $modelProvider = new CrelishJsonDataProvider('asset', [], $id);

    return $this->render('view.twig', [
      'model' => $modelProvider->one()
    ]);
  }

  public function actionUpload()
  {

    $file = UploadedFile::getInstanceByName('file');

    if ($file) {
      $destName = time() . '_' . $file->name;
      $file->saveAs(\Yii::getAlias('@webroot') . DIRECTORY_SEPARATOR . '_lib' . DIRECTORY_SEPARATOR . $destName);

      $filePath = \Yii::getAlias('@app/workspace/data/elements') . DIRECTORY_SEPARATOR . 'asset' . '.json';
      $elementDefinition = Json::decode(file_get_contents($filePath), false);

      // Add core fields.
      $elementDefinition->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
      $elementDefinition->fields[] = Json::decode('{ "label": "Path", "key": "path", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
      $elementDefinition->fields[] = Json::decode('{ "label": "Slug", "key": "slug", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
      $elementDefinition->fields[] = Json::decode('{ "label": "State", "key": "state", "type": "dropDownList", "visibleInGrid": true, "rules": [["required"], ["string", {"max": 128}]], "options": {"prompt":"Please set state"}, "items": {"0":"Offline", "1":"Draft", "2":"Online", "3":"Archived"}}', false);

      $fields = [];

      foreach ($elementDefinition->fields as $field) {
        array_push($fields, $field->key);
      }

      $model = new CrelishDynamicModel($fields);
      $model->identifier = 'asset';
      $model->systitle = $destName;
      $model->title = $destName;
      $model->src = \Yii::getAlias('@web') . '/' . '_lib' . '/' . $destName;
      $model->type = $file->type;
      $model->size = $file->size;
      $model->save();
    }


    return false;
  }

  public function actionDelete(  )
  {
    $id = !empty( \Yii::$app->getRequest()->getQueryParam('uuid') ) ?  \Yii::$app->getRequest()->getQueryParam('uuid') : null;
    $modelProvider = new CrelishJsonDataProvider('asset', [], $id);
    $model = $modelProvider->one();
    if(@unlink(\Yii::getAlias('@webroot') . $model['src']) || !file_exists(\Yii::getAlias('@webroot') . $model['src'])){
      $modelProvider->delete();
      \Yii::$app->session->setFlash('success', 'Asset deleted successfully...');
      header("Location: " . Url::to(['asset/index']));
      exit(0);
    };

    \Yii::$app->session->setFlash('danger', 'Asset could not be deleted...');
    header("Location: " . Url::to(['asset/index', ['uuid'=>$model['uuid']]]));
    exit(0);
  }
}
