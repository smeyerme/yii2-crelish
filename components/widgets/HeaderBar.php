<?php

namespace giantbits\crelish\components\widgets;

use Yii;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * HeaderBar widget for creating modular, reusable header bars
 */
class HeaderBar extends Widget
{
  /**
   * @var array Components to include in the left section
   */
  public $leftComponents = [];

  /**
   * @var array Components to include in the right section
   */
  public $rightComponents = [];

  /**
   * @var array Additional HTML options for the header bar container
   */
  public $options = [];

  /**
   * @var array Predefined component configurations
   */
  private $_components = [];

  /**
   * @inheritdoc
   */
  public function init()
  {
    parent::init();

    // Initialize the components
    $this->initComponents();

    // Set default options
    $this->options['class'] = isset($this->options['class'])
      ? 'navbar--controller ' . $this->options['class']
      : 'navbar--controller';

    // Add controller-specific classes
    $controllerId = \Yii::$app->controller->id;
    if ($controllerId === 'elements') {
      $this->options['class'] .= ' c-nav c-nav--inline gc-bc--palette-orange';
    }
  }

  /**
   * @inheritdoc
   */
  public function run()
  {
    // Register search functionality
    $this->registerSearchJs();

    // Register CSS
    $this->registerCss();

    // Include delete confirmation dialog
    $deleteConfirmationDialog = '';
    if (isset(Yii::$app->view->renderers['twig'])) {
      $deleteConfirmationDialog = Yii::$app->view->renderFile(
        '@giantbits/crelish/components/widgets/views/_delete_confirmation.twig',
        []
      );
    }

    // Check if Twig view renderer is available
    if (isset(Yii::$app->view->renderers['twig'])) {
      // Use Twig template
      return $deleteConfirmationDialog . Yii::$app->view->renderFile(
        '@giantbits/crelish/components/widgets/views/header-bar.twig',
        [
          'leftComponents' => $this->renderComponents($this->leftComponents),
          'rightComponents' => $this->renderComponents($this->rightComponents),
          'options' => $this->options,
        ]
      );
    } else {
      // Fallback to PHP template
      return $deleteConfirmationDialog . $this->render('header-bar', [
        'leftComponents' => $this->renderComponents($this->leftComponents),
        'rightComponents' => $this->renderComponents($this->rightComponents),
        'options' => $this->options,
      ]);
    }
  }

  /**
   * Register CSS for the header bar
   */
  protected function registerCss()
  {
    $css = <<<CSS
        /* Override for select fields only */
        .navbar--controller .c-input-group select.c-field:not([multiple]) {
            border: none;
        }
        
        /* Test email input field styling to match search input */
        .navbar--controller .c-field.test-email-input, 
        .navbar--controller .test-email-input {
            border: 1px solid var(--color-border);
            border-radius: var(--border-radius-md);
            padding: 0.5rem 1rem;
            height: 40px;
            transition: var(--transition-standard);
        }
        CSS;

    Yii::$app->view->registerCss($css);
  }

  /**
   * Register JavaScript for search functionality and other interactions
   */
  protected function registerSearchJs()
  {
    // Determine the appropriate container ID based on the controller
    $controllerId = \Yii::$app->controller->id;
    $containerMap = [
      'asset' => '#assetList',
      'page' => '#contentList',
      'content' => '#contentSelect',
      'user' => '#userList',
      'registrations' => '#registrationsList',
    ];

    // Default to contentList if controller not in map
    $containerId = isset($containerMap[$controllerId]) ? $containerMap[$controllerId] : '#contentList';

    $js = <<<JS
        $(document).ready(function() {
            // Function to submit search
            function submitSearch(searchTerm) {
                // Get current URL parameters
                var urlParams = new URLSearchParams(window.location.search);

                // Update or add cr_content_filter
                urlParams.set('cr_content_filter', searchTerm);

                // Build the new URL preserving all parameters including 'cet' if present
                var baseUrl = window.location.href.split('?')[0];
                var newUrl = baseUrl + '?' + urlParams.toString();

                $.pjax({
                    url: newUrl,
                    container: '{$containerId}'
                });
            }
            
            // Handle search input
            $('.header-search-input').on('keypress', function(e) {
                if (e.which === 13) { // Enter key
                    e.preventDefault();
                    submitSearch($(this).val());
                }
            });
            
            // Handle blur event on search input
            $('.header-search-input').on('blur', function() {
                submitSearch($(this).val());
            });
            
            // Handle search button click
            $('.c-button--search').on('click', function() {
                var searchTerm = $('.header-search-input').val();
                submitSearch(searchTerm);
            });
            
            // Handle clear button click
            $(document).on('click', '.search-clear-btn', function() {
                $('.header-search-input').val('');
                submitSearch('');
            });
            
            // Handle status filter change
            $('.header-status-filter').on('change', function() {
                var statusValue = $(this).val();
                var currentUrl = window.location.href;
                var url = new URL(currentUrl);
                url.searchParams.set('cr_status_filter', statusValue);
                window.location.href = url.toString();
            });
            
            // Handle checkbox selection for delete button
            $(document).on('change', 'input[name="selection[]"]', function() {
                var anyChecked = $('input[name="selection[]"]:checked').length > 0;
                if (anyChecked) {
                    $('.btn-delete-grid').removeClass('hidden');
                } else {
                    $('.btn-delete-grid').addClass('hidden');
                }
            });
            
            // Handle select all checkbox
            $(document).on('change', '#select-all', function() {
                var isChecked = $(this).is(':checked');
                $('input[name="selection[]"]').prop('checked', isChecked);
                if (isChecked) {
                    $('.btn-delete-grid').removeClass('hidden');
                } else {
                    $('.btn-delete-grid').addClass('hidden');
                }
            });
            
            // Handle confirm delete button click
            $('#confirm-delete-btn').on('click', function() {
                $('form[id$="-grid-form"]').submit();
            });
        });
        JS;

    \Yii::$app->view->registerJs($js);
  }

  /**
   * Render the components
   *
   * @param array $components The components to render
   * @return string The rendered components
   */
  protected function renderComponents($components)
  {
    $html = '';

    foreach ($components as $component) {
      // Check if component is an array with parameters
      if (is_array($component) && isset($component[0])) {
        $componentName = $component[0];
        $params = array_slice($component, 1);

        if (isset($this->_components[$componentName]) && is_callable($this->_components[$componentName])) {
          $html .= call_user_func_array($this->_components[$componentName], $params);
        }
      } // Regular component without parameters
      elseif (isset($this->_components[$component])) {
        if (is_callable($this->_components[$component])) {
          $html .= $this->_components[$component]();
        } else {
          $html .= $this->_components[$component]['content'];
        }
      }
    }

    return $html;
  }

  /**
   * Add a custom component to the predefined components list
   *
   * @param string $name Component name
   * @param array $config Component configuration
   */
  public function addComponent($name, $config)
  {
    $this->_components[$name] = $config;
  }

  /**
   * Static helper method to create a HeaderBar widget
   *
   * @param array $config Widget configuration
   * @return string Rendered widget
   */
  public static function widget($config = [])
  {
    return parent::widget($config);
  }

  /**
   * Initialize the predefined components
   */
  private function initComponents(): void
  {
    // Get current content type
    $ctype = \Yii::$app->session->get('ctype');

    // Get current controller ID
    $controllerId = \Yii::$app->controller->id;

    // Define the components
    $this->_components = [
      'toggle-sidebar' => function () {
        return '<div class="toggle-sidenav"><i class="fa-sharp  fa-regular fa-bars"></i></div>';
      },
      'back-button' => function ($controller = null) {

        if(empty($controller)) {
          $controller = \Yii::$app->controller->id;
        }
        $module = \Yii::$app->controller->module->id;

        // Create a proper URL array with module and controller
        $url = ["/{$module}/{$controller}/index"];

        // Add ctype parameter if it exists in the session
        if (\Yii::$app->session->has('ctype')) {
          $url['ctype'] = \Yii::$app->session->get('ctype');
        }

        return '<a class="c-button" href="' . \Yii::$app->urlManager->createUrl($url) . '">
                    <i class="fa-sharp fa-solid fa-arrow-left"></i> ' . Yii::t('app', 'Back') . '
                </a>';
      },
      'search' => function () {
        $searchValue = \Yii::$app->request->get('cr_content_filter', '');

        // Check if Twig view renderer is available
        if (isset(Yii::$app->view->renderers['twig'])) {
          // Use Twig template
          return Yii::$app->view->renderFile(
            '@giantbits/crelish/components/widgets/views/_search.twig',
            [
              'filterValue' => $searchValue,
            ]
          );
        } else {
          // Fallback to PHP template or direct HTML
          $html = '<button class="c-button c-button--search"><i class="fa-sharp  fa-regular fa-search"></i></button>';
          $html .= '<div class="o-field" style="position: relative; flex-grow: 1; max-width: 100%;">';
          $html .= Html::textInput('cr_content_filter', $searchValue, [
            'class' => 'c-field header-search-input',
            'style' => 'width: 100%;',
            'placeholder' => \Yii::t('app', 'Type your search phrase here...'),
          ]);
          if (!empty($searchValue)) {
            $html .= '<span class="search-clear-btn" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer;"><i class="fa-sharp  fa-regular fa-times"></i></span>';
          }
          $html .= '</div>';
          return $html;
        }
      },
      'user-search' => function () {
        $searchValue = \Yii::$app->request->get('cr_content_filter', '');
        $statusValue = \Yii::$app->request->get('cr_status_filter', '');

        // Check if Twig view renderer is available
        if (isset(Yii::$app->view->renderers['twig'])) {
          // Use Twig template
          return Yii::$app->view->renderFile(
            '@giantbits/crelish/components/widgets/views/_user_search.twig',
            [
              'filterValue' => $searchValue,
              'statusValue' => $statusValue,
            ]
          );
        } else {
          // Fallback to PHP template or direct HTML
          $html = '<button class="c-button c-button--search"><i class="fa-sharp  fa-regular fa-search"></i></button>';
          $html .= '<div class="o-field" style="position: relative; flex-grow: 1; max-width: 100%;">';
          $html .= Html::textInput('cr_content_filter', $searchValue, [
            'class' => 'c-field header-search-input',
            'style' => 'width: 100%;',
            'placeholder' => \Yii::t('app', 'Search users...'),
          ]);
          if (!empty($searchValue)) {
            $html .= '<span class="search-clear-btn" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer;"><i class="fa-sharp  fa-regular fa-times"></i></span>';
          }
          $html .= '</div>';

          // Add status filter dropdown
          $html .= Html::dropDownList('cr_status_filter', $statusValue, [
            '' => \Yii::t('app', 'All statuses'),
            '1' => \Yii::t('app', 'Inactive'),
            '2' => \Yii::t('app', 'Online'),
            '3' => \Yii::t('app', 'Archived'),
          ], [
            'class' => 'c-field header-status-filter',
            'style' => 'margin-left: 10px;'
          ]);

          return $html;
        }
      },
      'save' => function ($showSaveAndReturn = true, $showDelete = false) {
        // Check if Twig view renderer is available
        if (isset(Yii::$app->view->renderers['twig'])) {

          $controller = \Yii::$app->controller->id;
          $module = \Yii::$app->controller->module->id;
          $ctype = \Yii::$app->request->get('ctype');
          $uuid = \Yii::$app->request->get('uuid');
          $deleteUrl = \Yii::$app->urlManager->createUrl(["/{$module}/{$controller}/delete", 'ctype' => $ctype, 'uuid' => $uuid]);

          // Use Twig template
          return Yii::$app->view->renderFile(
            '@giantbits/crelish/components/widgets/views/_save_buttons.twig',
            [
              'showSaveAndReturn' => $showSaveAndReturn,
              'showDelete' => $showDelete,
              'deleteUrl' => $deleteUrl,
            ]
          );
        } else {
          // Fallback to PHP template or direct HTML
          $html = '<div class="c-input-group group-content-filter">';
          $html .= '<span id="submitButton" class="c-button btn-save c-button--success" type="button" onclick="$(\'#content-form\').submit();">';
          $html .= '<i class="fa-sharp  fa-regular fa-save"></i> ';
          $html .= '</span>';
          
          if ($showSaveAndReturn) {
            $html .= '<span class="c-button btn-save c-button--success-darker" type="button" onclick="$(\'#save_n_return\').val(\'1\'); $(\'#content-form\').submit();">';
            $html .= '<i class="fa-sharp  fa-regular fa-save"></i> <i class="fa-sharp  fa-regular fa-reply"></i> ';
            $html .= '</span>';
          }
          
          if ($showDelete) {
            $controller = \Yii::$app->controller->id;
            $module = \Yii::$app->controller->module->id;
            $ctype = \Yii::$app->request->get('ctype');
            $uuid = \Yii::$app->request->get('uuid');
            
            $deleteUrl = \Yii::$app->urlManager->createUrl(["/{$module}/{$controller}/delete", 'ctype' => $ctype, 'uuid' => $uuid]);
            
            $html .= '<span class="c-button btn-delete c-button--error" type="button" onclick="openDeleteDialog(\'' . $deleteUrl . '\');">';
            $html .= '<i class="fa-sharp  fa-regular fa-trash"></i>';
            $html .= '</span>';
          }
          
          $html .= '</div>';

          // Register keyboard shortcut
          $js = <<<JS
                    document.addEventListener('keydown', function (event) {
                      if ((event.ctrlKey || event.metaKey) && event.key === 's') {
                        event.preventDefault();
                        document.getElementById('submitButton').click();
                      }
                    });
                    JS;
          \Yii::$app->view->registerJs($js);

          return $html;
        }
      },
      'create' => function ($ctype = null, $controller = null) use ($controllerId) {
        // First try to get ctype from request
        if(empty($ctype)) {
          $ctype = \Yii::$app->request->get('ctype');
        }
        // Get base URL for use in JavaScript
        if(empty($controller)) {
          $controller = $controllerId;
        }

        $baseUrl = \Yii::$app->urlManager->createUrl(['/crelish/' . $controller . '/create']);
        $baseUrl = preg_replace('/\?.*$/', '', $baseUrl); // Remove any query parameters

        // Add JavaScript to handle pushState updates
        $js = <<<JS
        $(document).ready(function() {
            // Function to update create button URL
            function updateCreateButton() {
                // Extract ctype from current URL
                var url = new URL(window.location.href);
                var urlCtype = url.searchParams.get('ctype');
                
                if (urlCtype) {
                    // Update all create buttons with the current ctype
                    $('.create-content-btn').attr('href', 
                        '{$baseUrl}?ctype=' + encodeURIComponent(urlCtype)
                    );
                }
            }
            
            // Update on initial page load
            updateCreateButton();
            
            // Update when content is loaded via pjax
            $(document).on('pjax:complete', updateCreateButton);
            
            // Update on browser navigation (back/forward)
            $(window).on('popstate', updateCreateButton);
        });
        JS;
        \Yii::$app->view->registerJs($js);
        
        return '<a href="' . \Yii::$app->urlManager->createUrl(['/crelish/' . $controller . '/create', 'ctype' => $ctype]) . '" class="c-button create-content-btn"><i class="fa-sharp  fa-regular fa-plus"></i></a>';
      },
      'user-create' => function () {
        return '<a href="' . \Yii::$app->urlManager->createUrl(['/crelish/user/create']) . '" class="c-button"><i class="fa-sharp  fa-regular fa-user-plus"></i></a>';
      },
      'asset-view-controls' => function () {
        $html = '<span class="c-input-group u-small" style="margin-right: 0;">';
        $html .= '<button class="c-button c-button--error btn-delete-grid hidden">';
        $html .= '<i class="fa-sharp  fa-regular fa-check-square"></i> ' . \Yii::t('app', 'Löschen');
        $html .= '</button>';
        $html .= '<a class="c-button" id="switch-to-grid"><i class="fa-sharp fa-solid fa-th-large"></i></a>';
        $html .= '<a class="c-button" id="switch-to-list"><i class="fa-sharp fa-solid fa-list"></i></a>';
        $html .= '</span>';

        // Register JavaScript for view controls
        $js = <<<JS
                $(document).ready(function() {
                    // Handle grid view button click
                    $('#switch-to-grid').on('click', function() {
                        $('.asset-list').addClass('grid-view').removeClass('list-view');
                        localStorage.setItem('assetViewMode', 'grid');
                    });
                    
                    // Handle list view button click
                    $('#switch-to-list').on('click', function() {
                        $('.asset-list').addClass('list-view').removeClass('grid-view');
                        localStorage.setItem('assetViewMode', 'list');
                    });
                    
                    // Set initial view mode from localStorage
                    var viewMode = localStorage.getItem('assetViewMode') || 'grid';
                    if (viewMode === 'grid') {
                        $('#switch-to-grid').trigger('click');
                    } else {
                        $('#switch-to-list').trigger('click');
                    }
                });
                JS;

        \Yii::$app->view->registerJs($js);

        return $html;
      },
      'elements-title' => [
        'content' => '<span class="c-nav__content">' . Yii::t('app', 'Elements') . '</span>',
      ],
      'title' => function ($title = null) {
        // Use provided title or get from controller ID
        if ($title === null) {
          $controllerId = \Yii::$app->controller->id;
          $title = ucfirst($controllerId);
        }
        return '<span class="c-nav__content">' . Yii::t('app', $title) . '</span>';
      },
      'delete' => function () {
        return '<button class="c-button c-button--error btn-delete-grid hidden">
                    <i class="fa-sharp  fa-regular fa-check-square"></i> ' . '
                </button>';
      },
      'item-delete' => function () {
        $controller = \Yii::$app->controller->id;
        $module = \Yii::$app->controller->module->id;
        $ctype = \Yii::$app->request->get('ctype');
        $uuid = \Yii::$app->request->get('uuid');
        
        $deleteUrl = \Yii::$app->urlManager->createUrl(["/{$module}/{$controller}/delete", 'ctype' => $ctype, 'uuid' => $uuid]);
        
        return '<button class="c-button c-button--error" onclick="openDeleteDialog(\'' . $deleteUrl . '\');">
                    <i class="fa-sharp  fa-regular fa-trash"></i>
                </button>';
      },
      
      // Translation search component for the header bar
      'translation-search' => function () {
        // Check if Twig view renderer is available
        if (isset(Yii::$app->view->renderers['twig'])) {
          // Use Twig template
          return Yii::$app->view->renderFile(
            '@giantbits/crelish/components/widgets/views/_translation_search.twig',
            []
          );
        } else {
          // Fallback to PHP template or direct HTML
          $html = '<button class="c-button c-button--search"><i class="fa-sharp fa-regular fa-search"></i></button>';
          $html .= '<div class="o-field" style="position: relative; flex-grow: 1; max-width: 100%;">';
          $html .= Html::textInput('translation_global_search', '', [
            'id' => 'global-search',
            'class' => 'c-field header-search-input',
            'style' => 'width: 100%;',
            'placeholder' => \Yii::t('app', 'Search for keys or translations...'),
          ]);
          $html .= '<span class="search-clear-btn" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; display: none;"><i class="fa-sharp fa-regular fa-times"></i></span>';
          $html .= '</div>';
          
          return $html;
        }
      },
      
      // Test email component for newsletter testing
      'test-email' => function () {
        $html = '<div class="c-input-group" style="margin-right: 15px;">';
        $html .= Html::textInput('test_email', '', [
          'class' => 'c-field test-email-input',
          'style' => 'width: 200px; margin-right: 5px;',
          'placeholder' => \Yii::t('app', 'Enter email address...'),
        ]);
        $html .= '<button class="c-button c-button--primary test-email-send-btn" type="button">';
        $html .= '<i class="fa-sharp fa-regular fa-envelope"></i>';
        $html .= '</button>';
        $html .= '</div>';

        // Register JavaScript for test email functionality
        $js = <<<JS
        \$(document).ready(function() {
            \$('.test-email-send-btn').on('click', function() {
                var email = \$('.test-email-input').val();
                var newsletterId = getNewsletterIdFromUrl();
                
                if (!email) {
                    alert('Please enter an email address');
                    return;
                }
                
                if (!validateEmail(email)) {
                    alert('Please enter a valid email address');
                    return;
                }
                
                if (!newsletterId) {
                    alert('Newsletter ID not found. Please save the newsletter first.');
                    return;
                }
                
                // Disable button and show loading state
                var btn = \$(this);
                var originalHtml = btn.html();
                btn.prop('disabled', true).html('<i class="fa-sharp fa-regular fa-spinner fa-spin"></i>');
                
                // Send test email
                \$.ajax({
                    url: '/crelish/newsletter/send-test',
                    method: 'POST',
                    data: {
                        email: email,
                        id: newsletterId
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            alert('Test email sent successfully!');
                            \$('.test-email-input').val(''); // Clear the input
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function(xhr) {
                        var response = xhr.responseJSON;
                        var message = response && response.message ? response.message : 'Failed to send test email';
                        alert('Error: ' + message);
                    },
                    complete: function() {
                        // Re-enable button and restore original state
                        btn.prop('disabled', false).html(originalHtml);
                    }
                });
            });
            
            // Function to validate email format
            function validateEmail(email) {
                var re = /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+\$/;
                return re.test(email);
            }
            
            // Function to get newsletter ID from URL
            function getNewsletterIdFromUrl() {
                var urlParams = new URLSearchParams(window.location.search);
                return urlParams.get('uuid');
            }
            
            // Handle Enter key in email input
            \$('.test-email-input').on('keypress', function(e) {
                if (e.which === 13) { // Enter key
                    e.preventDefault();
                    \$('.test-email-send-btn').click();
                }
            });
        });
        JS;

        \Yii::$app->view->registerJs($js);

        return $html;
      },
    ];
  }
} 