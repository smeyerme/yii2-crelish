# Menus — Design

Date: 2026-09-29
Status: Draft for review
Target release: crelish 0.23.0
First consumer: crelish.forum-holzbau

## 1. Purpose

Almost every crelish site needs navigation, and today every theme hardcodes it
(URLs, labels, active-state checks). Editors cannot add or reorder entries
without a developer and a deploy.

Menus become a built-in crelish feature: editors manage any number of named
menus as trees in the backend, items point at crelish content (resolved to a
URL at render time, so slug changes never break a menu) or at plain URLs, and
themes render a resolved tree with their own markup.

### Decisions taken during brainstorming

| Topic | Decision |
|---|---|
| Editors | Clients/editors manage menus themselves: drag & drop tree editor with guard-rails |
| Architecture | Built-in entity following the shortlinks pattern (ActiveRecord + crelish migration + dedicated controller + Vue bundle), not a JSON content type |
| Storage | Two tables, `menu` and `menu_item`; adjacency list (`parent_uuid` + `sort`), no nested-set library |
| Languages | One tree for all languages; item labels translatable through the existing crelish i18n (`translation` table, `CrelishTranslationBehavior`) |
| Targets | `content` (ctype + uuid), `url`, or `none` (non-linked group heading) |
| Labels | Optional; empty label falls back to target `navtitle`, then `systitle` |
| URL resolution | Generic `ContentUrlResolver` extracted from `ShortLinkResolver`; shortlinks delegate to it |
| Interface | `ShortLinkTargetInterface` renamed to `UrlTargetInterface`; old name kept as deprecated alias |
| Active state | Exact content uuid match or longest URL-prefix match; ancestors get `activeTrail` |
| Caching | Resolved tree per menu key + language, one `TagDependency` tag invalidated by menu saves and any content save/delete; 1 h safety TTL |
| Saving | Whole tree saved in one request inside a transaction; optimistic concurrency via menu `updated` → 409 |
| Enablement | Always available (no config flag); sidebar entry unconditional |

### Non-goals (v1)

- Per-item target language (menus follow the request language)
- Scheduling windows on items
- Per-item CSS classes, icons, or role/visibility rules (theme concerns)
- Auto-generated menus from a page tree (pages have no parent relation)
- Porting the shortlinks edit form to the new picker component (optional later step)

## 2. Data model

New crelish migration `m260929_120000_create_menu_tables` (namespace
`giantbits\crelish\migrations`), picked up by the existing migration
registration in `Bootstrap.php`.
The migration creates the `translation` table when it does not exist.

### 2.1 `menu`

| Column | Type | Notes |
|---|---|---|
| `uuid` | string(36) PK | crelish convention |
| `key` | string(64), unique, not null | Theme lookup key (`main`, `footer`, `meta`); `[a-z0-9_-]+`; immutable after create |
| `systitle` | string(128), not null | Admin name |
| `max_depth` | smallint, not null, default 2 | Enforced in editor and on save; allowed 1–5 |
| `state` | smallint, default 2 | crelish state convention |
| `created`, `updated` | int | `TimestampBehavior` |
| `created_by`, `updated_by` | string(36), nullable | `BlameableBehavior` |

### 2.2 `menu_item`

| Column | Type | Notes |
|---|---|---|
| `uuid` | string(36) PK | |
| `menu_uuid` | string(36), not null | FK → `menu.uuid`, `ON DELETE CASCADE` |
| `parent_uuid` | string(36), nullable | FK → `menu_item.uuid`, `ON DELETE CASCADE` |
| `sort` | int, not null, default 0 | Order among siblings |
| `label` | string(255), nullable | Default-language label; translatable (§2.3) |
| `target_type` | string(16), not null | `content` \| `url` \| `none` |
| `target_ctype` | string(64), nullable | Required for `content` |
| `target_uuid` | string(36), nullable | Required for `content` |
| `target_url` | text, nullable | Required for `url` |
| `new_window` | boolean, not null, default false | Editor pre-checks it for external URLs |
| `state` | smallint, default 2 | Online/offline |
| `created`, `updated`, `created_by`, `updated_by` | | as `menu` |

Indexes: `idx-menu_item-tree` (`menu_uuid`, `parent_uuid`, `sort`),
`idx-menu_item-target` (`target_ctype`, `target_uuid`).

Models: `models/Menu.php`, `models/MenuItem.php` (`yii\db\ActiveRecord`, same
behaviors as `ShortLink`). `MenuItem` additionally attaches
`CrelishTranslationBehavior`; `label` is its only translatable attribute.

Broken targets (content deleted or unpublished) stay in the database. They are
skipped on the frontend and flagged in the admin.

### 2.3 Translations

Labels use the existing mechanism: rows in `translation` with
`source_model = 'menu_item'`, `source_model_attribute = 'label'`.

`CrelishTranslationBehavior` currently reads translations only from
`POST['CrelishDynamicModel']['i18n']` on save. It gains:

```php
public function setTranslations(array $byLanguage): void // ['en' => ['label' => '…'], …]
```

When set, `saveTranslations()` uses those values instead of the POST data; the
existing form path is unchanged. An empty value for a language deletes that
language's translation row (so editors can clear a translation), which is only
applied on the `setTranslations()` path to keep the form path unchanged.

`loadTranslations()` already swaps in the current-language value on find.

## 3. URL resolution

### 3.1 `ContentUrlResolver`

New `components/ContentUrlResolver.php`, extracted from
`components/shortlinks/ShortLinkResolver.php`:

```php
public function resolve(string $type, ?string $ctype, ?string $uuid, ?string $url, ?string $language): ?string
public function resolveLabel(string $ctype, string $uuid): ?string   // navtitle → systitle
public function resolvableTypes(): array
public function canResolveType(string $ctype): bool
```

- `url` → returns `$url`; `none` → `null`.
- `content` → loads the record via `CrelishModelResolver`, applies the existing
  published/state and from/to window checks, then tries in order:
  1. record implements `UrlTargetInterface` → `getTargetUrl($language)`
  2. `detailPages[ctype]` config → `urlFromSlug(listingSlug)/<uuid>/<slugified systitle>`
  3. record `slug` → `CrelishBaseHelper::urlFromSlug()`
- Returns `null` when the record is missing, unpublished, or no strategy yields a URL.

`detailPages` is currently read from `params['crelish']['shortLinks']['detailPages']`
(`ShortLinkConfig::detailPages()`). The resolver reads
`params['crelish']['detailPages']` first and falls back to the shortlinks key,
so existing project config keeps working and menus work on sites with
shortlinks disabled. `ShortLinkConfig::detailPages()` delegates to the resolver.

### 3.2 Interface rename

`components/shortlinks/ShortLinkTargetInterface.php` becomes
`components/UrlTargetInterface.php` with `getTargetUrl(?string $language): ?string`.
The old interface stays unchanged and is marked deprecated; the resolver checks `UrlTargetInterface` first, then `ShortLinkTargetInterface`. Project models keep working without changes.

### 3.3 Shortlinks

`ShortLinkResolver` keeps its public API and becomes a thin wrapper mapping
`target_type/target_ctype/target_uuid/target_url` and its language negotiation
onto `ContentUrlResolver`. Shortlink behavior does not change;
`ShortLinkResolverTest` must pass unchanged apart from the interface name.

## 4. Frontend API

### 4.1 `MenuService`

New `components/menus/MenuService.php`:

```php
public function get(string $key): array
```

Returns the resolved tree for `Yii::$app->language`. Node shape:

```php
[
  'uuid' => string,
  'label' => string,
  'url' => ?string,          // null for type none
  'type' => 'content'|'url'|'none',
  'targetUuid' => ?string,
  'external' => bool,        // absolute URL to another host
  'newWindow' => bool,
  'active' => bool,
  'activeTrail' => bool,     // an ancestor of an active item
  'children' => array,
]
```

Rules:

- Offline items are skipped with their subtree.
- `content` items whose target does not resolve are skipped with their subtree
  (logged with `Yii::warning`, category `crelish.menu`).
- `none` items without children are skipped (an empty heading is useless).
- Label: current-language translation → `label` column → `resolveLabel()`;
  an item with no label at all is skipped and logged.
- Unknown menu key → `[]` + warning. Any exception inside `get()` is caught,
  logged, and returns `[]`. A menu never breaks a page.

### 4.2 Active state

Computed per request on top of the cached tree (never cached):

- `active` when the item's `target_uuid` equals the current content uuid
  (`Yii::$app->params['content']->uuid` when present), or when the current path
  equals the item's URL path or starts with it followed by `/`.
- Prefix matches only count for the longest matching URL in the menu, so a
  `/de` home item does not light up every page. Exact uuid matches always count.
- Only same-host URLs take part in path matching.
- Site roots (`/` and `/<lang>`) only match exactly, never as a prefix.
- Every ancestor of an active item gets `activeTrail = true`.

### 4.3 Twig

`CrelishBaseHelper::menu(string $key): array`, available as
`chelper.menu('main')`:

```twig
{% for item in chelper.menu('main') %}
  <a href="{{ item.url }}" {% if item.active %}aria-current="page"{% endif %}>{{ item.label }}</a>
  {% for child in item.children %}…{% endfor %}
{% endfor %}
```

Labels are editor input and must be printed with normal auto-escaping.

### 4.4 Caching

- Cache key `crelish.menu.<key>.<language>`; value is the resolved tree without
  active flags.
- All entries carry `TagDependency` tag `crelish.menu`, duration 3600 s.
- The tag is invalidated in:
  - `Menu` / `MenuItem` `afterSave` / `afterDelete` (the tree save invalidates once after commit)
  - `CrelishDynamicModel::save()` and `delete()` (via `updateCache()`), since slugs,
    `navtitle` and publish state of any record may affect a menu
  - `MenuTreeSaver` after commit (the only write path for menu labels)
- Content written outside `CrelishDynamicModel` is covered by the TTL.

## 5. Admin

### 5.1 Navigation

`config/sidebar.json` gets an unconditional "Navigation" entry pointing to
`/crelish/menu/index`. Access control matches `ShortLinkController`.

### 5.2 `MenuController`

`controllers/MenuController.php` extends `CrelishBaseController`.

| Action | Purpose |
|---|---|
| `index` | Table of menus: title, key, item count, unavailable-target count |
| `create` / `update` | Menu metadata form (title, key on create only, max depth) |
| `delete` | POST, with confirmation; cascades items |
| `edit` | Renders the tree editor for one menu |
| `tree` (GET) | JSON: menu meta (incl. `updated`), languages, items with translations and admin status (`targetTitle`, `targetAvailable`, `fallbackLabel`) |
| `save` (POST, JSON) | Saves the whole tree (§5.4) |

### 5.3 `ContentTargetController`

Generic picker endpoints reused by menus and shortlinks:

| Action | Response |
|---|---|
| `types` | `[{ctype, label}]` from `ContentUrlResolver::resolvableTypes()` |
| `search?ctype&q` | `[{uuid, title}]`: `systitle LIKE`, min 2 chars, limit 20, `[]` on error |

`ShortLinkController::actionTargets()` delegates to `search`, so the
shortlinks form keeps working unchanged.

### 5.4 Tree save

Request: `{updated: int, items: [{uuid|null, clientId, parentRef, sort, label, i18n, target_type, target_ctype, target_uuid, target_url, new_window, state}]}`,
where `parentRef` is a uuid or the `clientId` of a new item.

1. Stale `updated` (differs from DB) → **409**, nothing written.
2. Validate the full tree before writing; any failure → **422** with
   `{errors: {<uuid|clientId>: [messages]}}`:
   - every parent reference exists in the submitted tree; no cycles
   - depth ≤ `max_depth`
   - `content`: `target_ctype` resolvable and `target_uuid` present
   - `url`: non-empty and scheme in allowlist: `http`, `https`, `mailto`, `tel`,
     relative paths (`/…`), fragments (`#…`); anything else (e.g. `javascript:`) rejected
   - `none`: requires a label
3. In one transaction: diff against stored items by uuid → insert / update /
   delete; save translations via `setTranslations()`; touch `menu.updated`.
   Any exception → rollback, **500**.
4. Invalidate `crelish.menu` once after commit; respond with the fresh tree
   (same shape as `tree`).

### 5.5 Tree editor (Vue)

New bundle `resources/menu-editor` (Vue 3 + `vuedraggable`, webpack,
same setup as `resources/pagebuilder`), registered via an asset bundle used by
the `edit` view.

- Left: nested drag & drop tree. Drops that would exceed `max_depth` are
  refused with a visual hint. Rows show label (fallback label greyed/italic
  with "(from page)"), target summary (content title / URL / "no link"),
  badges *Offline* and *Target unavailable*, and a delete action.
- Right: side panel for the selected item: label per configured language
  (tabs; placeholder shows the fallback label), target radio
  *Content / URL / No link*, content-type select + search picker component,
  URL input, *New window* and *Online* toggles.
- "Add item" appends a top-level item and selects it.
- One "Save" button; `beforeunload` guard while dirty; 409 shows
  "Menu was changed by someone else, reload"; 422 highlights the affected rows
  with their messages.
- UI strings in German and English via crelish messages.

## 6. Testing

Standalone scripts in `tests/`, run with `php tests/<Name>.php`, using the
in-memory SQLite harness pattern of `tests/shortlink/bootstrap.php` with the
real menu migration.

- `ContentUrlResolverTest`: all three strategies incl. old and new interface,
  publication state and window, language, `null` for unavailable targets.
- `ShortLinkResolverTest`: must stay green (only interface name updated).
- `MenuServiceTest`: tree build and sort order; offline and dead-target
  subtrees skipped; empty `none` heading skipped; label fallback chain and
  per-language translations; active state (uuid match, longest prefix,
  external URLs ignored, `activeTrail`); unknown key; cache hit and
  invalidation via menu save, item save, content save/delete.
- `MenuSaveTest`: insert/update/delete diff with new-item `clientId` parents;
  each validation rule → 422; stale `updated` → 409; rollback on failure.
- `TranslationBehaviorTest`: `setTranslations()` saves and clears; POST path
  unchanged.
- Vue editor: manual browser verification (drag, depth limit, save, 409, 422),
  consistent with the other bundles having no JS unit tests.

## 7. Documentation and release

- New `docs/menus.md`: concepts, admin usage, `chelper.menu()`, node shape,
  active-state rules, caching, `UrlTargetInterface`.
- `docs/shortlinks.md` and `docs/twig-reference.md` updated for the resolver
  extraction, interface rename and new helper.
- Work on `feature/menus`, released as 0.23.0 via git-flow.

## 8. forum-holzbau rollout

1. Raise the crelish constraint to `^0.23` in `composer.json`.
2. Project migration seeds menu `main` (max depth 2) from the current
   hardcoded navigation: News, Bauten, Termine (`veranstaltungen`),
   Holzbaupreise (`preise`), Wettbewerbe, FORUM HOLZ (`forum-holz`), Akademie,
   Über uns (`kontakt`) with children Premium-/Partner, Kontakt/Verein, Presse,
   Mediadaten, then Newsletter. Page uuids are looked up by slug; all labels are
   set explicitly (identical to today's navigation). A missing page
   fails the migration with a clear message. `deploy:migrate` runs it on deploy.
3. Deploy runs `yii crelish-migrate` before `deploy:migrate`.
4. `themes/fhbmain/layouts/_navigation.twig`: the `<ul class="nav-entries">`
   block becomes a loop over `chelper.menu('main')`. Classes, ARIA roles and
   dropdown markup stay; `active`/`aria-current` come from `item.active` /
   `item.activeTrail`, `has-dropdown` from `item.children`. The uncommitted
   hardcoded Akademie entry is dropped in favour of the seeded menu.
5. The mobile-only sister-site links and social icons stay hardcoded (possible
   later `meta` menu).
6. Verify on the dev site: every entry links correctly, active state on list
   and detail pages (e.g. a news article highlights "News"), dropdown trail on
   "Über uns" children, then deploy.
