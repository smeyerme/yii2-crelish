# JSON Editor New Plugin for Crelish CMS

This plugin provides a user-friendly schema-based form editor using [@json-editor/json-editor](https://github.com/json-editor/json-editor). Unlike the original `jsonEditor` plugin, this one generates actual HTML form inputs (text fields, dropdowns, checkboxes, etc.) from your JSON Schema, providing a much cleaner editing experience for end users.

## Features

- Schema-driven form generation
- Bootstrap 5 styled form inputs
- Validation against JSON Schema
- Support for translatable JSON fields
- Default value generation from schema
- Collapsible sections for complex structures

## When to Use

- Use `jsonEditorNew` for complex structured data that regular users need to edit (like event configurations, pricing structures, etc.)
- Use `jsonEditor` when you need raw JSON editing or tree/code views

## Usage

To use the JSON Editor New in your content type definitions, add a field with type `jsonEditorNew`:

```json
{
  "label": "Registration Config",
  "key": "registration_config",
  "type": "jsonEditorNew",
  "visibleInGrid": false,
  "rules": [["safe"]],
  "schema": {
    "type": "object",
    "title": "Registration Configuration",
    "properties": {
      "enabled": {
        "type": "boolean",
        "title": "Registration Enabled",
        "default": true
      },
      "max_participants": {
        "type": "integer",
        "title": "Maximum Participants",
        "default": 100
      },
      "pricing": {
        "type": "object",
        "title": "Pricing",
        "properties": {
          "early_bird": {
            "type": "number",
            "title": "Early Bird Price"
          },
          "regular": {
            "type": "number",
            "title": "Regular Price"
          }
        }
      }
    }
  }
}
```

## Schema Support

The editor uses [JSON Schema](https://json-schema.org/) to define the structure of your data. You can specify:

- Property types (string, number, integer, boolean, object, array)
- Enumerations (predefined values)
- Default values
- Required properties
- Titles and descriptions for each field

### Example with Enums

```json
{
  "type": "string",
  "title": "Status",
  "enum": ["draft", "published", "archived"],
  "default": "draft"
}
```

### Example with Arrays

```json
{
  "type": "array",
  "title": "Speakers",
  "items": {
    "type": "object",
    "title": "Speaker",
    "properties": {
      "name": {
        "type": "string",
        "title": "Name"
      },
      "bio": {
        "type": "string",
        "title": "Biography"
      }
    }
  }
}
```

## Dependencies

The plugin loads the @json-editor/json-editor library from CDN automatically. No additional installation required.

## Translations

The JSON Editor New supports translatable fields through the standard Crelish translation system. Set `"translatable": true` in your field definition to enable translations.