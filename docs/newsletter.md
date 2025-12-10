# Newsletter System

Crelish CMS includes a built-in newsletter system that converts JSON-based content definitions to responsive HTML emails using MJML.

## Overview

The newsletter system consists of:

- **MjmlGenerator**: Converts JSON newsletter definitions to MJML markup
- **MjmlService**: Compiles MJML to HTML using Node.js
- **Newsletter Editor**: Visual editor for composing newsletters (in `/resources/newsletter`)

## Architecture

```
JSON Definition → MjmlGenerator → MJML Markup → MjmlService → HTML Email
```

## Section Types

The newsletter system supports various section types that can be combined to create complete email templates:

### Hero Section

Full-width hero image with optional text overlay and CTA button.

```json
{
  "type": "hero",
  "content": {
    "imageId": "asset-uuid",
    "link": "https://example.com",
    "title": "Welcome to Our Newsletter",
    "subtitle": "Monthly updates and news",
    "ctaText": "Learn More",
    "titleColor": "#FFFFFF"
  }
}
```

### Navigation Section

Horizontal navigation links with optional images.

```json
{
  "type": "navigation",
  "content": {
    "links": [
      {
        "text": "Home",
        "url": "https://example.com",
        "imageId": null
      },
      {
        "text": "Products",
        "url": "https://example.com/products",
        "imageId": "asset-uuid"
      }
    ]
  }
}
```

### Article Section

Single or double-column article layout with images.

```json
{
  "type": "article_section",
  "content": {
    "title": "Latest News",
    "layout": "single",
    "showDivider": "true",
    "articles": [
      {
        "title": "Article Title",
        "text": "Article content goes here...",
        "imageId": "asset-uuid",
        "link": "https://example.com/article"
      }
    ]
  }
}
```

**Layout options:**
- `single`: Full-width articles stacked vertically
- `double`: Two-column layout with image on left (45%) and text on right (55%)

### Event Cards Section

Grid of event cards (up to 3 columns).

```json
{
  "type": "event_cards",
  "content": {
    "title": "Upcoming Events",
    "backgroundColor": "#FFFFFF",
    "events": [
      {
        "title": "Conference 2025",
        "location": "Berlin, Germany",
        "date": "March 15-17, 2025",
        "color": "#006633",
        "link": "https://example.com/event",
        "imageId": "asset-uuid"
      }
    ]
  }
}
```

### Events List Section

Compact list of events with light/dark color scheme.

```json
{
  "type": "events_list",
  "content": {
    "bgColor": "light",
    "events": [
      {
        "title": "Workshop: Introduction to MJML",
        "date": "January 20, 2025",
        "location": "Online",
        "link": "https://example.com/workshop"
      }
    ]
  }
}
```

**Color schemes:**
- `light`: White background, black text
- `dark`: Black background, white text

### Job Postings Section

Job listings with company logos.

```json
{
  "type": "job_postings",
  "content": {
    "title": "Career Opportunities",
    "titleColor": "#F7941D",
    "jobs": [
      {
        "company": "Acme Corp",
        "location": "Munich, Germany",
        "title": "Senior Developer",
        "companyLogoId": "asset-uuid",
        "companyLogoLink": "https://acme.com",
        "link": "https://acme.com/careers/senior-developer"
      }
    ]
  }
}
```

### Partners Section

Grid of partner/sponsor logos.

```json
{
  "type": "partners",
  "content": {
    "title": "Our Partners",
    "backgroundColor": "#FFFFFF",
    "titleColor": "#000000",
    "columnCount": 4,
    "partners": [
      {
        "name": "Partner Company",
        "logoId": "asset-uuid",
        "url": "https://partner.com"
      }
    ]
  }
}
```

### Advertisement Section

Full-width banner advertisement.

```json
{
  "type": "ad",
  "content": {
    "imageId": "asset-uuid",
    "url": "https://advertiser.com",
    "altText": "Advertisement description"
  }
}
```

### Text Section

Rich text content block.

```json
{
  "type": "text",
  "content": {
    "text": "Your text content here. Supports <strong>HTML</strong> formatting.",
    "backgroundColor": "#FFFFFF",
    "textColor": "#000000"
  }
}
```

## Complete Newsletter Structure

```json
{
  "title": "Monthly Newsletter - January 2025",
  "sections": [
    {
      "type": "hero",
      "content": { ... }
    },
    {
      "type": "navigation",
      "content": { ... }
    },
    {
      "type": "article_section",
      "content": { ... }
    },
    {
      "type": "events_list",
      "content": { ... }
    },
    {
      "type": "partners",
      "content": { ... }
    }
  ]
}
```

## Using the Newsletter System

### Generating MJML

```php
use giantbits\crelish\components\MjmlGenerator;

$generator = new MjmlGenerator($assetManager, 'https://your-domain.com');
$mjml = $generator->generateMjml($newsletterData);
```

### Compiling to HTML

```php
use giantbits\crelish\components\MjmlService;

$mjmlService = Yii::$app->mjmlService;
$html = $mjmlService->renderMjml($mjml);
```

### Configuration

The MJML service requires Node.js and the MJML package. Configure paths in your `.env` file:

```env
NODE_PATH=/usr/local/bin/node
MJML_PATH=/path/to/node_modules/mjml/bin/mjml
```

Or use the API method for environments without Node.js:

```php
$html = $mjmlService->renderMjmlViaApi($mjml);
```

Configure the API endpoint in your application params:

```php
'params' => [
    'laravelMjmlApiUrl' => 'http://localhost:8000/api/mjml/render',
],
```

## Default Styling

The newsletter system includes default styling:

- **Font**: Helvetica, Arial, sans-serif (16px base)
- **Max width**: 640px
- **Colors**: Customizable per section
- **Mobile responsive**: Fluid images and stacked layouts

### Built-in CSS Classes

- `.link-nostyle`: Removes link styling (inherits color)
- `.footer-link`: Footer link styling (#888888)
- `.section-text a`: Content section links
- `.section-social img`: Social media icon filtering

## Footer

The default footer includes:

- Social media links (Instagram, Facebook, LinkedIn)
- Copyright notice with year
- Impressum/Privacy links
- Unsubscribe link placeholder
- Back to top link

To customize the footer, extend the `MjmlGenerator` class and override `getTemplateFooter()`.

## Best Practices

1. **Image Optimization**: Use appropriately sized images. The generator uses placeholder URLs for missing images.

2. **Testing**: Always test emails across multiple clients (Gmail, Outlook, Apple Mail).

3. **Alt Text**: Provide meaningful alt text for all images.

4. **Link Tracking**: Combine with the click tracking system for analytics:
   ```twig
   {{ chelper.getClickTrackingUrl(element.uuid, 'newsletter-link') }}
   ```

5. **Mobile First**: Keep content concise - newsletters are often read on mobile devices.

## Related Documentation

- [Twig Reference](./twig-reference.md) - Template helpers
- [Click Tracking](./click-tracking.md) - Analytics integration
- [API Documentation](./API.md) - Content API
