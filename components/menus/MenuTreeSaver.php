<?php

namespace giantbits\crelish\components\menus;

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\models\CrelishTranslation;
use giantbits\crelish\models\Menu;
use giantbits\crelish\models\MenuItem;
use Yii;

/**
 * Saves a whole menu tree submitted by the editor: optimistic concurrency
 * check, full validation before any write, then one transaction that first
 * claims the menu version with a conditional update (0 rows: 409).
 */
class MenuTreeSaver
{
  public const ALLOWED_URL = '~^(https?://|mailto:|tel:|/(?!/)|#)~i';

  private ContentUrlResolver $resolver;

  public function __construct(private Menu $menu, ?ContentUrlResolver $resolver = null)
  {
    $this->resolver = $resolver ?? new ContentUrlResolver();
  }

  /**
   * @return array{status:int, body:array}
   */
  public function save(array $payload): array
  {
    // Fast path: a stale token is rejected before validation; the write below re-checks atomically
    if ((int)($payload['updated'] ?? -1) !== (int)$this->menu->updated) {
      return $this->conflict();
    }

    $items = [];
    $errors = [];

    foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $raw) {
      $item = $this->normalize(is_array($raw) ? $raw : []);

      if ($item['id'] === '') {
        $errors['_'][] = Yii::t('crelish', 'An item has neither an id nor a client id.');
        continue;
      }

      if (isset($items[$item['id']])) {
        $errors[$item['id']][] = Yii::t('crelish', 'Duplicate item.');
        continue;
      }

      $items[$item['id']] = $item;
    }

    $existing = MenuItem::find()->where(['menu_uuid' => $this->menu->uuid])->indexBy('uuid')->all();
    $depths = [];

    foreach ($items as $id => $item) {
      foreach ($this->validate($item, $items, $existing, $depths) as $message) {
        $errors[$id][] = $message;
      }
    }

    if ($errors !== []) {
      return ['status' => 422, 'body' => ['errors' => $errors]];
    }

    $token = (int)$this->menu->updated;
    $next = Menu::nextUpdated($token);
    $transaction = Yii::$app->db->beginTransaction();

    try {
      $this->beforeVersionBump();

      // Claims the version atomically: a save that committed since the check above makes this match nothing
      if (Menu::updateAll(['updated' => $next], ['uuid' => $this->menu->uuid, 'updated' => $token]) === 0) {
        $transaction->rollBack();

        return $this->conflict();
      }

      $this->write($items, $existing, $depths);
      $transaction->commit();
    } catch (\Throwable $e) {
      $transaction->rollBack();
      Yii::error('Menu "' . $this->menu->key . '" could not be saved: ' . $e->getMessage(), 'crelish.menu');

      return ['status' => 500, 'body' => ['error' => Yii::t('crelish', 'The menu could not be saved.')]];
    }

    $this->menu->updated = $next;
    $this->menu->setOldAttribute('updated', $next);
    MenuService::invalidate();

    return ['status' => 200, 'body' => MenuAdminTree::build($this->menu, $this->resolver)];
  }

  private function conflict(): array
  {
    return ['status' => 409, 'body' => ['error' => Yii::t('crelish', 'Menu was changed by someone else. Please reload.')]];
  }

  /**
   * Test seam: runs inside the transaction right before the version bump.
   */
  protected function beforeVersionBump(): void
  {
  }

  protected function menuUuid(): string
  {
    return (string)$this->menu->uuid;
  }

  private function normalize(array $raw): array
  {
    $uuid = trim((string)($raw['uuid'] ?? ''));
    $clientId = trim((string)($raw['clientId'] ?? ''));
    $parent = trim((string)($raw['parentRef'] ?? ''));

    return [
      'id' => $uuid !== '' ? $uuid : $clientId,
      'uuid' => $uuid !== '' ? $uuid : null,
      'parentRef' => $parent !== '' ? $parent : null,
      'sort' => (int)($raw['sort'] ?? 0),
      'label' => trim((string)($raw['label'] ?? '')),
      'i18n' => is_array($raw['i18n'] ?? null) ? $raw['i18n'] : [],
      'target_type' => (string)($raw['target_type'] ?? ''),
      'target_ctype' => trim((string)($raw['target_ctype'] ?? '')),
      'target_uuid' => trim((string)($raw['target_uuid'] ?? '')),
      'target_url' => trim((string)($raw['target_url'] ?? '')),
      'new_window' => !empty($raw['new_window']),
      'state' => (int)($raw['state'] ?? MenuItem::STATE_ONLINE) === MenuItem::STATE_ONLINE ? MenuItem::STATE_ONLINE : MenuItem::STATE_OFFLINE,
    ];
  }

  /**
   * @param array<string, int> $depths filled with the depth of every item that has a valid parent chain
   * @return string[] error messages for this item
   */
  private function validate(array $item, array $items, array $existing, array &$depths): array
  {
    $messages = [];

    if ($item['uuid'] !== null && !isset($existing[$item['uuid']])) {
      $messages[] = Yii::t('crelish', 'Unknown item.');
    }

    $depth = 1;
    $seen = [$item['id'] => true];
    $parent = $item['parentRef'];

    while ($parent !== null) {
      if (!isset($items[$parent])) {
        $messages[] = Yii::t('crelish', 'The parent item does not exist.');
        break;
      }

      if (isset($seen[$parent])) {
        $messages[] = Yii::t('crelish', 'Items cannot be nested inside themselves.');
        break;
      }

      $seen[$parent] = true;
      $depth++;
      $parent = $items[$parent]['parentRef'];
    }

    $depths[$item['id']] = $depth;

    if ($depth > (int)$this->menu->max_depth) {
      $messages[] = Yii::t('crelish', 'This menu allows at most {depth} levels.', ['depth' => (int)$this->menu->max_depth]);
    }

    if (mb_strlen($item['label']) > 255) {
      $messages[] = Yii::t('crelish', 'The label is too long (255 characters max).');
    }

    // Only configured non-default languages count; other keys are ignored and never stored
    foreach (array_slice(MenuAdminTree::languages(), 1) as $language) {
      $value = $item['i18n'][$language] ?? null;

      if ($value !== null && (!is_string($value) || mb_strlen(trim($value)) > 255)) {
        $messages[] = Yii::t('crelish', 'The translation ({language}) must be text of at most 255 characters.', ['language' => $language]);
      }
    }

    switch ($item['target_type']) {
      case MenuItem::TARGET_CONTENT:
        if (!ContentUrlResolver::canResolveType($item['target_ctype'])) {
          $messages[] = Yii::t('crelish', 'This content type cannot be linked.');
        }
        if ($item['target_uuid'] === '') {
          $messages[] = Yii::t('crelish', 'Please choose the content to link.');
        }
        break;
      case MenuItem::TARGET_URL:
        if ($item['target_url'] === '' || !preg_match(self::ALLOWED_URL, $item['target_url'])) {
          $messages[] = Yii::t('crelish', 'Please enter a URL starting with https://, http://, mailto:, tel:, / or #.');
        }
        break;
      case MenuItem::TARGET_NONE:
        if ($item['label'] === '') {
          $messages[] = Yii::t('crelish', 'An item without a link needs a label.');
        }
        break;
      default:
        $messages[] = Yii::t('crelish', 'Unknown target type.');
    }

    return $messages;
  }

  /**
   * Parents before children, so new parents exist before anything points at them.
   */
  private function write(array $items, array $existing, array $depths): void
  {
    $uuids = [];

    foreach ($items as $id => $item) {
      $uuids[$id] = $item['uuid'] ?? CrelishBaseHelper::GUIDv4();
    }

    uasort($items, fn(array $a, array $b) => $depths[$a['id']] <=> $depths[$b['id']]);
    $languages = array_slice(MenuAdminTree::languages(), 1);

    foreach ($items as $id => $item) {
      $model = $existing[$item['uuid'] ?? ''] ?? new MenuItem(['uuid' => $uuids[$id], 'menu_uuid' => $this->menu->uuid]);
      $model->parent_uuid = $item['parentRef'] !== null ? $uuids[$item['parentRef']] : null;
      $model->sort = $item['sort'];
      $model->label = $item['label'] !== '' ? $item['label'] : null;
      $model->target_type = $item['target_type'];
      $model->target_ctype = $item['target_type'] === MenuItem::TARGET_CONTENT ? $item['target_ctype'] : null;
      $model->target_uuid = $item['target_type'] === MenuItem::TARGET_CONTENT ? $item['target_uuid'] : null;
      $model->target_url = $item['target_type'] === MenuItem::TARGET_URL ? $item['target_url'] : null;
      $model->new_window = $item['new_window'] ? 1 : 0;
      $model->state = $item['state'];

      $translations = [];
      foreach ($languages as $language) {
        $translations[$language] = ['label' => trim((string)($item['i18n'][$language] ?? ''))];
      }
      $model->setTranslations($translations);

      if (!$model->save(false)) {
        throw new \RuntimeException('Item ' . $id . ' could not be saved');
      }
    }

    $removed = array_diff(array_keys($existing), array_values($uuids));

    if ($removed !== []) {
      CrelishTranslation::deleteAll(['source_model' => MenuItem::tableName(), 'source_model_uuid' => array_values($removed)]);
      MenuItem::deleteAll(['uuid' => array_values($removed)]);
    }
  }
}
