# Twig Template Reference

This document covers the Twig filters, functions, and helpers available in Crelish CMS templates.

## Filters

### registerCss

Registers CSS to be placed in the document `<head>`. Automatically minifies in production and uses content hashing to prevent duplicates.

```twig
{% apply registerCss %}
.my-component {
    background: #fff;
    padding: 1rem;
}
{% endapply %}
```

**Features:**
- CSS is placed in the `<head>` section
- Automatically minified in production (non-debug mode)
- Content-based deduplication - identical CSS blocks are only included once
- Supports CSP nonce when configured on the controller

### registerJs

Registers JavaScript to be placed at the end of the document body. Automatically minifies in production.

```twig
{% apply registerJs %}
document.addEventListener('DOMContentLoaded', function() {
    console.log('Page loaded');
});
{% endapply %}
```

**Features:**
- JavaScript is placed at the end of `<body>` by default
- Automatically minified in production
- Content-based deduplication
- Supports CSP nonce when configured

### truncateWords

Truncates text to a specified number of words while properly closing any open HTML tags.

```twig
{{ article.content|truncateWords(50) }}
```

**Parameters:**
- `limit` (integer): Maximum number of words

## Functions

### Translation

#### t()

Translates a message using Yii's i18n system. This function is provided by yii2-twig.

```twig
{{ t('content', 'Welcome to our site') }}
{{ t('app', 'Save') }}
```

**Parameters:**
- `category` (string): Translation category (e.g., 'content', 'app')
- `message` (string): The message to translate
- `params` (array, optional): Replacement parameters
- `language` (string, optional): Target language

```twig
{# With parameters #}
{{ t('content', 'Hello {name}', {'name': user.name}) }}
```

### Page Context

#### getCurrentSlug()

Returns the current page's URL slug.

```twig
{% if getCurrentSlug() == 'contact' %}
    {# Show contact-specific content #}
{% endif %}
```

#### isHomePage()

Returns `true` if the current page is the homepage.

```twig
{% if isHomePage() %}
    <div class="hero-banner">...</div>
{% endif %}
```

#### getCurrentPage()

Returns the current page data object.

```twig
{% set page = getCurrentPage() %}
<h1>{{ page.title }}</h1>
```

### HTML Helpers

#### html_attributes()

Renders an array as HTML attributes using Yii's Html helper.

```twig
<div{{ html_attributes({'class': 'container', 'data-id': item.id}) }}>
```

**Output:**
```html
<div class="container" data-id="123">
```

#### extract_first_tag()

Extracts the first occurrence of a specific HTML tag from content.

```twig
{{ extract_first_tag('p', article.content) }}
```

**Parameters:**
- `tag` (string): The HTML tag name to extract (e.g., 'p', 'h1')
- `htmlString` (string): The HTML content to search

## Global Helper: chelper

The `chelper` global provides access to `CrelishBaseHelper` methods. These are the most commonly used methods:

### Asset Methods

#### chelper.getAssetUrlById()

Returns the URL for an asset by its UUID.

```twig
<img src="{{ chelper.getAssetUrlById(item.image) }}" alt="{{ item.title }}">
```

**Parameters:**
- `uuid` (string): The asset UUID

**Returns:** String URL or `null` if not found

#### chelper.responsiveImage()

Generates a complete responsive `<img>` tag with srcset for optimized image loading.

```twig
{{ chelper.responsiveImage(image.uuid, {preset: 'hero', alt: 'Hero image'})|raw }}
```

**Parameters:**
- `uuid` (string): The asset UUID
- `options` (array): Configuration options

**Options:**
| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `preset` | string | 'default' | Predefined size configuration ('hero', 'thumbnail', 'card', etc.) |
| `sizes` | string | '100vw' | The `sizes` attribute for responsive images |
| `widths` | array | [480, 768, 1024] | Array of widths for srcset generation |
| `format` | string | 'webp' | Output image format |
| `quality` | int | 75 | Image quality (1-100) |
| `alt` | string | (from asset) | Alt text |
| `class` | string | 'img-fluid' | CSS class(es) |
| `loading` | string | 'lazy' | Loading strategy ('lazy', 'eager') |

```twig
{# Using a preset #}
{{ chelper.responsiveImage(hero.uuid, {preset: 'hero', alt: event.title})|raw }}

{# Custom configuration #}
{{ chelper.responsiveImage(thumb.uuid, {
    widths: [200, 400],
    sizes: '(max-width: 600px) 100vw, 50vw',
    class: 'thumbnail rounded'
})|raw }}
```

#### chelper.getAssetUrl()

Returns a URL by combining a path and filename.

```twig
<img src="{{ chelper.getAssetUrl('/uploads/images', 'logo.png') }}">
```

### URL Methods

#### chelper.urlFromSlug()

Generates a URL from a slug with automatic language prefix support.

```twig
<a href="{{ chelper.urlFromSlug('about-us') }}">About</a>
<a href="{{ chelper.urlFromSlug('contact', {}, 'de') }}">Kontakt</a>
```

**Parameters:**
- `slug` (string): The URL slug
- `params` (array): Additional URL parameters
- `langCode` (string|null): Language code override
- `scheme` (bool|string): URL scheme

#### chelper.getLanguageUrl()

Generates a URL for the current page in a different language.

```twig
<a href="{{ chelper.getLanguageUrl('en') }}">English</a>
<a href="{{ chelper.getLanguageUrl('de') }}">Deutsch</a>
```

#### chelper.currentUrl()

Returns the current URL with optional parameter modifications.

```twig
<a href="{{ chelper.currentUrl({page: 2}) }}">Next Page</a>
```

### Click Tracking

#### chelper.getClickTrackingUrl()

Generates a click tracking URL for analytics.

```twig
<a href="{{ sponsor.website }}"
   data-track-click="{{ chelper.getClickTrackingUrl(sponsor.uuid, 'sponsor') }}">
    {{ sponsor.name }}
</a>
```

**Parameters:**
- `uuid` (string): Element UUID
- `type` (string): Element type (e.g., 'link', 'sponsor', 'ad', 'cta')
- `pageUuid` (string|null): Optional page context UUID

#### chelper.getTrackedDownloadUrl()

Generates a tracked download URL.

```twig
<a href="{{ chelper.getTrackedDownloadUrl(document.uuid) }}">
    Download PDF
</a>
```

### Utility Methods

#### chelper.lightenColor() / chelper.darkenColor()

Adjusts a hex color by a percentage.

```twig
<div style="background: {{ chelper.lightenColor(event.color, 20) }}">
```

**Parameters:**
- `hexcolor` (string): Hex color code (e.g., '#795c41')
- `percent` (int): Percentage to lighten/darken

#### chelper.dump()

Debug helper - outputs variable contents (visible in debug mode).

```twig
{{ chelper.dump(myVariable) }}
```

#### chelper.dd()

Debug helper - dumps and dies (stops execution).

```twig
{{ chelper.dd(myVariable) }}
```

## Yii2 Twig Globals

These globals are provided by yii2-twig and give access to Yii application components:

### app

Access to the Yii application instance.

```twig
{# Current language #}
{{ app.language }}

{# Request parameters #}
{{ app.request.get('id') }}

{# Application parameters #}
{{ app.params.siteName }}

{# User info #}
{% if not app.user.isGuest %}
    Welcome, {{ app.user.identity.username }}
{% endif %}
```

### html

Access to `yii\helpers\Html` methods.

```twig
{{ html.encode(userInput) }}
{{ html.a('Click here', '/page')|raw }}
{{ html.submitButton('Save', {'class': 'btn btn-primary'})|raw }}
```

## Best Practices

### 1. Use registerCss/registerJs Instead of Inline Tags

```twig
{# Good - uses filters #}
{% apply registerCss %}
.component { color: red; }
{% endapply %}

{# Avoid - inline style tag #}
<style>.component { color: red; }</style>
```

### 2. Always Use |raw for HTML Output

```twig
{# Correct - outputs HTML #}
{{ chelper.responsiveImage(uuid, options)|raw }}

{# Wrong - escapes HTML entities #}
{{ chelper.responsiveImage(uuid, options) }}
```

### 3. Prefer Presets for Responsive Images

```twig
{# Good - uses predefined preset #}
{{ chelper.responsiveImage(uuid, {preset: 'hero'})|raw }}

{# Only use custom widths when presets don't fit #}
{{ chelper.responsiveImage(uuid, {widths: [100, 200, 300]})|raw }}
```

### 4. Use Translation Functions for All User-Facing Text

```twig
{# Good - translatable #}
<h1>{{ t('content', 'Welcome') }}</h1>

{# Avoid - hardcoded text #}
<h1>Welcome</h1>
```

### 5. Check for Empty Values Before Rendering

```twig
{% if item.image %}
    {{ chelper.responsiveImage(item.image, {preset: 'thumbnail'})|raw }}
{% endif %}
```

## Related Documentation

- [Content Types](content-types.md) - Defining content structure
- [Widgets](widgets.md) - Creating custom widgets
- [Click Tracking](click-tracking.md) - Analytics integration
- [Frontend Integration](frontend-integration.md) - API usage