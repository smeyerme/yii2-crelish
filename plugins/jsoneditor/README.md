# JSON Editor Plugin for Crelish CMS

This plugin provides a rich JSON editing experience in the Crelish CMS, allowing users to edit JSON data with a schema-driven interface.

## Features

- Schema-based JSON editing
- Multiple edit modes (tree, code, form, text, view)
- Validation against JSON Schema
- Support for translatable JSON fields
- Default value generation from schema

## Usage

To use the JSON Editor in your content type definitions, add a field with type `jsonEditor`:

```json
{
  "label": "Configuration",
  "key": "config",
  "type": "jsonEditor",
  "visibleInGrid": false,
  "translatable": true,
  "rules": [
    [
      "safe"
    ]
  ],
  "schema": {
    "type": "object",
    "title": "Configuration",
    "properties": {
      "title": {
        "type": "string",
        "title": "Title"
      },
      "is_enabled": {
        "type": "boolean",
        "title": "Enabled",
        "default": true
      },
      "settings": {
        "type": "object",
        "title": "Settings",
        "properties": {
          "color": {
            "type": "string",
            "title": "Color",
            "default": "#ffffff"
          },
          "size": {
            "type": "number",
            "title": "Size",
            "default": 100
          }
        }
      }
    }
  }
}
```

## Schema Support

The JSON Editor uses [JSON Schema](https://json-schema.org/) to define the structure of your data. You can specify:

- Property types (string, number, boolean, object, array)
- Enumerations (predefined values)
- Default values
- Required properties
- Custom validation rules

For array items, you can define:

```json
"schema": {
  "type": "array",
  "title": "Items",
  "items": {
    "type": "object",
    "title": "Item",
    "properties": {
      "name": {
        "type": "string",
        "title": "Name"
      },
      "value": {
        "type": "number",
        "title": "Value"
      }
    }
  }
}
```

## Installation

The plugin requires the JSONEditor JavaScript library. To install it, run:

```bash
cd resources/jsoneditor
node install.js
```

Alternatively, the plugin will use a CDN version if the local library is not found.

## Translations

The JSON Editor supports translatable fields through the standard Crelish translation system. Set `"translatable": true` in your field definition to enable translations. 