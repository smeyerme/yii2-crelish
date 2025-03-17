<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use yii\filters\AccessControl;
use yii\helpers\FileHelper;


class ElementsController extends CrelishBaseController
{
  public $layout = 'crelish.twig';

  public function behaviors()
  {
    return [
      'access' => [
        'class' => AccessControl::class,
        'rules' => [
          [
            'allow' => true,
            'actions' => ['login'],
            'roles' => ['?'],
          ],
          [
            'allow' => true,
            'actions' => [],
            'roles' => ['@'],
          ],
        ],
      ],
    ];
  }


  public function init()
  {
    parent::init();
  }

  public function actionIndex() {
      $files = FileHelper::findFiles(\Yii::getAlias('@app/workspace/elements'));
      $data = [];
      foreach ($files as $f) {
          $tmp = json_decode(file_get_contents($f));
          $data[$tmp->label] = basename($f);
      }
      ksort($data);

      return $this->render('index.twig',['data'=>$data]);
  }

  public function actionEdit() {
      if (!preg_match('/^[a-zA-Z0-9\-_]+\.json$/',$_GET['element'])) {
          echo "nice try...";
          die();
      }
      $elementFileName = $_GET['element'];
      $data = json_decode(file_get_contents(\Yii::getAlias('@app/workspace/elements/'.$elementFileName)));

      foreach ($data->fields as $key => $val) {
          $data->fields[$key]->fielddata = json_encode($val);
      }

      return $this->render('edit.twig',['element'=>$data]);
  }

	/**
	 * Override the setupHeaderBar method to use elements-specific components
	 */
	protected function setupHeaderBar()
	{
		// Default left components for all actions
		$this->view->params['headerBarLeft'] = ['toggle-sidebar'];
		
		// Default right components (empty by default)
		$this->view->params['headerBarRight'] = [];
		
		// Set specific components based on action
		$action = $this->action ? $this->action->id : null;
		
		switch ($action) {
			case 'index':
				// For elements index, just show the title
				$this->view->params['headerBarLeft'][] = 'elements-title';
				break;
				
			case 'edit':
				// For edit actions, add back button and save buttons
				$this->view->params['headerBarLeft'][] = 'back-button';
				$this->view->params['headerBarRight'] = ['save'];
				break;
				
			default:
				// For other actions, just keep the defaults
				break;
		}
	}

}
