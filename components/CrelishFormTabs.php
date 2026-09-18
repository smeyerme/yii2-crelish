<?php

namespace giantbits\crelish\components;

use yii\helpers\Html;

/**
 * Renders the tab navigation and panes of a Crelish content form.
 *
 * Pure presentation: it takes the element's tab definitions plus the model's
 * validation errors and returns markup. It holds no model and reaches no
 * database, which is what keeps it testable without an application context.
 *
 * An element with one visible tab is deliberately NOT tabbed — the caller
 * keeps its existing flat markup, so the ~100 single-tab elements across the
 * FORUM sites are untouched by this class.
 */
final class CrelishFormTabs
{
  /** @var object[] visible tab definitions, in declaration order */
  private array $tabs;

  public function __construct(array $tabs, private readonly string $ctype)
  {
    $this->tabs = array_values(array_filter($tabs, [self::class, 'isVisible']));
  }

  public static function isVisible(object $tab): bool
  {
    return !isset($tab->visible) || $tab->visible !== false;
  }

  /** @return object[] */
  public function visibleTabs(): array
  {
    return $this->tabs;
  }

  public function isTabbed(): bool
  {
    return count($this->tabs) > 1;
  }

  /**
   * Namespaced by ctype so two forms on one page (overlay mode) cannot
   * collide on pane ids.
   */
  public function paneId(object $tab): string
  {
    return 'crelish-tab-' . $this->ctype . '-' . $tab->key;
  }

  /** Every field key the tab owns, across all of its groups. */
  public static function fieldKeys(object $tab): array
  {
    $keys = [];

    foreach ($tab->groups ?? [] as $group) {
      foreach ($group->fields ?? [] as $key) {
        $keys[] = $key;
      }
    }

    return $keys;
  }

  public function errorCount(object $tab, array $errors): int
  {
    return count(array_intersect(self::fieldKeys($tab), array_keys($errors)));
  }

  /**
   * The tab to open: the first one carrying a validation error, otherwise the
   * first tab. A field error inside a collapsed pane would otherwise be
   * invisible.
   */
  public function activeKey(array $errors): string
  {
    foreach ($this->tabs as $tab) {
      if ($this->errorCount($tab, $errors) > 0) {
        return $tab->key;
      }
    }

    return $this->tabs[0]->key ?? '';
  }

  /**
   * The client-side counterpart of activeKey().
   *
   * activeKey() only runs when the request reaches the server. Yii validates in
   * the browser first, so an empty required field on a hidden pane blocks the
   * submit with no page load: the error is rendered where nobody can see it, the
   * first tab stays active, and the form looks like it ignores the save button.
   *
   * After each validation round this opens the first tab carrying an error and
   * puts the count on every affected tab — the same two signals the server-side
   * path gives. It reads the panes rather than a field-to-tab map so that
   * dynamically added rows (json-editor, matrix) are covered too.
   */
  public static function validationScript(string $formId): string
  {
    $id = json_encode($formId, JSON_THROW_ON_ERROR);

    return <<<JS
(function () {
  var form = document.getElementById({$id});
  var nav = document.querySelector('.crelish-form-tabs');
  // Yii fires afterValidate as a jQuery event; without jQuery there is nothing
  // to listen to, and the server-side path still covers the submitted case.
  if (!form || !nav || !window.jQuery) {
    return;
  }

  function sync() {
    var firstWithErrors = null;

    nav.querySelectorAll('[data-crelish-tab]').forEach(function (button) {
      var target = button.getAttribute('data-bs-target');
      var pane = target ? document.querySelector(target) : null;
      if (!pane) {
        return;
      }

      var count = pane.querySelectorAll('.has-error, .is-invalid').length;
      var badge = button.querySelector('.badge');

      if (count > 0) {
        button.classList.add('text-danger');
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'badge bg-danger ms-1';
          button.appendChild(badge);
        }
        badge.textContent = String(count);
        if (!firstWithErrors) {
          firstWithErrors = button;
        }
      } else {
        button.classList.remove('text-danger');
        if (badge) {
          badge.remove();
        }
      }
    });

    // Only pull the editor away from their tab when the errors are elsewhere.
    if (firstWithErrors && !firstWithErrors.classList.contains('active')) {
      firstWithErrors.click();
    }
  }

  window.jQuery(form).on('afterValidate', sync);
})();
JS;
  }

  public function renderNav(array $errors): string
  {
    $active = $this->activeKey($errors);
    $items = '';

    foreach ($this->tabs as $tab) {
      $count = $this->errorCount($tab, $errors);
      $isActive = $tab->key === $active;

      $label = Html::encode($tab->label);

      if ($count > 0) {
        $label .= ' ' . Html::tag('span', (string)$count, ['class' => 'badge bg-danger ms-1']);
      }

      $classes = 'nav-link';
      if ($isActive) {
        $classes .= ' active';
      }
      if ($count > 0) {
        $classes .= ' text-danger';
      }

      $items .= Html::tag(
        'li',
        Html::tag('button', $label, [
          // type is mandatory: a bare button inside the form would submit it.
          'type' => 'button',
          'class' => $classes,
          'role' => 'tab',
          'data-bs-toggle' => 'tab',
          'data-bs-target' => '#' . $this->paneId($tab),
          'data-crelish-tab' => $tab->key,
          'aria-controls' => $this->paneId($tab),
          'aria-selected' => $isActive ? 'true' : 'false',
        ]),
        ['class' => 'nav-item', 'role' => 'presentation']
      );
    }

    return Html::tag('ul', $items, [
      'class' => 'nav nav-tabs crelish-form-tabs',
      'role' => 'tablist',
      // Tells the restore script to stand down: the server already picked a tab.
      'data-crelish-has-errors' => $errors === [] ? '0' : '1',
    ]);
  }

  /**
   * @param callable $renderTab fn(object $tab): string — renders the tab's groups
   */
  public function renderPanes(callable $renderTab, array $errors): string
  {
    $active = $this->activeKey($errors);
    $panes = '';

    foreach ($this->tabs as $tab) {
      $classes = 'tab-pane fade';
      if ($tab->key === $active) {
        $classes .= ' show active';
      }

      $panes .= Html::tag(
        'div',
        // Each pane opens its own grid row; the caller's row wrapper is skipped
        // on the tabbed path so the nav is not an orphan grid child.
        Html::tag('div', $renderTab($tab), ['class' => 'row']),
        [
          'id' => $this->paneId($tab),
          'class' => $classes,
          'role' => 'tabpanel',
        ]
      );
    }

    return Html::tag('div', $panes, ['class' => 'tab-content']);
  }
}
