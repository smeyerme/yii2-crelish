# Working with Content Types

Content types are the foundation of Crelish CMS. They define the structure and validation rules for your content.

## What is a Content Type?

A content type is a schema that defines:

- The fields that make up a content item
- Validation rules for each field
- Display settings for the admin interface
- Relationships between content types

## Content Type Definition

Content types are defined in JSON files located in the `workspace/elements` directory of your application (not within the Crelish package itself). Each content type has its own file, named after the content type (e.g., `page.json`, `article.json`, `boardgame.json`).

### Managing Content Types

There are two ways to manage content types in Crelish CMS:

1. **Using the ElementsController**: Crelish provides a web interface to create, read, update, and delete content type definitions through the ElementsController. You can access this interface at `/crelish/elements` in your admin panel.

2. **Manually editing JSON files**: You can also create and edit the JSON files directly in the `workspace/elements` directory.

### ContentTypeController Command

After defining your content types, you need to generate the corresponding database tables and model classes. Crelish provides a console command for this purpose:

```bash
./yii crelish/content-type/generate boardgame
```

This command:
- Reads the content type definition from `workspace/elements/boardgame.json`
- Creates or updates the database table based on the fields defined
- Generates a model class in `workspace/models`

To see a list of all available content types:

```bash
./yii crelish/content-type/list
```

### Basic Structure

```json
{
  "name": "boardgame",
  "label": "Board Game",
  "description": "Board game content type",
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

## Creating a Content Type

### Using the ElementsController (Recommended)

1. Log in to the Crelish admin panel
2. Navigate to "Elements" in the sidebar
3. Click "Add New" to create a new content type
4. Define your fields and validation rules
5. Save the content type
6. Run the generator command to create the database table and model

### Manually Creating a JSON File

1. Create a new JSON file in `workspace/elements` (e.g., `boardgame.json`)
2. Define the content type structure:

```json
{
  "name": "boardgame",
  "label": "Board Game",
  "description": "Content type for board games",
  "fields": {
    "title": {
      "type": "string",
      "label": "Title",
      "description": "The name of the board game",
      "required": true,
      "minLength": 3,
      "maxLength": 255
    },
    "description": {
      "type": "text",
      "label": "Description",
      "description": "Full description of the board game",
      "required": true
    },
    "players": {
      "type": "object",
      "label": "Players",
      "description": "Number of players",
      "fields": {
        "min": {
          "type": "integer",
          "label": "Minimum Players",
          "required": true
        },
        "max": {
          "type": "integer",
          "label": "Maximum Players",
          "required": true
        }
      }
    },
    "playtime": {
      "type": "integer",
      "label": "Playtime (minutes)",
      "description": "Average playing time in minutes",
      "required": true
    },
    "image": {
      "type": "file",
      "label": "Box Image",
      "description": "Image of the game box",
      "accept": "image/*"
    },
    "categories": {
      "type": "array",
      "label": "Categories",
      "description": "Game categories",
      "items": {
        "type": "string"
      }
    },
    "publisher": {
      "type": "reference",
      "label": "Publisher",
      "description": "Game publisher",
      "contentType": "publisher"
    },
    "status": {
      "type": "enum",
      "label": "Status",
      "description": "Publication status",
      "values": ["draft", "published", "archived"],
      "default": "draft"
    }
  }
}
```

3. Run the generator command to create the database table and model:

```bash
./yii crelish/content-type/generate boardgame
```

## Field Configuration

Each field in a content type can have the following properties:

| Property | Description | Example |
|----------|-------------|---------|
| `type` | Field data type | `"type": "string"` |
| `label` | Human-readable label | `"label": "Title"` |
| `description` | Help text for the field | `"description": "Enter the title"` |
| `required` | Whether the field is required | `"required": true` |
| `default` | Default value | `"default": "Draft"` |
| `minLength` | Minimum text length | `"minLength": 3` |
| `maxLength` | Maximum text length | `"maxLength": 255` |
| `min` | Minimum numeric value | `"min": 0` |
| `max` | Maximum numeric value | `"max": 100` |
| `pattern` | Regex validation pattern | `"pattern": "^[A-Z][a-z]+$"` |
| `accept` | File type filter | `"accept": "image/*"` |
| `multiple` | Allow multiple values | `"multiple": true` |
| `values` | Enum possible values | `"values": ["draft", "published"]` |
| `contentType` | Referenced content type | `"contentType": "author"` |
| `fields` | Nested fields (for objects) | `"fields": {...}` |
| `items` | Array item definition | `"items": {"type": "string"}` |

## Content Relationships

Crelish supports relationships between content types using the `reference` field type:

```json
"author": {
  "type": "reference",
  "label": "Author",
  "description": "Content author",
  "contentType": "user",
  "multiple": false
}
```

This creates a relationship to the `user` content type. When you use this field in the admin interface, you'll be able to select from a list of user content items.

## Generated Models

When you run the `generate` command, Crelish creates a model class for your content type in `workspace/models`. For example, a `boardgame` content type would generate a `Boardgame` model class.

This model class extends `\giantbits\crelish\models\CrelishActiveRecord` and provides all the necessary methods for working with your content type.

Example usage in code:

```php
use app\workspace\models\Boardgame;

// Find all published board games
$games = Boardgame::find()
    ->where(['status' => 'published'])
    ->orderBy(['title' => SORT_ASC])
    ->all();

// Create a new board game
$game = new Boardgame();
$game->title = 'Settlers of Catan';
$game->description = 'A popular strategy board game';
$game->players = ['min' => 3, 'max' => 4];
$game->playtime = 90;
$game->categories = ['Strategy', 'Resource Management'];
$game->status = 'published';
$game->save();
```

## Advanced Field Types

### Object Fields

Object fields allow you to create nested structures:

```json
"location": {
  "type": "object",
  "label": "Location",
  "fields": {
    "latitude": {
      "type": "float",
      "label": "Latitude"
    },
    "longitude": {
      "type": "float",
      "label": "Longitude"
    },
    "address": {
      "type": "string",
      "label": "Address"
    }
  }
}
```

### Array Fields

Array fields store multiple values:

```json
"tags": {
  "type": "array",
  "label": "Tags",
  "items": {
    "type": "string"
  }
}
```

### Reference Fields

Reference fields create relationships to other content types:

```json
"relatedArticles": {
  "type": "reference",
  "label": "Related Articles",
  "contentType": "article",
  "multiple": true
}
```

## Best Practices

1. **Use Clear Names**: Choose descriptive names for your content types and fields
2. **Group Related Fields**: Use object fields to group related information
3. **Include Validation**: Add appropriate validation rules to ensure data quality
4. **Document Your Fields**: Include clear descriptions for all fields
5. **Consider Performance**: Be mindful of complex relationships between content types
6. **Use Consistent Naming**: Follow a consistent naming convention for your content types and fields 