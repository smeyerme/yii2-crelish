# Working with Content Types

Content types are the foundation of Crelish CMS. They define the structure and validation rules for your content.

## What is a Content Type?

A content type is a schema that defines:

- The fields that make up a content item
- Validation rules for each field
- Display settings for the admin interface
- Relationships between content types

## Content Type Definition

Content types are defined in JSON files located in the `config/content-types` directory. Each content type has its own file, named after the content type (e.g., `page.json`, `article.json`).

### Basic Structure

```json
{
  "name": "page",
  "label": "Page",
  "description": "Basic page content type",
  "fields": {
    // Field definitions go here
  }
}
```

### Field Types

Crelish supports the following field types:

| Type | Description | Example |
|------|-------------|---------|
| `string` | Text field | Title, name, description |
| `text` | Multi-line text | Content, long description |
| `integer` | Whole number | Order, count |
| `float` | Decimal number | Price, rating |
| `boolean` | True/false value | Published, featured |
| `date` | Date value | Publication date |
| `datetime` | Date and time | Created at, updated at |
| `email` | Email address | Contact email |
| `url` | URL | Website, social media link |
| `enum` | Selection from predefined values | Status, category |
| `array` | List of values | Tags, categories |
| `object` | Nested object | Address, metadata |
| `reference` | Reference to another content item | Author, related pages |
| `file` | File upload | Document, image |
| `image` | Image upload with processing | Featured image, gallery |

### Field Properties

Each field can have the following properties:

```json
"title": {
  "type": "string",
  "label": "Title",
  "description": "The title of the page",
  "required": true,
  "minLength": 3,
  "maxLength": 255,
  "default": "New Page",
  "placeholder": "Enter page title",
  "help": "The title appears at the top of the page and in search results"
}
```

Common properties for all field types:

- `type`: The field type (required)
- `label`: Human-readable label (required)
- `description`: Description of the field
- `required`: Whether the field is required (default: false)
- `default`: Default value
- `placeholder`: Placeholder text for input fields
- `help`: Help text to display in the admin interface

Type-specific properties:

- `string`:
  - `minLength`: Minimum length
  - `maxLength`: Maximum length
  - `pattern`: Regular expression pattern
  
- `integer`/`float`:
  - `min`: Minimum value
  - `max`: Maximum value
  - `step`: Step value for input controls
  
- `enum`:
  - `values`: Array of allowed values
  - `multiple`: Allow multiple selections (default: false)
  
- `array`:
  - `minItems`: Minimum number of items
  - `maxItems`: Maximum number of items
  - `items`: Schema for array items
  
- `reference`:
  - `contentType`: Referenced content type
  - `multiple`: Allow multiple references (default: false)
  
- `image`:
  - `maxSize`: Maximum file size in bytes
  - `formats`: Allowed formats (e.g., ["jpg", "png", "gif"])
  - `presets`: Image processing presets

## Example Content Types

### Basic Page

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
      "type": "text",
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

### Blog Post

```json
{
  "name": "post",
  "label": "Blog Post",
  "description": "Blog post content type",
  "fields": {
    "title": {
      "type": "string",
      "label": "Title",
      "description": "Post title",
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
      "type": "text",
      "label": "Content",
      "description": "Post content in HTML format",
      "required": true
    },
    "excerpt": {
      "type": "text",
      "label": "Excerpt",
      "description": "Short summary of the post",
      "required": false,
      "maxLength": 500
    },
    "featured_image": {
      "type": "image",
      "label": "Featured Image",
      "description": "Main image for the post",
      "required": false,
      "formats": ["jpg", "png", "webp"],
      "presets": ["thumbnail", "medium", "large"]
    },
    "author_id": {
      "type": "reference",
      "label": "Author",
      "description": "Post author",
      "required": true,
      "contentType": "author"
    },
    "categories": {
      "type": "reference",
      "label": "Categories",
      "description": "Post categories",
      "required": false,
      "contentType": "category",
      "multiple": true
    },
    "tags": {
      "type": "array",
      "label": "Tags",
      "description": "Post tags",
      "required": false,
      "items": {
        "type": "string"
      }
    },
    "published_at": {
      "type": "datetime",
      "label": "Published At",
      "description": "Publication date and time",
      "required": false
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

## Working with Content Types in Code

### Registering Content Types

Content types are automatically registered when placed in the `config/content-types` directory. The directory path can be configured in your application configuration:

```php
'components' => [
    'contentService' => [
        'class' => 'giantbits\crelish\components\ContentService',
        'contentTypesPath' => '@app/config/content-types',
    ],
],
```

### Accessing Content Types

You can access content types through the `contentService` component:

```php
// Check if a content type exists
$exists = Yii::$app->contentService->contentTypeExists('page');

// Get content type definition
$definition = Yii::$app->contentService->getContentTypeDefinition('page');

// Get a query for content items
$query = Yii::$app->contentService->getQuery('page');
$items = $query->all();

// Get a single content item
$item = Yii::$app->contentService->getContentById('page', '123');
```

### Creating and Updating Content

```php
// Create a new content item
$result = Yii::$app->contentService->createContent('page', [
    'title' => 'Hello World',
    'slug' => 'hello-world',
    'content' => '<p>This is my first page.</p>',
    'status' => 'published',
]);

// Update an existing content item
$result = Yii::$app->contentService->updateContent('page', '123', [
    'title' => 'Updated Title',
    'content' => '<p>This is the updated content.</p>',
]);

// Delete a content item
$result = Yii::$app->contentService->deleteContent('page', '123');
```

## Best Practices

1. **Use descriptive names**: Choose clear, descriptive names for content types and fields.
2. **Keep it simple**: Start with the essential fields and add more as needed.
3. **Be consistent**: Use consistent naming conventions across content types.
4. **Use references**: Use references to create relationships between content types.
5. **Document your content types**: Add descriptions to content types and fields.
6. **Validate thoroughly**: Define appropriate validation rules for each field.
7. **Consider the user experience**: Organize fields in a logical order for content editors. 