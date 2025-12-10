# Responsive Images

Crelish CMS provides a comprehensive responsive image system with automatic srcset generation, format conversion, and predefined presets for common use cases.

## Overview

The responsive image system:

- Generates `srcset` and `sizes` attributes automatically
- Converts images to WebP format by default
- Provides predefined presets for common layouts
- Calculates dimensions to prevent layout shift
- Supports lazy loading
- Uses Glide for on-the-fly image processing

## Basic Usage

### In Twig Templates

```twig
{# Using a preset #}
{{ chelper.responsiveImage(image.uuid, {preset: 'hero'})|raw }}

{# With custom options #}
{{ chelper.responsiveImage(image.uuid, {
    preset: 'card',
    alt: 'Product image',
    class: 'product-thumbnail'
})|raw }}

{# Fully custom #}
{{ chelper.responsiveImage(image.uuid, {
    widths: [200, 400, 800],
    sizes: '(max-width: 600px) 100vw, 50vw',
    format: 'webp',
    quality: 80,
    loading: 'lazy'
})|raw }}
```

### In PHP

```php
use giantbits\crelish\components\CrelishBaseHelper;

$html = CrelishBaseHelper::responsiveImage($uuid, [
    'preset' => 'hero',
    'alt' => 'Hero image'
]);
```

## Image Presets

Crelish includes five built-in presets optimized for common layouts:

### Hero

Full-width hero/banner images.

```php
'hero' => [
    'widths' => [480, 768, 1024, 1600, 2000],
    'sizes' => '(max-width: 767px) 100vw, (max-width: 1199px) 100vw, 100vw',
    'loading' => 'eager',  // No lazy loading for above-fold content
]
```

**Use for:** Homepage heroes, page headers, full-bleed banners

```twig
{{ chelper.responsiveImage(page.heroImage, {preset: 'hero', alt: page.title})|raw }}
```

### Card

Images in card grids (4-column desktop, responsive).

```php
'card' => [
    'widths' => [300, 600, 900],
    'sizes' => '(max-width: 767px) 100vw, (max-width: 991px) 50vw, (max-width: 1199px) 33.333vw, 25vw',
]
```

**Use for:** Product cards, article cards, team member cards

```twig
{% for article in articles %}
<div class="col-md-6 col-lg-3">
    {{ chelper.responsiveImage(article.thumbnail, {preset: 'card', alt: article.title})|raw }}
    <h3>{{ article.title }}</h3>
</div>
{% endfor %}
```

### Thumbnail

Small thumbnail images.

```php
'thumbnail' => [
    'widths' => [120, 240],
    'sizes' => '120px',
]
```

**Use for:** Avatar images, small previews, list item icons

```twig
{{ chelper.responsiveImage(user.avatar, {preset: 'thumbnail', alt: user.name})|raw }}
```

### Content

Images within content areas (centered, max 50% width on desktop).

```php
'content' => [
    'widths' => [400, 800, 1200],
    'sizes' => '(max-width: 767px) 100vw, (max-width: 991px) 75vw, 50vw',
]
```

**Use for:** Article body images, blog post images, documentation

```twig
<article>
    {{ chelper.responsiveImage(article.featuredImage, {preset: 'content', alt: article.title})|raw }}
    {{ article.body|raw }}
</article>
```

### Default

General-purpose fallback preset.

```php
'default' => [
    'widths' => [480, 768, 1024],
    'sizes' => '100vw',
]
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `preset` | string | `'default'` | Predefined preset name |
| `widths` | array | `[480, 768, 1024]` | Array of widths for srcset |
| `sizes` | string | `'100vw'` | The `sizes` attribute |
| `format` | string | `'webp'` | Output format (webp, jpg, png) |
| `quality` | int | `75` | Image quality (1-100) |
| `alt` | string | Asset description | Alt text |
| `class` | string | `'img-fluid'` | CSS class(es) |
| `loading` | string | `'lazy'` | Loading strategy |
| `width` | int | From asset | Explicit width |
| `height` | int | From asset | Explicit height |

## Generated HTML

For an image with the `card` preset:

```html
<img
    src="/crelish/asset/glide?path=uploads/image.jpg&q=75&fm=webp&w=900"
    alt="Product Name"
    class="img-fluid"
    loading="lazy"
    srcset="/crelish/asset/glide?path=uploads/image.jpg&q=75&fm=webp&w=300 300w,
            /crelish/asset/glide?path=uploads/image.jpg&q=75&fm=webp&w=600 600w,
            /crelish/asset/glide?path=uploads/image.jpg&q=75&fm=webp&w=900 900w"
    sizes="(max-width: 767px) 100vw, (max-width: 991px) 50vw, (max-width: 1199px) 33.333vw, 25vw"
    width="800"
    height="600"
>
```

## Glide Image Processing

Images are processed on-the-fly using the Glide library via `/crelish/asset/glide`:

### URL Parameters

| Parameter | Description | Example |
|-----------|-------------|---------|
| `path` | Source image path | `uploads/image.jpg` |
| `w` | Width | `800` |
| `h` | Height | `600` |
| `q` | Quality (1-100) | `75` |
| `fm` | Format | `webp`, `jpg`, `png` |
| `fit` | Fit mode | `contain`, `crop`, `fill` |

### Direct Glide URLs

```twig
{# Generate a specific size manually #}
<img src="/crelish/asset/glide?path={{ image.path }}&w=400&h=300&fit=crop&fm=webp&q=80">
```

## Aspect Ratio Handling

The system automatically calculates missing dimensions from the original asset:

```twig
{# If you only specify width, height is calculated from aspect ratio #}
{{ chelper.responsiveImage(image.uuid, {width: 800})|raw }}
```

This prevents Cumulative Layout Shift (CLS) by including `width` and `height` attributes.

## Performance Tips

### 1. Use Appropriate Presets

Don't use `hero` preset for thumbnails - the large srcset wastes bandwidth.

### 2. Eager Loading for Above-the-Fold

```twig
{# Hero images should load immediately #}
{{ chelper.responsiveImage(hero.uuid, {preset: 'hero', loading: 'eager'})|raw }}

{# Below-fold images can lazy load #}
{{ chelper.responsiveImage(article.image, {preset: 'content', loading: 'lazy'})|raw }}
```

### 3. WebP Format

WebP is the default and provides ~25-35% smaller files than JPEG. Fall back to JPEG only if you need IE11 support:

```twig
{{ chelper.responsiveImage(image.uuid, {format: 'jpg'})|raw }}
```

### 4. Appropriate Quality

- **Hero/Feature images**: 80-85 quality
- **Thumbnails**: 70-75 quality
- **Decorative images**: 60-70 quality

```twig
{{ chelper.responsiveImage(decorative.uuid, {quality: 65})|raw }}
```

## Custom Presets

To add custom presets, extend `CrelishBaseHelper`:

```php
class MyHelper extends CrelishBaseHelper
{
    private static function getImagePresets(): array
    {
        $presets = parent::getImagePresets();

        // Add custom presets
        $presets['gallery'] = [
            'widths' => [400, 800, 1200, 1600],
            'sizes' => '(max-width: 767px) 100vw, 50vw',
            'quality' => 85,
        ];

        $presets['logo'] = [
            'widths' => [100, 200],
            'sizes' => '100px',
            'format' => 'png',  // Preserve transparency
        ];

        return $presets;
    }
}
```

## Fallback for Missing Images

If an asset UUID is invalid or the image doesn't exist, `responsiveImage()` returns an empty string:

```twig
{% set imageHtml = chelper.responsiveImage(item.image, {preset: 'card'}) %}
{% if imageHtml %}
    {{ imageHtml|raw }}
{% else %}
    <img src="/images/placeholder.svg" alt="No image available">
{% endif %}
```

## Simple Asset URL

For cases where you don't need responsive images:

```twig
{# Just get the URL #}
<img src="{{ chelper.getAssetUrlById(image.uuid) }}">

{# Or with path and filename #}
<img src="{{ chelper.getAssetUrl('/uploads', 'logo.png') }}">
```

## Related Documentation

- [Twig Reference](./twig-reference.md) - All template helpers
- [Content Types](./content-types.md) - File field configuration
- [Widgets](./widgets.md) - Using images in widgets
