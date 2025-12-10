# Crelish CMS Documentation

Welcome to the Crelish CMS documentation. This folder contains comprehensive documentation for using and extending the Crelish CMS.

## Table of Contents

### Getting Started
- [Getting Started](./getting-started.md) - Installation and basic setup
- [Content Types](./content-types.md) - Working with content types
- [Troubleshooting](./troubleshooting.md) - Solutions for common issues

### Templates & Frontend
- [Twig Reference](./twig-reference.md) - Template filters, functions, and helpers
- [Responsive Images](./responsive-images.md) - Image presets and srcset generation
- [Frontend Integration](./frontend-integration.md) - Integrating with frontend frameworks
- [Widgets](./widgets.md) - Working with built-in and custom widgets

### API & Authentication
- [API Documentation](./API.md) - RESTful API reference
- [Authentication](./authentication.md) - Authentication and authorization

### Features
- [Newsletter System](./newsletter.md) - MJML email template generation
- [Analytics](./analytics.md) - Page views, element tracking, and dashboards
- [Click Tracking](./click-tracking.md) - Link and element click tracking

### Advanced
- [Extending Crelish](./extending.md) - Hooks, custom controllers, sidebar navigation, widgets, and plugins
- [Documentation Viewer](./documentation-viewer.md) - Using and customizing the documentation viewer

## About Crelish CMS

Crelish is a modern, flexible content management system built on the Yii2 framework. It provides a headless CMS approach with a powerful API for managing content, making it ideal for modern web applications.

## Key Features

### Content Management
- **Headless Architecture**: Separate your content from presentation
- **RESTful API**: Comprehensive API for content management
- **Content Types**: Define custom content types with JSON schemas
- **Content Generator**: Generate database tables and model classes from definitions
- **Multilingual**: Built-in support for multiple languages with translation management

### Media & Templates
- **Media Library**: Integrated asset management with Glide image processing
- **Responsive Images**: Automatic srcset generation with predefined presets
- **Twig Templates**: Rich templating with custom filters and helpers

### Marketing & Analytics
- **Newsletter System**: MJML-based email template generator with visual editor
- **First-Party Analytics**: Page views, element tracking, session management
- **Click Tracking**: Track link clicks and conversions
- **Bot Detection**: Sophisticated bot filtering for accurate metrics

### Administration
- **User Management**: Role-based access control
- **Translation Editor**: In-admin translation file management
- **Built-in Documentation**: Access documentation directly from the admin interface
- **Extensible**: Plugin system for adding custom functionality

## System Requirements

- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB 10.2+
- Composer
- Node.js 14+ and npm (for frontend assets)

## Quick Start

```bash
# Install via Composer
composer require giantbits/yii2-crelish

# Run migrations
./yii migrate --migrationPath=@vendor/giantbits/yii2-crelish/migrations

# Create a content type definition in workspace/elements/boardgame.json
# Generate model and database table
./yii crelish/content-type/generate boardgame
```

See the [Getting Started](./getting-started.md) guide for detailed installation and setup instructions.

## Contributing

We welcome contributions to improve the documentation. Please submit a pull request or open an issue if you find any errors or have suggestions for improvements.

## License

Crelish CMS is released under the [BSD 3-Clause License](../LICENSE). 