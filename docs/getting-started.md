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

Create the first admin on the server:

```bash
php yii crelish/admin/create-default-admin
```

Only in `YII_ENV_DEV` does the login page create a default admin (`admin@local.host`) when the user table is empty; change its password immediately.

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
GET https://your-domain.com/crelish-api/content/page
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

## Previewing unpublished pages

Pages that are offline (state other than 2) or outside their from/to window return 404 for everyone. To check or share such a page, open it in the admin and use the **Preview** button in the header bar of the edit view (pages only, not on create). It opens a signed preview link in a new tab; the link button next to it copies the link for sharing, e.g. with a client.

The link is the page's normal URL in the default content language plus `?preview=<token>`. The token holds the page uuid and an expiry time and is signed (HMAC via `Yii::$app->security->hashData()`). It is valid only for that page and until it expires; a tampered, expired or foreign token gives the usual 404. A published page ignores the parameter and is served as normal, so the buttons are shown for published pages too.

```php
'params' => [
    'crelish' => [
        'previewTtl' => 86400,           // link lifetime in seconds (default: 24 hours)
        'previewSecret' => '<random>',   // signing key, at least 32 characters; defaults to request.cookieValidationKey
    ],
],
```

Changing `previewSecret` (or the cookie validation key it falls back to) invalidates all issued links. A `previewSecret` that is not a string of at least 32 characters is ignored (with a warning in the log) and the cookie validation key is used. Without either key the buttons are hidden and no token validates.

A preview response sends `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`, adds `<meta name="robots" content="noindex, nofollow">` and `<meta name="referrer" content="no-referrer">`, is not tracked by analytics (so the token never ends up in the analytics tables), and shows a small dismissible bar at the top of the page ("Vorschau – diese Seite ist nicht veröffentlicht"). The bar is injected at `View::EVENT_BEGIN_BODY` with inline styles, so themes need no changes as long as their layout calls `beginBody()`.

In the page edit view, the frame next to the form shows an unpublished page through a fresh preview link instead of the 404, with a warning strip above it naming the reason (offline, draft, archived, or outside the publication window). Published pages are framed with their normal URL.

## Security

- **Admin access** (everything under `/crelish/`) requires a logged-in user with role 9. Guests are sent to the login form, logged-in users without the admin role to the home page, and AJAX/JSON requests get a 403; the action does not run. The check is `CrelishAccess::guard()` in `CrelishBaseController::beforeAction()`; admin controllers on another base class use `CrelishAccess::adminRule()` in their AccessControl.
- **Public exceptions** are listed in `CrelishAccess::PUBLIC_ROUTES`: `user/login`, `user/logout`, `asset/glide` and `asset/download` (images and downloads of the public site), `track/click` (frontend click tracking). The frontend, short link redirects and the API module are separate controllers.
- **API** (`/crelish-api/content/...`) requires an admin (login + role 9) for every action: the admin session, an access token, or a JWT. There is no debug endpoint.
- **Login** is by email and password only, and only for active accounts (state 2). Offline, draft/pending and archived users cannot log in, their sessions end and their tokens stop working.
- **Session cookie**: Crelish sets `httponly`, `sameSite: Lax` and, on HTTPS requests, `secure` as defaults; anything in the project's own `session` config wins.
- **`jwtSecretKey`** (`params['jwtSecretKey']`) must be a random string of at least 32 characters. If it is missing, shorter, or one of the shipped placeholders, JWT authentication is off (no tokens are issued or accepted, a warning is logged); session and access-token authentication still work.
- **`previewSecret`** (`params['crelish']['previewSecret']`) must be at least 32 characters, otherwise the cookie validation key signs preview links.
- **Translations** (admin, Translations) only write existing message files of the configured languages.

## Translations

Crelish ships its own translations for the `crelish` category (`messages/<lang>/crelish.php` in the package). They are the base; a project overrides single strings in its own `messages/<lang>/crelish.php`. Empty project values do not override the package value. Only strings found in neither file trigger the missing-translation handler (DeepL).

Projects created before 0.23.1 may hold cached machine translations that shadow Crelish's curated strings. List them with a dry run, then remove them:

```bash
php yii crelish-translations/prune de           # dry run, lists shadowing keys
php yii crelish-translations/prune de --apply   # removes them from the project file
```

`--apply` also removes deliberate project customisations of Crelish strings that share a key with the package, so review the dry run first. Nothing runs automatically on deploy.

Content translations (the `translation` table, written through the language tabs) are swapped into a record for display only: a db-backed record found while `Yii::$app->language` has a translation shows the translated values, but saving it never writes them into the default-language columns. Rows stored for the default content language itself are ignored; the columns are authoritative. Only a value you change deliberately is saved. In the admin editor the main fields always hold the default content language (the first entry of `params['crelish']['languages']`), whatever the admin UI language; every other language is edited in its translation tab. An empty translation field means the default-language value is used (text fields show it as placeholder; selection fields keep the default preselected), and a translation saved identical to the default value is not stored (an existing row is removed). Code that needs the raw column values can wrap the lookup in `CrelishTranslationBehavior::withoutTranslations(fn() => …)`.

Languages are compared by two-letter code, so with `['de-CH', 'en']` an app language `de` counts as the default content language. A language list with two locales of the default language (e.g. `['de', 'de-CH', 'en']`) is therefore not supported: `de-CH` would be treated as the default language, so its rows are ignored and removed by `stale-defaults`. For a regional locale such as `en-US`, each field uses its `en-US` row if there is one and otherwise its `en` row. Auto-translate (DeepL) always translates from the default content language, not from Yii's `sourceLanguage`.

Older versions could store translation rows for the default content language itself. They are ignored, and you can remove them:

```bash
php yii crelish-translations/stale-defaults           # dry run, counts per source model
php yii crelish-translations/stale-defaults --apply   # deletes them
```

## Next Steps

- [Configure authentication](./authentication.md) for your API
- [Create custom content types](./content-types.md)
- [Integrate with frontend frameworks](./frontend-integration.md)
- [Extend Crelish with plugins](./extending.md)
- [Work with widgets](./widgets.md)
- [Use the documentation viewer](./documentation-viewer.md)
- [Troubleshoot common issues](./troubleshooting.md) 