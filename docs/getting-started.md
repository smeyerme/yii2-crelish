# Getting Started with Crelish CMS

This guide will help you install and set up Crelish CMS for your project.

## Requirements

- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB 10.2+
- Composer
- Node.js 14+ and npm (for frontend assets)

## Installation

### Via Composer

The recommended way to install Crelish CMS is through Composer:

```bash
composer require giantbits/yii2-crelish
```

### Manual Installation

1. Download the latest release from the [GitHub repository](https://github.com/giantbits/yii2-crelish)
2. Extract the files to your project's vendor directory
3. Run `composer install` in the extracted directory

## Configuration

### Basic Configuration

1. Add Crelish to your Yii2 application configuration:

```php
// config/web.php
return [
    'bootstrap' => [
        // ... other bootstrap components
        'giantbits\crelish\Bootstrap',
    ],
    'modules' => [
        'crelish' => [
            'class' => 'giantbits\crelish\Module',
            'theme' => 'default', // or your custom theme
        ],
    ],
    'components' => [
        // ... other components
    ],
    'params' => [
        'crelish' => [
            'theme' => 'default',
            'ga_sst_enabled' => false, // Google Analytics server-side tracking
        ],
    ],
];
```

### Database Setup

Run the migrations to set up the database tables:

```bash
./yii migrate --migrationPath=@vendor/giantbits/yii2-crelish/migrations
```

### Workspace Setup

Crelish CMS uses a workspace directory in your application to store content type definitions and generated model classes. Create this directory structure:

```bash
mkdir -p workspace/elements
mkdir -p workspace/models
```

### Content Types Configuration

Content types in Crelish are defined in JSON files stored in the `workspace/elements` directory. You can create these files manually or use the ElementsController in the admin interface.

#### Using ElementsController (Recommended)

1. Access the admin panel at `/crelish`
2. Navigate to "Elements" in the sidebar
3. Click "Add New" to create a new content type
4. Define your fields and configuration
5. Save the content type

#### Manual Content Type Definition

Create your first content type (e.g., `workspace/elements/page.json`):

```json
{
  "name": "page",
  "label": "Page",
  "description": "Basic page content type",
  "fields": {
    "title": {
      "type": "string",
      "label": "Title",
      "description": "Page title",
      "required": true,
      "minLength": 3,
      "maxLength": 255
    },
    "slug": {
      "type": "string",
      "label": "Slug",
      "description": "URL-friendly version of the title",
      "required": true,
      "minLength": 3,
      "maxLength": 255
    },
    "content": {
      "type": "string",
      "label": "Content",
      "description": "Page content in HTML format",
      "required": true
    },
    "status": {
      "type": "enum",
      "label": "Status",
      "description": "Publication status",
      "required": true,
      "values": ["draft", "published", "archived"],
      "default": "draft"
    }
  }
}
```

### Generating Models and Database Tables

After defining a content type, you need to generate the corresponding model class and database table:

```bash
./yii crelish/content-type/generate page
```

This command:
- Reads the content type definition from `workspace/elements/page.json`
- Creates or updates the database table for the content type
- Generates a model class in `workspace/models`

## First Steps

### Accessing the Admin Panel

After installation, you can access the admin panel at:

```
https://your-domain.com/crelish
```

The default login credentials are:

- Username: `admin`
- Password: `admin`

**Important**: Change the default password immediately after your first login.

### Creating Your First Content

1. Log in to the admin panel
2. Navigate to "Content" in the sidebar
3. Select the content type you want to create (e.g., "Page")
4. Click "Add New" and fill in the required fields
5. Save your content

### Managing Content Types

1. Navigate to "Elements" in the sidebar
2. View and edit existing content types
3. Create new content types as needed
4. After making changes, run the generator command to update models and tables:
   ```bash
   ./yii crelish/content-type/generate your-content-type
   ```

### Accessing Content via API

You can access your content via the API:

```
GET https://your-domain.com/api/content/page
```

See the [API Documentation](./API.md) for more details.

## Project Structure

After setting up Crelish CMS, your project structure should include:

```
your-project/
├── config/
│   └── web.php (Crelish configuration)
├── workspace/
│   ├── elements/ (Content type definitions)
│   │   ├── page.json
│   │   └── ...
│   └── models/ (Generated model classes)
│       ├── Page.php
│       └── ...
└── vendor/
    └── giantbits/
        └── yii2-crelish/ (Crelish CMS files)
```

## Next Steps

- [Configure authentication](./authentication.md) for your API
- [Create custom content types](./content-types.md)
- [Integrate with frontend frameworks](./frontend-integration.md)
- [Extend Crelish with plugins](./extending.md)
- [Work with widgets](./widgets.md)
- [Use the documentation viewer](./documentation-viewer.md)
- [Troubleshoot common issues](./troubleshooting.md) 