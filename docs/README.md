# Crelish CMS Documentation

Welcome to the Crelish CMS documentation. This folder contains comprehensive documentation for using and extending the Crelish CMS.

## Table of Contents

- [Getting Started](./getting-started.md) - Installation and basic setup
- [API Documentation](./API.md) - RESTful API reference
- [Content Types](./content-types.md) - Working with content types
- [Authentication](./authentication.md) - Authentication and authorization
- [Extending Crelish](./extending.md) - Creating plugins and extensions
- [Frontend Integration](./frontend-integration.md) - Integrating with frontend frameworks
- [Widgets](./widgets.md) - Working with built-in and custom widgets
- [Documentation Viewer](./documentation-viewer.md) - Using and customizing the documentation viewer
- [Troubleshooting](./troubleshooting.md) - Solutions for common issues

## About Crelish CMS

Crelish is a modern, flexible content management system built on the Yii2 framework. It provides a headless CMS approach with a powerful API for managing content, making it ideal for modern web applications.

## Key Features

- **Headless Architecture**: Separate your content from presentation
- **RESTful API**: Comprehensive API for content management
- **Content Types**: Define custom content types with JSON schemas in your application's workspace/elements directory
- **Element Management**: Create and manage content type definitions through the ElementsController or directly with JSON files
- **Content Generator**: Generate database tables and model classes from your content type definitions
- **Multilingual**: Built-in support for multiple languages
- **Extensible**: Plugin system for adding custom functionality
- **User Management**: Role-based access control
- **Media Management**: Integrated media library with image processing
- **Built-in Documentation**: Access documentation directly from the admin interface

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