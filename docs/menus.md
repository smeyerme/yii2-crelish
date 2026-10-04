# Menus

Crelish manages navigation menus in the backend (**Navigation** in the sidebar).
Each menu has a key that themes use to render it; editors build the tree by
drag and drop.

## Concepts

- **Menu**: title, key (`main`, `footer`, … — lowercase, digits, `-`, `_`; fixed after creation), maximum depth (1–5), online/offline.
- **Item**: label (translatable), target, *open in new window*, online/offline.
- **Targets**
  - *Content*: any crelish record the resolver can link (pages, types listed in `detailPages`, models implementing `UrlTargetInterface`). Resolved on every render, so slug and title changes never break a menu.
  - *URL*: `https://…`, `http://…`, `mailto:…`, `tel:…`, `/relative/path` or `#anchor`.
  - *No link*: a group heading; hidden when it has no visible children.
- **Labels**: an empty label falls back to the target's `navtitle`, then `systitle`. Translations use the crelish i18n (`translation` table); a missing translation falls back to the default-language label.
- Offline items, and content items whose target is deleted or unpublished, are hidden together with their children. The admin flags them as *Target unavailable*.

## Rendering in a theme

```twig
<ul>
  {% for item in chelper.menu('main') %}
    <li class="{{ item.children ? 'has-dropdown' }}">
      {% if item.url %}
        <a href="{{ item.url }}"
           {% if item.active %}aria-current="page"{% endif %}
           class="{{ item.active or item.activeTrail ? 'active' }}"
           {% if item.newWindow %}target="_blank" rel="noopener"{% endif %}>{{ item.label }}</a>
      {% else %}
        <span>{{ item.label }}</span>
      {% endif %}
      {% if item.children %}
        <ul>{% for child in item.children %}…{% endfor %}</ul>
      {% endif %}
    </li>
  {% endfor %}
</ul>
```

Always print `item.label` with auto-escaping (never `|raw`).

### Node fields

| Field | Meaning |
|---|---|
| `uuid` | item uuid |
| `label` | label in the current language |
| `url` | URL, `null` for *No link* |
| `type` | `content`, `url` or `none` |
| `targetUuid` | uuid of the linked record (content items) |
| `external` | absolute URL to another host than the current request's (set per request, never cached) |
| `newWindow` | editor chose *open in new window* |
| `active` | this item leads to the current page |
| `activeTrail` | a descendant is active |
| `children` | child nodes |

### Active state

An item is `active` when it links the record being rendered (`app.params.content.uuid`), or when its URL is the longest path prefix of the current URL on this host (so a news article under `/de/news/…` highlights *News*). `/` and `/<lang>` only match exactly. Ancestors of active items get `activeTrail`.

An unknown key or any error renders an empty menu and logs to category `crelish.menu`.

## Caching

Trees are cached per menu key and full locale, i.e. `Yii::$app->language`
such as `de-CH` (key `crelish.menu.<key>.<locale>`, tag `crelish.menu`, 1 h),
because item labels are translated by the full locale. Content URLs are
built with the two-letter language code (`/de/…`) for every locale. The
cached tree (`MenuService::tree()`) holds only request-independent data:
`external`, `active` and `activeTrail` are `false` there and are set by
`MenuService::get()` (what `chelper.menu()` returns) for each request, so
one cache entry serves every host and a cache warmed from the console is
correct. The cache is cleared when a menu or item is saved and whenever
crelish content is saved or deleted through the admin. Menu and item writes
inside a transaction (the tree editor's save, a menu delete) clear it only
after the outermost transaction commits, so a concurrent request cannot
re-cache the old tree; a rollback clears it too, since a read inside the
transaction may have cached uncommitted data. Content written
elsewhere (imports, direct ActiveRecord writes) shows up at the latest after
an hour, or call
`giantbits\crelish\components\menus\MenuService::invalidate()`.

## Custom URLs for content types

Content types with non-standard frontend URLs implement
`giantbits\crelish\components\UrlTargetInterface`:

```php
public function getTargetUrl(?string $language): ?string
{
    return '/' . $language . '/seminare/' . $this->slug;
}
```

Detail pages that follow `/<listing>/<uuid>/<slug>` only need config:

```php
'crelish' => [
    'detailPages' => ['news' => 'news', 'event' => 'veranstaltungen'],
],
```

(`shortLinks.detailPages` is still read as a fallback.)

## Database

Crelish migrations create `menu` and `menu_item` (and `translation` if the
project lacks it): `php yii crelish-migrate`.
