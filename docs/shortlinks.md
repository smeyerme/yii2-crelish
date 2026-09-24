# Short Links

Campaign and print links with QR export and tracking: `https://example.com/go/<code>`.

## Enable

1. Run the migration: `./yii crelish-migrate`
2. Configure `params['crelish']['shortLinks']`:

```php
'shortLinks' => [
  'enabled'       => true,
  'prefix'        => 'go',          // /go/<code>
  'shortHost'     => null,          // e.g. 'fhb.link' (needs DNS + vhost to the same docroot)
  'siteUrl'       => null,          // absolute main-site URL; required with shortHost
  'fallbackUrl'   => null,          // null = home page
  'detailPages'   => ['news' => 'news'],   // ctype => listing page slug
  'qrLogo'        => '@webroot/img/qr-signet.png', // PNG/JPEG, ideally square
  'reservedCodes' => [],
],
```

"Short Links" then appears in the admin sidebar.

**Note:** In crelish ≤ 0.21 the `crelish-migrate` console command may register both `migrationPath` and `migrationNamespaces` for the same directory and can fail on already-applied migrations. If this happens, run: `./yii crelish-migrate --migrationPath= --interactive=0`

## Targets

A link points to an external URL or to a crelish record. Records are resolved on every hit, so a link
survives slug and title changes:

1. A model implementing `giantbits\crelish\components\shortlinks\ShortLinkTargetInterface` returns its own URL.
2. A ctype listed in `detailPages` resolves to `urlFromSlug('<page>')/<uuid>/<slugified systitle>`,
   the convention used by crelish list widgets.
3. Records with a `slug` attribute (pages) resolve to `urlFromSlug($slug)`.

A record that is missing, not online (`state != 2`) or outside its `from`/`to` window counts as broken.

## Dead links

Printed codes never end on a 404. Offline, draft, archived, not-yet-valid, expired and broken links redirect
to the link's fallback URL, then to `fallbackUrl`, then to the home page. Broken targets log a warning
(category `shortlink`) once per link and day. The redirect never fails with an error page for visitors; lookup, 
resolution or cache failures redirect to the link's fallback or the site fallback and are logged (category 
`shortlink`); tracking failures are logged under `analytics`.

## Configuration Notes

- **`siteUrl` is required** when `shortHost` is set. Without it, the redirect endpoint throws an InvalidConfigException. This is a deliberate loud failure to prevent endless redirect loops.
- **QR code logo** must be a PNG or JPEG image, ideally square and at least 600 px wide. The knockout takes at most 22% of the code width.

## Tracking

Each hit is an analytics element event: `element_type = 'shortlink'`, `type` is `scan` (via the QR code's
`/q` URL), `click` or `fallback`. The redirect creates the analytics session itself, so the nightly
aggregation, bot filtering and retention treat short links like any other element.
With a separate `shortHost` the session cookie belongs to that host, so a scan and the following page
view are not linked into one session.

## Admin Interface

The admin list shows "Last hit" with minute precision for recent hits. Once raw events have been pruned, 
"Last hit" displays day precision as it then comes from the daily aggregates.

## QR codes

The QR code contains the short URL in uppercase (`HTTPS://EXAMPLE.COM/GO/IHF26/Q`), which uses the QR
alphanumeric mode and gives a smaller code. The ZIP bundle contains:

- `plain/` SVG, EPS, PDF (vector) and PNG (>= 300 dpi), error correction M, readable from 15 mm
- `logo/` SVG, PDF and PNG with the logo, error correction H, only for 25 mm and larger (EPS cannot embed images)
- `README.txt`
