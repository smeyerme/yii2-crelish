<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\Component;
use yii\web\View;
use yii\helpers\Json;

/**
 * Helper component for Matrix Page Builder integration
 */
class MatrixBuilderHelper extends Component
{
  /**
   * Registers the necessary JS code for overlay mode detection
   * Call this in your content edit action
   *
   * @param View $view The view object
   * @return void
   */
  public static function registerOverlayMode($view)
  {
    // Check if we're in overlay mode
    $isOverlay = Yii::$app->request->get('overlay', 0) == 1;


    if ($isOverlay) {
      // Register CSS to optimize the view for iframe display
      $css = <<<CSS
                /* Hide unnecessary elements in overlay mode */
                #cr-left-pane {
                    display: none !important;
                }
                
                a:has(.fui-arrow-left),
                .navbar--controller span:not(#submitButton) {
                  display: none !important;
                }
                
                .content-wrapper, .main-content, .content {
                    margin: 0 !important;
                    padding: 0 !important;
                }
                body {
                    background: #fff !important;
                }
                .box {
                    border: none !important;
                    box-shadow: none !important;
                    margin-bottom: 0 !important;
                }
                .box-header {
                    display: none !important;
                }
                .form-group.buttons {
                    padding: 15px !important;
                    background: #f8f9fa !important;
                    position: sticky !important;
                    bottom: 0 !important;
                    z-index: 100 !important;
                    margin-bottom: 0 !important;
                }
CSS;
      $view->registerCss($css);

      // Register JS to communicate save events to the parent window
      $js = <<<JS
                // Set a flag to determine if the form has been modified
                window.hasUnsavedChanges = false;
                
                // Track form changes
                $(document).on('change', 'form input, form textarea, form select', function() {
                    window.hasUnsavedChanges = true;
                    
                    // Notify parent window
                    if (window.parent && window.parent !== window) {
                        try {
                            window.parent.postMessage({ 
                                action: 'content-modified',
                                state: true
                            }, '*');
                        } catch(e) {
                            console.error('Failed to notify parent window', e);
                        }
                    }
                });
                
                // Track successful form submission
                $('form').on('beforeSubmit', function(e) {
                    // Store the submission state
                    localStorage.setItem('form_submitted', 'true');
                    window.hasUnsavedChanges = false;
                    
                    // Notify parent window
                    if (window.parent && window.parent !== window) {
                        try {
                            window.parent.postMessage({ 
                                action: 'content-saved',
                                elementId: '{$_GET['uuid']}',
                                contentType: '{$_GET['ctype']}'
                            }, '*');
                        } catch(e) {
                            console.error('Failed to notify parent window', e);
                        }
                    }
                    
                    return true;
                });
                
                // Create a custom event for AJAX form success
                window.triggerSaveEvent = function() {
                    window.hasUnsavedChanges = false;
                    
                    // Create and dispatch a custom event
                    var saveEvent = new CustomEvent('ajaxFormSaved');
                    window.dispatchEvent(saveEvent);
                    
                    // Also try to notify the parent directly
                    if (window.parent && window.parent !== window) {
                        try {
                            window.parent.postMessage({ 
                                action: 'content-saved',
                                elementId: '{$_GET['uuid']}',
                                contentType: '{$_GET['ctype']}'
                            }, '*');
                        } catch(e) {
                            console.error('Failed to notify parent window', e);
                        }
                    }
                };
                
                // Hook into Yii's AJAX success handler
                $(document).ajaxSuccess(function(event, xhr, settings) {
                    // Check if this was a form submission
                    if (settings.type === 'POST' && 
                        (settings.url.indexOf('/update') > -1 || 
                         settings.url.indexOf('/create') > -1 || 
                         settings.url.indexOf('/save') > -1)) {
                        
                        // Trigger our custom save event
                        window.triggerSaveEvent();
                    }
                });
JS;
      $view->registerJs($js, View::POS_READY);
    }
  }

  /**
   * Registers success notification script in the controller action
   * Call this in your controller action after successful save
   *
   * @param View $view The view object
   * @return void
   */
  public static function notifySaveSuccess($view)
  {
    $isOverlay = Yii::$app->request->get('overlay', 0) == 1;

    if ($isOverlay) {
      $js = <<<JS
                // Trigger save notification
                if (typeof window.triggerSaveEvent === 'function') {
                    window.triggerSaveEvent();
                }
JS;
      $view->registerJs($js, View::POS_READY);
    }
  }

  /**
   * Registers a content selector for the matrix builder
   *
   * @param View $view The view object
   * @param array $options Additional options for the selector
   * @return void
   */
  public static function registerContentSelector($view, $options = [])
  {
    $isOverlay = Yii::$app->request->get('overlay', 0) == 1;
    $targetArea = Yii::$app->request->get('target', '');

    if ($isOverlay) {
      // Register JS to handle content selection
      $js = <<<JS
                // Find all add content buttons and attach click handlers
                $(document).on('click', '.cntAdd', function(e) {
                    e.preventDefault();
                    
                    // Get content data
                    var contentData = $(this).data('content');
                    
                    // Send message to parent window
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({
                            action: 'contentSelected',
                            content: contentData,
                            targetArea: '{$targetArea}'
                        }, '*');
                    }
                });
JS;
      $view->registerJs($js, View::POS_READY);

      // Register CSS for overlay mode
      $css = <<<CSS
                /* Optimize display for overlay mode */
                body {
                    background: white !important;
                    padding: 0 !important;
                    margin: 0 !important;
                }
                .main-header, .main-footer, .page-header, .breadcrumb {
                    display: none !important;
                }
                .content-wrapper {
                    margin: 0 !important;
                }
                .content {
                    padding: 15px !important;
                }
CSS;
      $view->registerCss($css);
    }
  }

  /**
   * Formats the content data into the expected JSON format for the page builder
   *
   * @param mixed $data The content data
   * @return string JSON encoded content data
   */
  public static function formatContentData($data)
  {
    if (is_string($data)) {
      $data = Json::decode($data, true);
    }

    // Make sure we have a valid structure
    if (!is_array($data)) {
      return Json::encode(['main' => []]);
    }

    // Ensure _layout exists
    if (!isset($data['_layout'])) {
      // Create a default layout from the available areas
      $areas = array_keys($data);
      $layout = [[]];

      foreach ($areas as $area) {
        if ($area !== '_layout') {
          $layout[0][] = $area;
        }
      }

      $data['_layout'] = Json::encode($layout);
    }

    return Json::encode($data);
  }

  /**
   * Creates a content API response for refreshing content data
   *
   * @param CrelishDynamicModel $model The content model
   * @return array Content data array
   */
  public static function createContentApiResponse($model)
  {
    $info = [];

    // Extract displayable info from model
    if (property_exists($model, 'definitions') && property_exists($model->definitions, 'fields')) {
      foreach ($model->definitions->fields as $field) {
        if (isset($field->visibleInGrid) && $field->visibleInGrid) {
          if (!empty($field->label) && isset($model->{$field->key})) {
            $value = $model->{$field->key};

            // Apply transformers if needed
            if ($field && property_exists($field, 'transform')) {
              $transformer = 'giantbits\crelish\components\transformer\CrelishFieldTransformer' . ucfirst($field->transform);
              if (class_exists($transformer) && method_exists($transformer, 'afterFind')) {
                $value = $transformer::afterFind($value);
              }
            }

            $info[] = ['label' => $field->label, 'value' => $value];
          }
        }
      }
    }

    // Ensure at least a title field exists
    if (empty($info)) {
      $info[] = ['label' => 'Titel', 'value' => $model->systitle ?? ''];
    }

    return [
      'uuid' => $model->uuid,
      'ctype' => $model->ctype,
      'info' => $info
    ];
  }
}