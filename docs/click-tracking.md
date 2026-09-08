# Click Tracking Implementation

This document describes the secure click tracking implementation using JavaScript Beacon API.

## Overview

The click tracking system allows you to track clicks on any link (especially external links, ads, banners) using a reliable JavaScript-based approach. The implementation includes security measures to prevent abuse and spam.

**Why not HTML `ping`?** The HTML `ping` attribute has severe limitations:
- ❌ Disabled by default in Firefox
- ❌ Blocked by most ad blockers
- ❌ Blocked by privacy tools
- ❌ No reliability guarantees

Our JavaScript solution uses **Beacon API** with **fetch fallback** for maximum reliability.

## How It Works

1. Include `ClickTrackingAsset` in your layout to load the JavaScript tracker
2. Add `data-track-click` attribute to links with a secure tracking URL
3. When clicked, JavaScript sends tracking request using Beacon API (or fetch fallback)
4. The endpoint validates the request and records the click
5. Clicks are stored in the `analytics_element_views` table with `type='click'`

The JavaScript tracker tries three methods in order:
1. **Beacon API** (best - reliable even during page unload)
2. **Fetch with keepalive** (good fallback)
3. **Image pixel** (works everywhere)

## Security Features

### 1. Token-Based Authentication
- Each tracking URL includes a time-limited HMAC token
- Tokens are valid for 1 hour by default
- Tokens cannot be reused after expiration

### 2. Rate Limiting
- Maximum 10 clicks per element per session within 5 minutes
- Prevents click spam and abuse
- Session-based tracking

### 3. Bot Detection
- Leverages existing `CrelishAnalyticsComponent` bot detection
- Bots are tracked separately with `is_bot=1` flag

## Setup

### 1. Register the Asset in Your Layout

```twig
{# In your main layout file (e.g., layout.twig) #}
{% do ClickTrackingAsset.register(this) %}
```

Or in PHP:

```php
// In your layout file
use giantbits\crelish\assets\ClickTrackingAsset;
ClickTrackingAsset::register($this);
```

### 2. Add Tracking to Links

```twig
<a href="https://external-site.com"
   data-track-click="{{ chelper.getClickTrackingUrl(element.uuid, element.ctype) }}">
  Click Me
</a>
```

## Usage in Templates

### Basic Usage

```twig
<a href="https://external-site.com"
   target="_blank"
   rel="noopener noreferrer"
   data-track-click="{{ chelper.getClickTrackingUrl(element.uuid, 'link') }}">
  External Site
</a>
```

### With Custom Element Type

```twig
{# Track as 'ad' instead of generic 'link' #}
<a href="https://advertiser.com"
   target="_blank"
   rel="noopener noreferrer"
   data-track-click="{{ chelper.getClickTrackingUrl(ad.uuid, 'ad') }}">
  Advertisement
</a>
```

### With Custom Page UUID

```twig
<a href="https://partner.com"
   target="_blank"
   rel="noopener noreferrer"
   data-track-click="{{ chelper.getClickTrackingUrl(banner.uuid, 'banner', null, page.uuid) }}">
  Partner Link
</a>
```

### Redirect Mode

Instead of a background ping, the link itself can point at the tracking endpoint,
which logs the click and then forwards the visitor. This is more reliable, because
it does not depend on the browser completing a background request during
navigation.

```twig
<a href="{{ chelper.getClickTrackingUrl(model.uuid, model.ctype, model.linkUrl) }}"
   target="_blank"
   rel="noopener noreferrer">
  Partner Website
</a>
```

## Helper Methods

### `chelper.getClickTrackingUrl($uuid, $type, $targetUrl, $pageUuid)`

Generates a complete tracking URL with security token.

**Parameters:**
- `$uuid` (string, required) - Element UUID to track
- `$type` (string, optional) - Element type (default: 'link')
  - Common types: 'ad', 'banner', 'link', 'button', 'cta'
- `$targetUrl` (string, optional) - Redirect target. Provide it for redirect mode,
  leave it out for ping mode.
- `$pageUuid` (string, optional) - Page UUID (defaults to current page)

**Returns:** String - Complete tracking URL

### `chelper.generateClickToken($uuid, $redirectUrl)`

Generates just the security token (usually not needed directly).

**Parameters:**
- `$uuid` (string, required) - Element UUID
- `$redirectUrl` (string, optional) - Redirect target the token should be valid
  for. Must be `null` for ping mode, and must match the `redirect` query
  parameter exactly for redirect mode.

**Returns:** String - Security token

## Security

The tracking endpoint is public and unauthenticated, and in redirect mode it
forwards visitors to another site. That combination is attractive to phishing
campaigns: a link on a trusted domain that quietly lands somewhere else.

Two properties keep it safe, and both have to hold:

1. **The redirect target is part of the signed token.** The token is an HMAC over
   `uuid + redirect target + issue time`, so it is only valid for the exact
   destination the application generated it for. Appending or swapping a
   `redirect` parameter invalidates it.
2. **A failed check never redirects.** Missing token, bad token, expired token and
   exceeded rate limit all return `400`, never a `Location` header.

Regression tests for both live in `tests/ClickTrackingSecurityTest.php`:

```bash
php tests/ClickTrackingSecurityTest.php
```

### Redirect targets

A signed target still has to be structurally sound: it needs an `http` or `https`
scheme and a host, and it must not carry embedded credentials
(`https://trusted.example.com@evil.example.net/`), which are a common way to make
a hostile host look familiar in the address bar.

Non-ASCII characters are allowed. Editorial URLs such as
`https://example.com/über-uns` are valid and are percent-encoded when the
`Location` header is written, since a header may only carry printable ASCII.

### Required configuration

Tokens are signed with `Yii::$app->params['clickTokenSecret']` if set, otherwise
with the `request` component's `cookieValidationKey`. If neither is configured,
click tracking throws `InvalidConfigException` rather than falling back to a
guessable value.

A dedicated secret is preferable, because it can live outside version control and
can be rotated without invalidating everyone's cookies:

```php
// config/params.php
return [
    'clickTokenSecret' => getenv('CLICK_TOKEN_SECRET'),
];
```

Rotating the secret invalidates all outstanding tracking URLs. Those are
regenerated on every page render, so the practical impact is limited to links in
already-sent newsletters and in cached or indexed pages.

### Token lifetime

Tokens are valid for 30 days by default. Because the destination is signed, an old
token cannot be repointed, so the window mainly limits how long a link can be
replayed to inflate click counts. Adjust it per project if needed:

```php
'click' => [
    'class' => 'giantbits\crelish\actions\TrackClickAction',
    'tokenValidityWindow' => 604800, // 7 days
],
```

## Database Schema

Clicks are stored in the existing `analytics_element_views` table:

```sql
INSERT INTO analytics_element_views (
  element_uuid,
  element_type,
  page_uuid,
  session_id,
  user_id,
  type,
  created_at
) VALUES (
  'element-uuid',
  'ad',
  'page-uuid',
  'session-id',
  123,
  'click',  -- This identifies it as a click event
  NOW()
);
```

## Configuration

You can customize the tracking behavior by modifying the action configuration:

```php
// In your controller configuration
'track' => [
    'class' => 'giantbits\crelish\actions\TrackClickAction',
    'tokenValidityWindow' => 3600,     // Token validity in seconds (1 hour)
    'maxClicksPerElement' => 10,       // Max clicks per element per session
    'rateLimitWindow' => 300,          // Rate limit window in seconds (5 minutes)
]
```

## Querying Click Data

### Get Top Clicked Elements

```php
// Get top clicked elements in the last month
$topClicks = Yii::$app->crelishAnalytics->getTopElements(
    'month',  // period
    10,       // limit
    'click'   // type filter
);
```

### Get Clicks for Specific Element

```php
use yii\db\Query;

$clicks = (new Query())
    ->select(['created_at', 'session_id', 'user_id', 'page_uuid'])
    ->from('analytics_element_views')
    ->where([
        'element_uuid' => 'your-element-uuid',
        'type' => 'click'
    ])
    ->orderBy(['created_at' => SORT_DESC])
    ->all();
```

### Get Click Count by Element Type

```php
$clicksByType = (new Query())
    ->select(['element_type', 'count' => 'COUNT(*)'])
    ->from('analytics_element_views')
    ->where(['type' => 'click'])
    ->andWhere(['>=', 'created_at', date('Y-m-d', strtotime('-30 days'))])
    ->groupBy(['element_type'])
    ->orderBy(['count' => SORT_DESC])
    ->all();
```

## Browser Compatibility

The JavaScript tracker (Beacon API + fallbacks) is supported in:
- ✅ **Chrome 39+** (Beacon API)
- ✅ **Firefox 31+** (Beacon API)
- ✅ **Safari 11.1+** (Beacon API)
- ✅ **Edge 14+** (Beacon API)
- ✅ **All browsers** (Image pixel fallback)

The tracker automatically uses the best available method for each browser.

## Privacy Considerations

1. Tracking URLs are visible in the HTML source and browser dev tools
2. Ad blockers may block the tracking endpoint
3. This is transparent tracking - users can inspect what's being tracked
4. Consider informing users in your privacy policy

## Best Practices

1. **Use Descriptive Types**: Use meaningful element types ('ad', 'banner', 'cta') for better analytics
2. **Track External Links Only**: Internal navigation is already tracked via page views
3. **Combine with Page Views**: Use click data alongside page view data for complete picture
4. **Respect Privacy**: Be transparent about tracking in your privacy policy
5. **Monitor Rate Limits**: If legitimate users hit rate limits, increase the threshold

## Troubleshooting

### Clicks Not Being Tracked

1. Check that `crelishAnalytics` component is enabled
2. Verify token is being generated (check page source)
3. Check browser console for failed ping requests
4. Verify the route `/crelish/track/click` is accessible
5. Check if rate limiting is blocking requests

### High Number of Invalid Token Warnings

1. Check server time synchronization
2. Verify `Yii::$app->security->passwordHashStrategy` is consistent
3. Increase `tokenValidityWindow` if pages are cached for long periods

### Rate Limiting Issues

1. Increase `maxClicksPerElement` if users legitimately click frequently
2. Increase `rateLimitWindow` to spread out the limit
3. Check session configuration for premature expiration

## Example: Tracking Ad Banners

```twig
{% for ad in advertisements %}
  <div class="ad-banner">
    <a href="{{ ad.targetUrl }}"
       target="_blank"
       rel="noopener noreferrer"
       data-track-click="{{ chelper.getClickTrackingUrl(ad.uuid, 'ad') }}">
      <img src="{{ ad.imageUrl }}" alt="{{ ad.title }}">
    </a>
  </div>
{% endfor %}
```

## Example: Tracking Call-to-Action Buttons

```twig
<a href="/signup"
   class="btn btn-primary"
   data-track-click="{{ chelper.getClickTrackingUrl(cta.uuid, 'cta') }}">
  Sign Up Now
</a>
```

## Future Enhancements

Potential improvements:
1. Click heatmap visualization
2. Click-through rate (CTR) calculations
3. A/B testing integration
4. Conversion funnel tracking
5. Real-time click analytics dashboard