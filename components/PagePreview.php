<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\InvalidConfigException;
use yii\db\BaseActiveRecord;
use yii\helpers\Html;
use yii\web\Response;
use yii\web\View;

/**
 * Signed preview links for unpublished pages.
 *
 * A token carries the page uuid and an expiry timestamp, signed with
 * Security::hashData() (HMAC) and base64url encoded for the query string.
 * Key: params['crelish']['previewSecret'] (a string of at least 32 characters,
 * otherwise ignored with a warning), else the request's cookieValidationKey;
 * without either, previews are disabled.
 * TTL: params['crelish']['previewTtl'] seconds, default 86400.
 */
class PagePreview
{
  public const PARAM = 'preview';
  public const DEFAULT_TTL = 86400;

  /** Payload version/purpose marker, keeps these signatures apart from other uses of the same key */
  private const PREFIX = 'p1';
  private const MAX_TOKEN_LENGTH = 512;
  public const MIN_SECRET_LENGTH = 32;

  private static ?int $warnedApp = null;

  public static function isEnabled(): bool
  {
    return self::key() !== null;
  }

  /**
   * @throws InvalidConfigException when no signing key is configured
   */
  public static function createToken(string $pageUuid, ?int $now = null): string
  {
    $key = self::key();

    if ($key === null) {
      throw new InvalidConfigException('Page preview needs params[crelish][previewSecret] or request.cookieValidationKey.');
    }

    $expires = ($now ?? time()) + self::ttl();
    $signed = Yii::$app->security->hashData(self::PREFIX . '|' . $pageUuid . '|' . $expires, $key);

    return rtrim(strtr(base64_encode($signed), '+/', '-_'), '=');
  }

  /**
   * False for anything but an untampered, unexpired token for this page; never throws.
   */
  public static function validateToken(string $token, string $pageUuid, ?int $now = null): bool
  {
    try {
      $key = self::key();

      if ($key === null || $pageUuid === '' || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH
        || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
        return false;
      }

      $signed = base64_decode(strtr($token, '-_', '+/'), true);

      // Only the canonical encoding counts, so no two strings map to the same token
      if ($signed === false || rtrim(strtr(base64_encode($signed), '+/', '-_'), '=') !== $token) {
        return false;
      }

      $payload = Yii::$app->security->validateData($signed, $key);

      if (!is_string($payload)) {
        return false;
      }

      $parts = explode('|', $payload);

      if (count($parts) !== 3 || $parts[0] !== self::PREFIX || !ctype_digit($parts[2])) {
        return false;
      }

      return hash_equals($parts[1], $pageUuid) && (int)$parts[2] >= ($now ?? time());
    } catch (\Throwable $e) {
      Yii::warning('Page preview: token validation failed: ' . $e->getMessage(), 'crelish');
      return false;
    }
  }

  /**
   * Whether the frontend serves this page as a preview: only an unpublished
   * page with a valid token for it. Published pages never are previews.
   */
  public static function shouldServe(object $page, mixed $token, ?int $now = null): bool
  {
    $now ??= time();

    if (!is_string($token) || $token === '' || ContentUrlResolver::isPublished($page, $now)) {
      return false;
    }

    $uuid = self::attribute($page, 'uuid');

    return is_string($uuid) && $uuid !== '' && self::validateToken($token, $uuid, $now);
  }

  /**
   * Absolute frontend URL of the page in the default content language with a fresh preview token.
   *
   * @return string|null null when the page has no uuid or slug
   * @throws InvalidConfigException when no signing key is configured
   */
  public static function url(object $page, ?int $now = null): ?string
  {
    $uuid = (string)self::attribute($page, 'uuid');
    $slug = (string)self::attribute($page, 'slug');

    if ($uuid === '' || $slug === '') {
      return null;
    }

    return CrelishBaseHelper::urlFromSlug($slug, [self::PARAM => self::createToken($uuid, $now)], CrelishBaseHelper::defaultContentLanguage(), true);
  }

  /**
   * Why a page is not published: 'offline' (state 0 or unknown), 'draft' (1),
   * 'archived' (3) or 'window' (online but outside from/to); null when published.
   */
  public static function unpublishedReason(object $page, ?int $now = null): ?string
  {
    if (ContentUrlResolver::isPublished($page, $now)) {
      return null;
    }

    $state = self::attribute($page, 'state');

    if ($state === null || (int)$state === ContentUrlResolver::STATE_ONLINE) {
      return 'window';
    }

    return match ((int)$state) {
      1 => 'draft',
      3 => 'archived',
      default => 'offline',
    };
  }

  /**
   * URL for the page frame of the admin edit view: the live URL in the default
   * content language for a published page, the signed preview URL otherwise, so
   * editors see the page instead of a 404. Falls back to the live URL when
   * previews are disabled. Pass the stored page (framePage()), not the form model.
   */
  public static function frameUrl(object $page, ?int $now = null): string
  {
    $slug = (string)self::attribute($page, 'slug');
    $liveUrl = $slug === ''
      ? Yii::$app->getRequest()->getHostInfo() . '/'
      : CrelishBaseHelper::urlFromSlug($slug, [], CrelishBaseHelper::defaultContentLanguage(), true);

    if (self::unpublishedReason($page, $now) === null || !self::isEnabled()) {
      return $liveUrl;
    }

    try {
      return self::url($page, $now) ?? $liveUrl;
    } catch (\Throwable $e) {
      Yii::warning('Page preview: no preview url for the page frame: ' . $e->getMessage(), 'crelish');
      return $liveUrl;
    }
  }

  /**
   * The stored page (default-language column values) for the admin page frame.
   *
   * Not the form model: CrelishDynamicModel::loadModelData() skips empty values,
   * so state 0 (offline) never reaches it and the page would look published.
   */
  public static function framePage(string $uuid): ?object
  {
    if ($uuid === '') {
      return null;
    }

    try {
      return CrelishTranslationBehavior::withoutTranslations(static fn() => ContentUrlResolver::findRecord('page', $uuid));
    } catch (\Throwable $e) {
      Yii::warning('Page preview: page ' . $uuid . ' not loaded for the page frame: ' . $e->getMessage(), 'crelish');
      return null;
    }
  }

  /**
   * Strip above the page frame telling the editor it shows a preview and why; empty when published.
   */
  public static function frameNotice(object $page, ?int $now = null): string
  {
    $label = match (self::unpublishedReason($page, $now)) {
      null => null,
      'draft' => Yii::t('crelish', 'Preview – page is a draft'),
      'archived' => Yii::t('crelish', 'Preview – page is archived'),
      'window' => Yii::t('crelish', 'Preview – page is outside its publication window'),
      default => Yii::t('crelish', 'Preview – page is offline'),
    };

    if ($label === null) {
      return '';
    }

    return '<div class="crelish-frame-notice border-top border-4 border-warning rounded-top pt-2 mb-2">'
      . '<span class="badge text-bg-warning"><i class="fa-sharp fa-regular fa-eye"></i> ' . Html::encode($label) . '</span>'
      . '</div>';
  }

  /**
   * Admin header bar: "Preview" (opens the signed URL in a new tab) and "Copy preview link".
   * Empty unless editing an existing page and previews are enabled.
   *
   * @param callable(string, string): ?object|null $recordFinder defaults to ContentUrlResolver::findRecord
   */
  public static function headerBarButtons(?string $ctype, ?string $uuid, ?callable $recordFinder = null, ?int $now = null): string
  {
    if ($ctype !== 'page' || $uuid === null || $uuid === '' || !self::isEnabled()) {
      return '';
    }

    try {
      $page = ($recordFinder ?? [ContentUrlResolver::class, 'findRecord'])('page', $uuid);
      $url = $page === null ? null : self::url($page, $now);
    } catch (\Throwable $e) {
      Yii::warning('Page preview: no preview link for ' . $uuid . ': ' . $e->getMessage(), 'crelish');
      return '';
    }

    if ($url === null) {
      return '';
    }

    $href = Html::encode($url);
    $previewTitle = Html::encode(Yii::t('crelish', 'Open preview (valid for {hours} h)', ['hours' => round(self::ttl() / 3600, 1)]));
    $copyTitle = Html::encode(Yii::t('crelish', 'Copy preview link'));
    $copied = Html::encode(Yii::t('crelish', 'Preview link copied'));

    self::registerCopyScript();

    return '<span class="c-input-group crelish-preview-buttons">'
      . '<a class="c-button btn-preview" href="' . $href . '" target="_blank" rel="noopener noreferrer" title="' . $previewTitle . '">'
      . '<i class="fa-sharp fa-regular fa-eye"></i> ' . Html::encode(Yii::t('crelish', 'Preview'))
      . '</a>'
      . '<button type="button" class="c-button btn-copy-preview" data-preview-url="' . $href . '" data-copied="' . $copied . '"'
      . ' title="' . $copyTitle . '" aria-label="' . $copyTitle . '">'
      . '<i class="fa-sharp fa-regular fa-link"></i>'
      . '</button>'
      . '</span>';
  }

  private static function registerCopyScript(): void
  {
    if (!Yii::$app->has('view')) {
      return;
    }

    $js = <<<JS
      document.addEventListener('click', function (event) {
        var button = event.target.closest('.btn-copy-preview');
        if (!button) {
          return;
        }
        var url = button.getAttribute('data-preview-url');
        var done = function () {
          var icon = button.querySelector('i');
          var title = button.getAttribute('title');
          icon.className = 'fa-sharp fa-regular fa-check';
          button.setAttribute('title', button.getAttribute('data-copied'));
          setTimeout(function () {
            icon.className = 'fa-sharp fa-regular fa-link';
            button.setAttribute('title', title);
          }, 2000);
        };
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(url).then(done, function () {
            window.prompt(button.getAttribute('title'), url);
          });
        } else {
          window.prompt(button.getAttribute('title'), url);
        }
      });
      JS;

    Yii::$app->view->registerJs($js, View::POS_END, 'crelish-preview-copy');
  }

  /**
   * Keep the preview out of search engines, caches and analytics, and mark it with a banner.
   */
  public static function registerPreviewMode(View $view, Response $response): void
  {
    // Preview visits are not analytics: the URL (and a referer to it) carries the token
    if (Yii::$app->has('crelishAnalytics')) {
      Yii::$app->get('crelishAnalytics')->enabled = false;
    }

    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    $response->headers->set('Cache-Control', 'no-store, private');
    // Links and requests from the preview must not pass the tokenised URL on as referer
    $response->headers->set('Referrer-Policy', 'no-referrer');
    $view->registerMetaTag(['name' => 'robots', 'content' => 'noindex, nofollow'], 'robots');
    $view->registerMetaTag(['name' => 'referrer', 'content' => 'no-referrer'], 'referrer');

    $view->on(View::EVENT_BEGIN_BODY, static function (): void {
      echo self::bannerHtml();
    });
  }

  public static function bannerHtml(): string
  {
    $label = Html::encode(Yii::t('crelish', 'Preview – this page is not published'));
    $close = Html::encode(Yii::t('crelish', 'Close'));

    return '<div class="crelish-preview-banner" role="status" style="position:sticky;top:0;left:0;right:0;z-index:2147483647;'
      . 'display:flex;align-items:center;justify-content:center;gap:12px;margin:0;padding:6px 40px;'
      . 'background:#222;color:#fff;font:600 14px/1.4 system-ui,-apple-system,sans-serif;text-align:center;'
      . 'box-shadow:0 1px 4px rgba(0,0,0,.3);">'
      . '<span>' . $label . '</span>'
      . '<button type="button" onclick="this.parentNode.remove()" aria-label="' . $close . '" title="' . $close . '" '
      . 'style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:0;'
      . 'color:inherit;font:inherit;font-size:20px;line-height:1;cursor:pointer;padding:0 6px;">&times;</button>'
      . '</div>';
  }

  public static function ttl(): int
  {
    $ttl = Yii::$app->params['crelish']['previewTtl'] ?? null;

    return is_numeric($ttl) && (int)$ttl > 0 ? (int)$ttl : self::DEFAULT_TTL;
  }

  private static function key(): ?string
  {
    $secret = Yii::$app->params['crelish']['previewSecret'] ?? null;

    if (is_string($secret) && strlen($secret) >= self::MIN_SECRET_LENGTH) {
      return $secret;
    }

    // Set but unusable: ignore it (fall back to the cookie key), warn once per application
    if ($secret !== null && $secret !== '' && self::$warnedApp !== spl_object_id(Yii::$app)) {
      self::$warnedApp = spl_object_id(Yii::$app);
      Yii::warning('Page preview: params[crelish][previewSecret] is ignored, it must be a string of at least '
        . self::MIN_SECRET_LENGTH . ' characters; using request.cookieValidationKey.', 'crelish');
    }

    $request = Yii::$app->has('request') ? Yii::$app->getRequest() : null;
    $cookieKey = $request instanceof \yii\web\Request ? $request->cookieValidationKey : null;

    return is_string($cookieKey) && $cookieKey !== '' ? $cookieKey : null;
  }

  private static function attribute(object $record, string $name): mixed
  {
    if ($record instanceof BaseActiveRecord) {
      return $record->hasAttribute($name) ? $record->getAttribute($name) : null;
    }

    return $record->$name ?? null;
  }
}
