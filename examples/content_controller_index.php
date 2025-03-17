<?php
/**
 * This file demonstrates how to update the ContentController's index action
 * to properly handle the visibleInGrid attribute with the new storage system.
 */

/**
 * Updated actionIndex method for ContentController
 * 
 * @return string The rendered view
 * @throws Exception
 */
public function actionIndex()
{
    $filter = null;
    
    // Setup checkbox column for bulk actions
    $checkCol = [
        [
            'class' => 'giantbits\crelish\components\CrelishCheckboxColumn',
        ]
    ];

    // Handle bulk delete actions
    $modelClass = '\app\workspace\models\\' . ucfirst($this->ctype);
    if (!empty($_POST['selection']) && class_exists($modelClass)) {
        foreach ($_POST['selection'] as $selection) {
            $delModel = $modelClass::findOne($selection);
            $delModel->delete();
        }
    }

    // Handle content filtering
    if (key_exists('cr_content_filter', $_GET)) {
        $filter = ['freesearch' => $_GET['cr_content_filter']];
    } elseif (!empty(Yii::$app->session->get('cr_content_filter'))) {
        $filter = ['freesearch' => Yii::$app->session->get('cr_content_filter')];
    }

    // Create a data manager for the content type
    $dataManager = new \giantbits\crelish\components\CrelishDataManager($this->ctype, [
        'filter' => $filter,
        'pageSize' => 25
    ]);
    
    // Get the element definition
    $elementDefinition = $dataManager->getDefinitions();
    
    // Get the data provider
    $dataProvider = $dataManager->getProvider();
    
    // Build columns based on visibleInGrid attribute
    $columns = [];
    
    // Add checkbox column for bulk actions
    $columns = array_merge($columns, $checkCol);
    
    // Add columns for fields with visibleInGrid = true
    if (isset($elementDefinition->fields)) {
        foreach ($elementDefinition->fields as $field) {
            // Only include fields that have visibleInGrid = true
            if (property_exists($field, 'visibleInGrid') && $field->visibleInGrid === true) {
                $column = [
                    'attribute' => $field->key,
                    'label' => $field->label
                ];
                
                // Special handling for state field
                if ($field->key === 'state') {
                    $column['format'] = 'raw';
                    $column['label'] = Yii::t('app', 'Status');
                    $column['value'] = function ($data) {
                        switch ($data['state']) {
                            case 1:
                                return Yii::t('app', 'Draft');
                            case 2:
                                return Yii::t('app', 'Online');
                            case 3:
                                return Yii::t('app', 'Archived');
                            default:
                                return Yii::t('app', 'Offline');
                        }
                    };
                }
                // Special handling for dropdown fields
                elseif (property_exists($field, 'items')) {
                    $column['format'] = 'raw';
                    $column['value'] = function ($data) use ($field) {
                        $items = (array)$field->items;
                        return $items[$data[$field->key]] ?? $data[$field->key];
                    };
                }
                // Special handling for switch inputs
                elseif (property_exists($field, 'type') && str_contains($field->type, 'SwitchInput')) {
                    $column['format'] = 'raw';
                    $column['value'] = function ($data) use ($field) {
                        return $data[$field->key] == 0 ? Yii::t('app', 'No') : Yii::t('app', 'Yes');
                    };
                }
                // Special handling for value overwrites
                elseif (property_exists($field, 'valueOverwrite')) {
                    $column['format'] = 'raw';
                    $column['value'] = function ($data) use ($field) {
                        // Assuming Arrays::get is available or implement a similar function
                        return isset($data[$field->valueOverwrite]) ? $data[$field->valueOverwrite] : null;
                    };
                }
                
                $columns[] = $column;
            }
        }
    }
    
    // Add action column
    $columns[] = [
        'class' => 'yii\grid\ActionColumn',
        'template' => '{update} {delete}',
        'buttons' => [
            'update' => function ($url, $model) {
                return Html::a('<span class="glyphicon glyphicon-pencil"></span>', 
                    ['content/update', 'ctype' => $this->ctype, 'uuid' => $model['uuid']], 
                    ['title' => Yii::t('app', 'Update')]);
            },
            'delete' => function ($url, $model) {
                return Html::a('<span class="glyphicon glyphicon-trash"></span>', 
                    ['content/delete', 'ctype' => $this->ctype, 'uuid' => $model['uuid']], 
                    [
                        'title' => Yii::t('app', 'Delete'),
                        'data-confirm' => Yii::t('app', 'Are you sure you want to delete this item?'),
                        'data-method' => 'post',
                    ]);
            },
        ],
    ];
    
    // Make rows clickable to edit the item
    $rowOptions = function ($model, $key, $index, $grid) {
        return ['onclick' => 'location.href="update?ctype=' . $this->ctype . '&uuid=' . $model['uuid'] . '";'];
    };
    
    // Get filters for the grid
    $filters = new \giantbits\crelish\components\CrelishDynamicModel(['ctype' => $this->ctype]);
    
    return $this->render('content.twig', [
        'dataProvider' => $dataProvider,
        'filterProvider' => $filters,
        'columns' => $columns,
        'ctype' => $this->ctype,
        'rowOptions' => $rowOptions
    ]);
} 