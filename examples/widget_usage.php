<?php
/**
 * This file demonstrates how to use the ElementNav widget with the new storage system.
 * 
 * The ElementNav widget scans the @app/workspace/elements directory for element definitions
 * and displays a navigation menu for all elements that have the attribute "selectable": true.
 */

use giantbits\crelish\widgets\ElementNav;
use yii\helpers\Html;

// Basic usage
echo ElementNav::widget();

// Advanced usage with configuration
echo ElementNav::widget([
    'action' => 'index',
    'selector' => 'content_type',
    'ctype' => 'article',
    'target' => '#customContentSelector'
]);

// Using the widget in a layout
?>

<div class="row">
    <div class="col-md-3">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Content Types</h3>
            </div>
            <div class="panel-body">
                <ul class="nav nav-pills nav-stacked">
                    <?= ElementNav::widget() ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-md-9">
        <div id="contentSelector">
            <!-- Content will be loaded here via PJAX -->
        </div>
    </div>
</div>

<?php
// Example of how to handle the PJAX request in your controller:

/**
 * Example controller action:
 * 
 * public function actionSelector()
 * {
 *     $ctype = Yii::$app->request->get('ctype', 'page');
 *     
 *     // Use the new CrelishDataManager to get content
 *     $dataManager = new \giantbits\crelish\components\CrelishDataManager($ctype, [
 *         'sort' => ['by' => ['created', 'desc']]
 *     ]);
 *     
 *     $result = $dataManager->all();
 *     
 *     return $this->renderAjax('_content', [
 *         'models' => $result['models'],
 *         'pagination' => $result['pagination'],
 *         'ctype' => $ctype
 *     ]);
 * }
 */ 