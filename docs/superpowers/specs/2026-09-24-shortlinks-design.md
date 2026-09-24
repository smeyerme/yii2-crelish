# Short Links — Design

Date: 2026-09-24
Status: Draft for review
Target release: crelish 0.22.0
First consumer: crelish.forum-holzbau

## 1. Purpose

Editors create short links for campaigns and print material (flyers, posters,
newsletters, social posts). Every link can be exported as a print-quality QR
code, and every hit is tracked so editors see how a campaign performs and
whether people came via QR scan or via typed/clicked link.

Short links are a built-in crelish feature, enabled per project by config.

### Decisions taken during brainstorming

| Topic | Decision |
|---|---|
| Use case | Campaign/print links with click tracking and QR export |
| Codes | Auto-generated, optionally replaced by a memorable custom slug |
| Domain | Site's own domain with prefix by default (`/go/<code>`), optional dedicated short host |
| Tracking | Integrated into existing crelish analytics (`analytics_element_views` / sessions / nightly aggregation) |
| Targets | External URLs and crelish records; records resolved at redirect time |
| Dead links | Never 404: redirect to link fallback, then site-wide fallback; hit still tracked |
| QR export | Vector + raster, plain and (if a logo is set) logo variant, always both as one bundle |
| Architecture | Built-in entity following the Bulletin/Translation pattern (ActiveRecord + crelish migration + dedicated controllers), not a JSON content type and not a separate package |

## 2. Data model

Table `shortlink`, created by a crelish migration (`./yii crelish-migrate`).
Standard crelish columns are used so existing tooling (`systitle`, `state`,
`ElementTitleResolver`, list views) works unchanged.

| column | type | notes |
|---|---|---|
| `uuid` | string(36) PK | also `element_uuid` in analytics |
| `created`, `updated` | int NULL | unix timestamps (crelish convention) |
| `created_by`, `updated_by` | string(36) NULL | |
| `state` | smallint NOT NULL DEFAULT 1 | 0 Offline, 1 Draft, 2 Online, 3 Archived. Only 2 redirects to the target. The admin form pre-selects 2 for new links. |
| `systitle` | string(255) NOT NULL | admin label |
| `code` | string(64) NOT NULL, UNIQUE | stored lowercase |
| `target_type` | string(16) NOT NULL | `url` or `content` |
| `target_url` | text NULL | required when `target_type = url` |
| `target_ctype` | string(64) NULL | required when `target_type = content` |
| `target_uuid` | string(36) NULL | required when `target_type = content` |
| `target_language` | string(8) NULL | null = browser language if supported by the site, else site default |
| `fallback_url` | text NULL | null = site-wide fallback |
| `valid_from` | int NULL | unix timestamp |
| `valid_until` | int NULL | unix timestamp |
| `note` | text NULL | internal comment |
| `logo_asset_uuid` | string(36) NULL | overrides site-wide QR logo |
| `qr_size_mm` | smallint NOT NULL DEFAULT 30 | |
| `qr_color` | string(7) NOT NULL DEFAULT '#000000' | foreground colour |
| `qr_quiet_zone` | smallint NOT NULL DEFAULT 4 | in modules; 4 is the spec minimum and the lower bound in validation |

Indexes: unique on `code`, index on `state`, index on (`target_ctype`, `target_uuid`).

### Code rules

- Generated codes: 6 characters from `23456789abcdefghjkmnpqrstuvwxyz`
  (no `0/o`, `1/l/i`), regenerated on collision.
- Custom slugs: `^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$`, minimum 2 characters.
- Input is lowercased before validation and storage; lookup is case-insensitive.
- Must not be in the reserved list: built-in (`crelish`, `api`, `crelish-api`,
  `site`, `sitemap`, `robots`, `favicon`, `assets`, `q`, `document`) plus
  `reservedCodes` from config.
- `target_url` and `fallback_url` must be absolute `http`/`https` URLs.

## 3. Routing

`ShortLinkUrlRule` (a `yii\web\UrlRuleInterface`) is registered before
`CrelishBaseUrlRule`, only when the feature is enabled.

- Main host: `/<prefix>/<code>` and `/<prefix>/<code>/q` (prefix default `go`).
- Short host (if `shortHost` is configured and the request host matches):
  `/<code>` and `/<code>/q`. Any other path on the short host redirects (302)
  to the main site homepage.
- Matching is case-insensitive for prefix, code and the `q` marker.
- `/q` marks a QR scan; without it the hit is a `click`.
- The rule only parses; it does not create URLs for other routes.
  `ShortLink::getShortUrl(bool $qr = false)` builds the canonical short URL
  (short host if configured, else absolute main-host URL with prefix).

### QR payload

The QR code encodes the short URL **uppercased** with the scan marker, e.g.
`HTTPS://FORUM-HOLZBAU.COM/GO/IHF26/Q`. Uppercase letters, digits and `/:.-`
fit the QR alphanumeric mode, which produces a less dense code than byte mode.
Hosts are case-insensitive and the rule matches case-insensitively, so the URL
resolves identically.

## 4. Redirect flow

`ShortLinkRedirectController::actionIndex` (public, no auth, no CSRF):

1. Look up link by lowercase `code`.
2. Unknown code → site-wide fallback, no tracking, log `info` (category `shortlink`).
3. `ShortLinkResolver::resolve(ShortLink, ?string $lang): ResolveResult` returns
   the destination and whether it is the real target or a fallback:
   - `state != 2`, or now < `valid_from`, or now > `valid_until` → fallback.
   - `target_type = url` → `target_url`.
   - `target_type = content` → internal resolution (below); unresolvable → fallback
     plus a `warning` (category `shortlink`), deduplicated to once per link per day
     via cache (Sentry picks up warnings).
   - Fallback = link `fallback_url`, else config `fallbackUrl`, else homepage
     (`CrelishBaseHelper::urlFromSlug(entryPoint.slug)`).
4. Track (section 6). Tracking never prevents the redirect.
5. Respond `302` with `Cache-Control: no-store` and `X-Robots-Tag: noindex`.

Any DB exception during lookup (e.g. migration not run) → site-wide fallback,
log `error`.

### Internal target resolution

Evaluated in order; first match wins:

1. The record's model implements `ShortLinkTargetInterface::getShortLinkUrl(?string $lang): ?string`.
2. `target_ctype` is listed in config `detailPages` →
   `urlFromSlug($detailPages[$ctype], [], $lang) . '/' . $uuid . '/' . slugify($systitle)`,
   using the same slugify as the Twig `slugify` filter. This matches the existing
   detail-link convention in project widgets (e.g. `urlFromSlug('news')/uuid/slug`).
3. The record has a `slug` attribute → `urlFromSlug($slug, [], $lang)`.

A record counts as unresolvable when it does not exist, has `state != 2`, or
has `from`/`to` attributes and now is outside that window.

Language: `target_language` if set; otherwise the best match of the browser's
`Accept-Language` among `params.crelish.languages`; otherwise the app default.

### Security

- The destination comes only from stored records. No request parameter can
  influence it (no open redirect; see the history of `/crelish/track/click`).
- URL fields are validated as absolute `http`/`https`; `javascript:`, `data:`
  and relative URLs are rejected on save.
- No rate limiting on the redirect; repeat hits are handled by unique-session counts.

## 5. Configuration

In `params['crelish']['shortLinks']`:

```php
'shortLinks' => [
  'enabled'       => false,     // default off
  'prefix'        => 'go',
  'shortHost'     => null,      // e.g. 'fhb.link'; requires DNS + vhost to same docroot
  'siteUrl'       => null,      // absolute main-site URL; null = current request host; required with shortHost
  'fallbackUrl'   => null,      // null = homepage
  'detailPages'   => [],        // ctype => listing page slug
  'qrLogo'        => null,      // alias/path to a PNG, JPEG or SVG
  'reservedCodes' => [],        // merged with the built-in list
],
```

When `enabled` is false, no URL rule, sidebar item or admin/public route is registered.

## 6. Analytics integration

New public method on `CrelishAnalyticsComponent`:

```php
public function trackEvent(string $elementUuid, string $elementType, string $type): bool
```

- Returns false without writing when analytics is disabled or the IP is excluded.
  (The static-file and URL-pattern exclusions in `shouldExclude()` don't apply to
  this method; it is only called from controlled endpoints.)
- Ensures a row in `analytics_sessions` exists for the current session:
  inserts one with `ip_address`, `user_agent`, `is_bot` (via `isBot()`),
  `first_url` = the request URL, `first_page_uuid` = `$elementUuid` (the session
  started from this short link) and `total_pages = 0` if missing; upgrades
  `is_bot` if the current request is a bot; **does not** increment `total_pages`.
- Inserts into `analytics_element_views` with `page_uuid = $elementUuid`
  (the column is NOT NULL in production), `user_agent` and `first_url` truncated
  to 255 characters (their production column size).

Why: the nightly aggregation and orphan cleanup only keep element views whose
session exists. A QR scan that redirects off-site has no page view, so without
this the hit would be deleted as an orphan.

Short-link hits: `element_type = 'shortlink'`, `type` =
- `scan`: via `/q` to the real target
- `click`: without `/q` to the real target
- `fallback`: any hit that went to a fallback. The reason is shown from the
  link's current state in the admin.

The nightly aggregation, bot filtering, retention and orphan cleanup work
unchanged. For same-site targets the following page view shares the session
(scan → visit funnel). With a separate short host the session cookie is
scoped to that host, so the funnel is not linked. This is documented.

`shortlink` is registered with `ElementTitleResolver` so links appear by
`systitle` in the existing analytics widgets.

## 7. Admin UI

`ShortLinkController` (admin, standard crelish access control) with Twig views in
`views/shortlink/`. The sidebar item "Short Links" is added by `Bootstrap` when
the feature is enabled, and can be overridden via `workspace/crelish/sidebar.json`.

### List (`index`)

Columns: systitle; short URL + copy button; target (record systitle or shortened
URL); computed status (Online / Offline / Draft / Archived / Scheduled / Expired /
Broken target); scans and clicks in the last 30 days; last hit.
Filters: text (systitle, code), status. "Broken target" is computed with
`ShortLinkResolver` at list time.

### Edit (`create` / `update`)

1. **Link**: systitle, code (pre-filled with a generated code; editable; live
   validation), state, valid_from, valid_until, note.
2. **Target**: radio content / external URL.
   - Content: ctype dropdown limited to types that can be resolved (`page` plus
     `detailPages` keys, plus types whose model implements the interface) →
     record search by systitle (reuse the `relationselect` plugin if it works
     outside the dynamic-model form, else a small AJAX search action) →
     optional target_language.
   - External: target_url.
   - fallback_url (placeholder shows the effective site-wide fallback).
   - "Resolves to" line showing the current effective destination.
3. **QR code** (after first save): size (mm), colour, quiet zone, logo override
   via the `assetconnector` plugin; live SVG preview of plain and logo variants;
   warning when a logo is set and size < 25 mm; "Download ZIP" plus per-file
   downloads. The settings are saved on the link so re-exports produce the same artwork.

### Statistics (tab on edit)

Period picker (`CrelishAnalyticsPeriodPicker`); totals for scans, clicks,
fallback and unique sessions; daily chart of scans vs clicks.
Data: `analytics_element_daily` (`element_type = 'shortlink'`) for past days,
plus today's `analytics_element_views` joined to `analytics_sessions` with
`is_bot = 0`.

## 8. QR export

Libraries: `bacon/bacon-qr-code ^3` for encoding (already used by the portal project),
`setasign/fpdf ^1.8` for PDF; requires `ext-gd` and `ext-zip`. Rendering is our
own code (see section 14).

`QrBundleService::build(ShortLink): string` (path to a temp ZIP):

```
<code>-qr.zip
├── plain/   <code>.svg  <code>.eps  <code>.pdf  <code>.png
├── logo/    <code>.svg  <code>.pdf  <code>.png      (only if a logo is set)
└── README.txt
```

- Payload: uppercase short URL with `/Q` (section 3).
- Plain: error correction M. Logo: error correction H, logo about 20 % of the code width,
  white punch-out background.
- PNG: 300 dpi; pixel size = round(size_mm / 25.4 × 300); DPI metadata set.
- SVG / PDF / EPS: vector at the physical size (PDF page = code + quiet zone in mm).
- EPS has no logo variant (the library's EPS writer cannot embed images); PDF is
  the vector format for print shops that need the logo.
- README: short URL, QR payload, recommended minimum sizes (plain ≥ 15 mm,
  logo ≥ 25 mm), which folder to use for small print.
- Logo unreadable or unsupported → bundle contains `plain/` only; admin shows a
  flash message; README says why.
- Generation failure → flash error; no partial download.

## 9. Error handling summary

| situation | behaviour |
|---|---|
| unknown code | site-wide fallback, `info` log |
| offline / draft / archived / not yet valid / expired | fallback, tracked `fallback` |
| internal target unresolvable | fallback, tracked `fallback`, `warning` once per link per day |
| analytics disabled or failing | redirect still happens; failure logged `error` (category `analytics`) |
| DB/table missing | site-wide fallback, `error` log |
| logo problem | plain-only bundle + notice |
| QR generation failure | admin flash error |

## 10. Testing

Standalone scripts in `tests/`, same style as `ClickTrackingSecurityTest.php`
(`php tests/<Name>.php`), written test-first:

- `ShortLinkCodeTest`: generator alphabet and length, collision retry, custom slug
  rules, reserved words, case-insensitive uniqueness.
- `ShortLinkUrlRuleTest`: prefix route, `/q`, uppercase paths, short host,
  other paths on the short host, no interference with pages or the language prefix.
- `ShortLinkResolverTest`: all state and validity cases, url targets,
  interface / `detailPages` / slug resolution order, unresolvable records,
  language selection, fallback chain.
- `ShortLinkRedirectSecurityTest`: request parameters cannot change the
  destination; unsafe schemes rejected on save; 302 with `no-store` and `noindex`.
- `ShortLinkTrackingTest`: `trackEvent` creates a missing session, leaves
  `total_pages` untouched, upgrades `is_bot`; a tracking exception still redirects.
- `QrBundleTest`: ZIP layout with and without a logo, PNG pixel size, EPS only in
  `plain/`, uppercase payload with `/Q`.

Manual before the first real campaign: print plain and logo variants at 15, 20, 25
and 30 mm and scan with iOS and Android camera apps. This confirms the 25 mm logo
threshold with the real logo.

## 11. Documentation

New `docs/shortlinks.md` (config, target resolution, `ShortLinkTargetInterface`,
QR sizes, short-host setup and the session-cookie caveat), linked from
`docs/README.md`.

## 12. Rollout (forum-holzbau)

1. crelish: feature branch `feature/shortlinks` → git-flow release 0.22.0.
2. Project: raise `giantbits/yii2-crelish` to `^0.22` (`^0.21` does not allow
   0.22), `composer update giantbits/yii2-crelish`, run `./yii crelish-migrate`.
3. Config: `enabled => true`,
   `detailPages => ['news' => 'news', 'constructions' => 'bauten']`, `qrLogo`.
   Events have no detail pages (widgets link to `infourl`), so event links use
   external URLs.
4. Deploy; GD is available on production.
5. Optional later: dedicated short host (DNS + vhost + `shortHost`).

## 13. Out of scope

Automatic short links per content item, UTM forwarding, bulk import/export,
printable label/flyer PDFs, public API, referrer and device columns in analytics.

## 14. Decisions made while planning

These refine the sections above; where they differ, this section wins.

- **QR library.** endroid/qr-code works in whole pixels or points, so an exact
  mm size and a 4-module quiet zone cannot both be met, and its PDF writer
  cannot place a logo with a white knockout. We encode with bacon/bacon-qr-code
  (endroid's own encoder) and draw SVG, EPS, PDF and PNG ourselves from the
  module matrix. Dark modules are merged into runs and filled as a single path
  so viewers and RIPs show no seams.
- **QR size** in mm includes the quiet zone; the file can be placed as-is.
- **Logo** may be PNG, JPEG or SVG (ideally square, >= 600 px). It is flattened
  onto white because FPDF cannot embed alpha channels; SVG logos are rasterised
  with ImageMagick for that flattened PNG/PDF output but kept as vector markup
  for the SVG export (needs the PHP `imagick` extension with SVG support; SVGs
  with `<!DOCTYPE`/`<!ENTITY` are rejected). The knockout is at most 22 % of
  the code width, and its height follows the logo's aspect ratio. The admin
  reports when a configured logo can't be used instead of showing a broken
  preview and dead download links.
- **Analytics rows** use `page_uuid = link uuid` (NOT NULL column in production);
  `user_agent`/`first_url` are truncated to 255 characters.
- **`siteUrl` config key** makes resolved content URLs and the home fallback
  absolute on the main site, which a dedicated short host requires.
- **Public redirect** lives in its own `ShortLinkRedirectController`
  (plain `yii\web\Controller`): `CrelishBaseController::init()` redirects every
  non-admin visitor.
- **Target picker** is a small AJAX search (`short-link/targets`); the
  relationselect plugin needs a fixed ctype in its field config.
- **Sidebar item** is a regular `config/sidebar.json` entry with
  `"condition": "shortlinks"`, evaluated by `CrelishSidebarManager`.
- **List view** has no bulk delete (the generic one targets the content
  controller); links are deleted from their edit view.
- **forum-holzbau has no square signet** yet (only wide wordmarks), so
  `qrLogo` stays null until one exists.
