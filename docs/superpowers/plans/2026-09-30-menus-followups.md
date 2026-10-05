# Menus follow-ups: triaged fixes

Date: 2026-09-30
Status: A, B and C were released in 0.23.2. D and the "Found while doing A–C" items are implemented on feature/menus-followups-2 (2026-10-01). E1 was fixed in forum-holzbau (SitemapController filters pages by their publication window). E2 is implemented in 0.24.0 (signed preview links). One new Important item is at the top.
Source: deferred findings from the per-task reviews, the final whole-branch review and the browser checks of the menus feature (0.23.0).
Already handled in 0.23.1: unpublished pages now return 404, and crelish's own translations are used, plus the `crelish-translations/prune` command.

Each item names the problem, the fix, the files and the test. Items within a group are ordered by value. The groups are sized for one PR each, as a 0.23.x or 0.24.0 release.

## Fixed in 0.23.4: translated values written into the default column

Found during the review of round 2. This bug predates the menus work and affects every crelish content type that uses `CrelishTranslationBehavior`.

- Problem: the behavior swaps translated values into the model's attributes on `find()`. The admin language comes from the `crelish_admin_lang` cookie (`CrelishBaseController`). If an editor works under an admin language that has translation rows, `CrelishDynamicModel` loads those swapped values into the default-language form fields. `CrelishDbStorage::save()` then `findOne()`s the record, and every translated attribute is dirty against its column, so the translated text is silently written into the default-language column.
- Impact: silent data corruption on multi-language installs. forum-holzbau is not affected today: it has a single language, and its workspace models don't attach the behavior. Menus are not affected either, because `MenuTreeSaver` always writes the label from the payload.
- Direction:
  - Never write behavior-swapped values back. For example, keep the original column values (`getOldAttribute`) and restore them in `beforeSave` for attributes the form did not explicitly post for the default language.
  - Alternatively, load admin edit models with `skipTranslation`.
  - Add a regression test covering both the admin edit path and the storage save path.
  - The fix must also cover values loaded through the 0.23.3 locale fallback (`en-US` → `en`). The regression test should include a full-locale admin language.


## Group A: Save and cache correctness (recommended, ~half a day)

**A1. Atomic conflict check in the tree save.**
- Problem: `MenuTreeSaver::save()` compares `updated` outside the transaction and takes no lock. Two saves that arrive within milliseconds with the same token both pass, and the last one wins silently.
- Fix: inside the transaction, run `UPDATE menu SET updated = :new WHERE uuid = :uuid AND updated = :old`. If it affects 0 rows, roll back and return 409. Drop the pre-check, or keep it only as a fast path.
- Files: `components/menus/MenuTreeSaver.php`.
- Test: `MenuSaveTest`. Simulate a concurrent bump by changing `updated` between validation and write, for example through a subclass hook or a trigger. Expect 409 and nothing written.

**A2. `updated` only ever moves forward.**
- Problem: the saver bumps `updated` to `max(time(), old + 1)`. A later settings save through `TimestampBehavior` writes `time()`, which can be lower. In a narrow window an old token then matches again.
- Fix: in `Menu::beforeSave` on update, set `updated = max(time(), (int)$this->getOldAttribute('updated') + 1)`, overriding the behaviour's value. Share one helper with the saver.
- Files: `models/Menu.php`, `components/menus/MenuTreeSaver.php`.
- Test: `MenuModelTest`. A save right after a saver bump still increases `updated`.

**A3. Validate `i18n` values (422, not 500).**
- Problem: an array or number in `i18n` reaches `trim((string)…)` inside the transaction, causes an "Array to string conversion" and a 500. Translation length is not checked either.
- Fix: in `MenuTreeSaver::validate()`, every `i18n` value must be a string of at most 255 characters, and only configured languages are accepted.
- Test: `MenuSaveTest`. Array value → 422; 256 characters → 422; unknown language key is ignored or rejected (decide and pin it).

**A4. Cache key and translation language agree.**
- Problem: the cache key uses the two-letter language (`currentLanguage()`), while `CrelishTranslationBehavior` loads by the full `Yii::$app->language`. `de-DE` and `de-CH` share a cache entry but can load different labels.
- Fix: key the cache on the full `Yii::$app->language`, or normalise both to the same code. Pick one and document it in `docs/menus.md`.
- Files: `components/menus/MenuService.php`.
- Test: `MenuServiceTest`. Two full locales produce two cache entries.

**A5. `external` computed per request, not cached.**
- Problem: `external` depends on the request host but is cached with the tree. With several hosts, or when the cache is warmed from the console, the flag is wrong.
- Fix: cache without `external` and set it in `MenuService::get()`, next to the active state (`MenuActiveState` or a small sibling).
- Test: `MenuActiveStateTest`/`MenuServiceTest`. The same cached tree gives different `external` values for different hosts.

## Group B: Editor robustness (recommended, ~half a day incl. rebuild and browser check)

**B1. Failed initial load shows a message.** In `MenuEditor.vue`, a network failure in `mounted()` rejects `Promise.all` without being caught, and the editor stays blank. Wrap it in try/catch and flash `labels.failed` with a reload button.

**B2. Stale search results cannot land.** In `TargetPicker.vue`, the debounce timer is not cleared on `clearTarget`, on unmount or on item switch, so a late response can fill results for the wrong item or content type. Clear the timer in `clearTarget`/`beforeUnmount`, and drop responses whose `q`/`ctype` no longer match (compare a request counter).

**B3. Errors clear when the row is fixed or deleted.** `errors[key]` persists until the next save, so rows stay red after correction. Delete `errors[key]` when that node changes or is removed.

**B4. Show general errors.** An error keyed `_` (an item with neither `uuid` nor `clientId`) has no row. Show `errors._` in the toolbar status line.

**B5. Smaller bundle.** `webpack.config.js` aliases `vue` to `vue.esm-bundler.js`, which includes the template compiler. The SFC-only code needs the runtime build (`vue.runtime.esm-bundler.js`), which cuts roughly 100 KB of the 306 KB.

Verification: `npm run build`, then a browser check of each item in light and dark mode.

## Group C: Tests that pin behaviour we rely on (recommended, ~2 hours)

**C1. Foreign keys enforced in the SQLite harness.** Run `PRAGMA foreign_keys = ON` in `tests/menu/bootstrap.php` (the `afterOpen` hook), so the parent-before-child write order and the delete cascades are exercised as on MySQL.

**C2. Missing save cases.** Add to `MenuSaveTest`:
- duplicate item id → 422
- exact 409 and 500 response bodies
- cache invalidated after a successful save

**C3. Translation behaviour test that proves "consumed once".** Replace the fresh-instance check in `TranslationBehaviorTest` with a second save on the same instance after restoring the row.

**C4. Resolver edge cases.** Add to `ContentUrlResolverTest`:
- a record implementing both interfaces (`UrlTargetInterface` wins)
- a record without a state attribute
- `resolve('content', …)` with an empty content type or uuid

**C5. `typeLabel` reads the element JSON label.** Cover the JSON-label path in `ContentTargetSearchTest` with a temporary element file.

## Group D: Small hardening (optional, ~1 hour)

**D1. Empty translation check.** `CrelishTranslationBehavior::writeTranslations` treats `"0"` as empty and deletes it. Use `$value === '' || $value === null`.

**D2. Menu deletion in a transaction.** `Menu::beforeDelete` should delete translations and items in one transaction, so a failure cannot leave partial state on engines without the cascade.

**D3. Key rules on create only.** Scope the `unique`/`match` rules for `key` to insert, so an ignored key change on update cannot produce a confusing validation error.

**D4. Keyboard-accessible menu list.** In `views/menu/index.twig`, rows navigate via `onclick` only. Make the title cell a real link, keeping the row click as a convenience.

**D5. Singular and plural in the menu list badge.**
- Problem: the badge reads "1 Ziele nicht verfügbar".
- Fix: use an ICU plural message, `{n, plural, one{# target unavailable} other{# targets unavailable}}` with the matching German, in `views/menu/index.twig` and `messages/de/crelish.php`.

## Group E: Investigate (no decision yet)

**E1. Sitemap and other page listings.** Check whether the sitemap, search or page listings include unpublished pages. Since 0.23.1 those return 404, so listing them now produces dead links.

**E2. Editor preview of offline pages.** 0.23.1 returns 404 for everyone, as decided. If editors need previews, add an opt-in, for example a signed preview URL from the page edit view, rather than letting logged-in users bypass the rule.
*Implemented in 0.24.0:* `PagePreview` signs `?preview=` tokens (page uuid + expiry, `previewSecret`/cookie key, `previewTtl`); `CrelishFrontendController` serves an unpublished page with a valid token as a noindex/no-store preview with a banner; the page edit header bar has Preview and Copy preview link buttons. Docs: getting-started.md, "Previewing unpublished pages". Test: tests/PagePreviewTest.php.

## Not worth fixing (and why)

- **Long docblock line in `ShortLinkResolver`; log category moved from `shortlink` to `crelish`:** cosmetic or intended.
- **Foreign keys auto-named `1`/`2` on the MySQL server:** renaming needs a migration on live data for zero functional gain.
- **`MenuController::behaviors()` replaces the parent's (drops `botDetection`):** identical to every sibling admin controller, and admin routes require login. If it changes, change it crelish-wide.
- **`ContentTargetController` extends `yii\web\Controller`:** plan-prescribed JSON endpoints that need no admin layout.
- **`typeLabel` reads the JSON file per call:** admin-only and a few files.
- **A uuid match and a prefix match can mark two items active:** by the spec (uuid OR longest prefix). Revisit only if a theme complains.
- **Per-item translation queries in `MenuAdminTree` and N+1 queries in the menu index:** menus have tens of items, and this is admin-only.
- **One-shot `ignoreNextChange` flag in the editor:** works as intended. A snapshot comparison would be nicer, but there is no observed bug.
- **`beforeunload` prompt not browser-tested:** the code is trivial and browser dialogs block automation.
- **Role gate (login-only):** the user decided to keep it consistent with the rest of crelish.

## Found while doing A–C (implemented in round 2, see feature/menus-followups-2)

Collected from the group reviews and the final review. None blocks a release.

- **Content types fail to load silently.** A non-OK response from `content-target/types` leaves the type select empty until reload. Show the load-failure message with Reload, as B1 does for the tree.
- **A settings save can move `updated` backwards.** The settings form computes `nextUpdated()` from the in-memory old value. A tree save between `findOne()` and `save()` can lower `updated` again. The window is milliseconds. Fix by basing it on the current row value, or on `GREATEST(updated + 1, :now)` in SQL.
- **A non-array `i18n` payload wipes translations.** A hand-crafted payload with a non-array `i18n` deletes the item's translations instead of returning 422. The editor always sends an object. Reject a present non-array `i18n` with 422.
- **Editor state is not tidied.** `errorSignatures` is not reset on a successful save or reload, and `errors._` stays until the next save. Both are bounded and invisible to users.
- **Changing the content type keeps the search term.** It clears the results but neither clears the query nor searches again.
- **Translations can be stored and looked up under different language codes (pre-existing).** `CrelishTranslationBehavior` stores editor translations under two-letter codes but looks them up by the full `Yii::$app->language`. Only sites running full locales such as `en-US` are affected. forum-holzbau uses `de`.

## Fixed in 0.23.5 (i18n)

- **The JSON editor widgets edited in the admin UI language.** `JsonEditor` and `JsonEditorNew` now take their language from the form key the admin form passes (`CrelishBaseHelper::formFieldLanguage()`): the main field posts the default content language column, each other language posts `i18n[<lang>]` with its stored translation, and the form group carries `data-language` (translations also `lang-ver`) like the core fields. Without language fields (single language, not translatable) the output is unchanged. Test: `tests/JsonEditorLanguageTest.php`.
- **Auto-translate used the wrong source language.** The button, its script, `CrelishTranslationService::shouldOfferTranslation()` and the service's default source now use the default content language instead of `Yii::$app->sourceLanguage`. Message translation (`CrelishI18nEventHandler`) still uses `sourceLanguage`. Test: `tests/AdminContentLanguageTest.php`.
- **A full locale in the language list was not handled.** `CrelishBaseHelper::isDefaultContentLanguage()` compares two-letter codes on both sides; the behavior's default-language skip, the admin `isTranslation()` and the auto-translate check use it. Tests: `tests/AdminContentLanguageTest.php`, `tests/TranslationBehaviorTest.php`.
- **Stale default-language rows were not cleaned up.** `php yii crelish-translations/stale-defaults` lists translation rows stored for the default content language per `source_model` (dry run); `--apply` deletes them. Test: `tests/TranslationStaleDefaultsTest.php`.
- **Locale fallback per record, not per field.** `loadTranslations()` now starts from the two-letter rows keyed by attribute and overlays the exact-locale rows, still in one query. Test: `tests/TranslationBehaviorTest.php`.

## Fixed in 0.23.6

- **Menu cache cleared inside the transaction.** Menu and item hooks call `MenuService::invalidateAfterCommit()`: without an open transaction it invalidates at once; inside one it registers a handler on `Connection::EVENT_COMMIT_TRANSACTION` (fired only when the outermost transaction commits), once per transaction; a rollback invalidates too (a read inside the transaction may have cached uncommitted data). Covers the tree editor's save, `Menu::delete()` (AR transaction) and any caller's own transaction. `MenuTreeSaver` still invalidates after its commit. Test: `tests/MenuServiceTest.php` ("Invalidation after commit").
- **`required` on menu `key` applied on update.** The required rule for `key` now has `when => isNewRecord` like the other key rules; `systitle` stays required. Test: `tests/MenuModelTest.php`.
- **The Form.io JSON editor edited in the admin UI language.** `FormioJsonEditor` uses `CrelishBaseHelper::formFieldLanguage()` like `JsonEditor`/`JsonEditorNew`: main field = default content language column, `i18n[<lang>]` per translation, `data-language`/`lang-ver` on the form group, suffixed editor ids. Output without language fields is byte-identical. Test: `tests/JsonEditorLanguageTest.php`.
- **Translation fields prefilled with the default value.** Text inputs and textareas without a stored translation render empty with the default as placeholder (max. 120 characters, a placeholder configured on the field wins; type compared case-insensitively) (`CrelishBaseController::handleTranslationOptions()`). Every other core type keeps the default preselected, since an empty select would post its first option; the unchanged default is then not stored. On the form POST path, `CrelishTranslationBehavior` stores no translation identical (after trim) to the saved default column value and deletes an existing row instead; `setTranslations()` is unchanged. Tests: `tests/AdminContentLanguageTest.php`, `tests/TranslationBehaviorTest.php`.

## Open after 0.23.6 (i18n)

- **Plugin widgets and JSON editors still show the default value in translation tabs.** `buildCustomOrDefaultField()`/`buildWidgetField()` pass the column value as `data` for translation fields, and the three JSON editors fall back to it. Rendering them empty is not safe as is: an empty JSON editor posts its schema default (`[]`, `{}` or default-filled objects), which would be stored as a translation and blank out the default. Needs a decision (e.g. treat empty/schema-default JSON as "no translation" on POST). The POST identical-value check already drops untouched copies that come back byte-identical.
