# yii2-crelish 0.4.8
Content management for the Yii2 framework.

## Installation

1. Create Yii2 Basic project
```
composer global require "fxp/composer-asset-plugin:^1.2.0"
composer create-project --prefer-dist yiisoft/yii2-app-basic {projectname}
```
2. Install yii2-crelish
```
composer require giantbits/yii2-crelish
```

## Setup

### configuration
Edit your web.php config file
```
// Add crelish to the bootstrap phase.
'bootstrap' => ['crelish', '...'],

// Configure components.
// Set default route.
'defaultRoute' => 'frontend/index',

// Configure view component to use twig.
'view' => [
  'class' => 'yii\web\View',
  'renderers' => [
    'twig' => [
      'class' => 'yii\twig\ViewRenderer',
      'cachePath' => '@runtime/Twig/cache',
      // Array of twig options:
      'options' => [
        'auto_reload' => true,
      ],
      'globals' => ['html' => '\yii\helpers\Html'],
      'uses' => ['yii\bootstrap']
    ],
  ]
]

// Enable file cache.
'cache' => [
  'class' => 'yii\caching\FileCache'
]

// Enable swift mailer.
'mailer' => [
  'class' => 'yii\swiftmailer\Mailer',
  'useFileTransport' => TRUE
]

// Use crelish user class
'user' => [
  'class'=>'giantbits\crelish\components\CrelishUser',
  'identityClass' => 'giantbits\crelish\components\CrelishUser',
  'enableAutoLogin' => true,
],

// Enable basic URL Manager.
'urlManager' => [
  'enablePrettyUrl' => TRUE,
  'showScriptName' => FALSE,
  'enableStrictParsing' => TRUE,
  'suffix' => '.html',
  'rules' => [
    // ...
  ]
]

// Finaly add Crelish to the config. (After component configuration)
$config['modules']['crelish'] = [
  'class' => 'giantbits\crelish\Module',
  'theme' => 'klangfarbe'
];
```
### Setting up folders
Create the following structure in you project root folder.

```
workspace/
	data/
		*Create folders for evey content type you use.*
		asset/
			*Content items of this type are stored here.*
			2131123123123123.json
			...
		page/
			*Content items of this type are stored here.*
			2131123123123123.json
			...
	elements/
		*Definitions for each content type are stored here.*
		asset.json
		page.json
	widgets/
```

## Important additions

Currently the system relies on two cintent types to b epresent. Paste the following code into the folder workspace/elements/.

```
// File name: asset.json

{
  "key": "asset",
  "label": "Asset",
  "selectable": false,
  "tabs": [
  ],
  "groups": [
  ],
  "fields": [
    { "label": "Title internal", "key": "systitle", "type": "textInput", "visibleInGrid": true, "rules": [["required"], ["string", {"max": 128}]]},
    { "label": "Title", "key": "title", "type": "textInput", "visibleInGrid": false, "rules": [["required"], ["string", {"max": 128}]]},
    { "label": "Description",  "key": "description", "type": "textInput", "visibleInGrid": false, "rules": [["required"],["string", {"max": 320}]]},
    { "label": "MIME-Type",  "key": "mime", "type": "textInput", "visibleInGrid": false, "rules": [["required"],["string", {"max": 128}]]},
    { "label": "Source",  "key": "src", "type": "textInput", "visibleInGrid": false, "rules": [["required"],["string", {"max": 256}]]},
    { "label": "Size",  "key": "size", "type": "textInput", "visibleInGrid": false, "rules": [["required"],["integer"]]},
    { "label": "Dominant color HEX",  "key": "colormain_hex", "type": "textInput", "visibleInGrid": false, "rules": [["safe"]]},
    { "label": "Dominant color RGB",  "key": "colormain_rgb", "type": "textInput", "visibleInGrid": false, "rules": [["safe"]]},
    { "label": "Color palette",  "key": "colorpalette", "type": "textInput", "visibleInGrid": false, "rules": [["safe"]]}
  ]
}

```

```
// File name: page.json

{
  "key": "page",
  "label": "Page",
  "tabs": [
    {
      "label": "Content",
      "key": "content",
      "groups": [
        {
          "label": "Content",
          "key": "content",
          "settings": {
            "width": "60"
          },
          "fields": [
            "displaytitle",
            "body",
            "matrix"
          ]
        },
        {
          "label": "Settings",
          "key": "settings",
          "settings": {
            "width": "40"
          },
          "fields": [
            "systitle",
            "navtitle",
            "metadescription",
            "metakeywords"
          ]
        }
      ]
    }
  ],
  "fields": [
    {
      "label": "Title internal",
      "key": "systitle",
      "type": "textInput",
      "visibleInGrid": true,
      "rules": [
        [
          "required"
        ],
        [
          "string",
          {
            "max": 128
          }
        ]
      ]
    },
    {
      "label": "Title display",
      "key": "displaytitle",
      "type": "textInput",
      "visibleInGrid": false,
      "rules": [
        [
          "required"
        ],
        [
          "string",
          {
            "max": 128
          }
        ]
      ]
    },
    {
      "label": "Title navigation",
      "key": "navtitle",
      "type": "textInput",
      "visibleInGrid": false,
      "rules": [
        [
          "required"
        ],
        [
          "string",
          {
            "max": 128
          }
        ]
      ]
    },
    {
      "label": "Meta-Description",
      "key": "metadescription",
      "type": "textInput",
      "visibleInGrid": false,
      "rules": [
        [
          "required"
        ],
        [
          "string",
          {
            "max": 128
          }
        ]
      ]
    },
    {
      "label": "Meta-Keywords",
      "key": "metakeywords",
      "type": "textInput",
      "visibleInGrid": false,
      "rules": [
        [
          "required"
        ],
        [
          "string",
          {
            "max": 128
          }
        ]
      ]
    },
    {
      "label": "Content",
      "key": "body",
      "type": "widget_\\yii\\redactor\\widgets\\Redactor",
      "visibleInGrid": false,
      "rules": [
        [
          "required"
        ],
        [
          "string",
          {
            "max": 2300
          }
        ]
      ]
    },
    {
      "label": "References",
      "key": "matrix",
      "type": "matrixConnector",
      "rules": [
        [
          "safe"
        ]
      ]
    }
  ]
}


```

## Content Type Generator

Crelish now provides a command-line tool to streamline the process of creating new content types. This tool automatically generates both the database table and the model class based on a JSON element definition.

### Usage

1. Create a JSON element definition file in `@app/workspace/elements/` (e.g., `boardgame.json`)
2. Run the generator command:

```bash
./yii content-type/generate boardgame
```

This will:
- Create a database table for the content type
- Generate a model class with appropriate getters/setters for JSON fields
- Set up relations based on the element definition

### Listing Available Element Definitions

To see all available element definitions:

```bash
./yii content-type/list
```

### Element Definition Format

The element definition should follow the standard Crelish format, with the addition of a `storage` property to specify whether to use database or JSON storage:

```json
{
  "key": "boardgame",
  "storage": "db",
  "label": "Board Games",
  "category": "Content",
  "fields": [
    {
      "label": "Title",
      "key": "systitle",
      "type": "textInput",
      "visibleInGrid": true,
      "rules": [
        ["required"],
        ["string", {"max": 128}]
      ],
      "sortable": true
    },
    {
      "label": "Mechanics",
      "key": "mechanics",
      "type": "checkboxList",
      "transform": "json",
      "visibleInGrid": false,
      "rules": [
        ["safe"]
      ]
    },
    {
      "label": "Cover Image",
      "key": "coverImage",
      "type": "assetConnector",
      "visibleInGrid": false,
      "rules": [
        ["safe"]
      ]
    }
  ]
}
```

The generator will automatically:
- Map field types to appropriate database column types
- Create getters and setters for JSON fields
- Set up relations for fields of type `relationSelect` and `assetConnector`

#### Special Field Types

- **relationSelect**: Creates a relation to another content type. Requires a `config.ctype` property to specify the target content type.
- **assetConnector**: Creates a relation to the asset content type. This is automatically handled without additional configuration.
- **matrixConnector**: Stored as JSON data for complex structured content.
- **widgetConnector**: Stored as JSON data for connecting widgets to content items.
- Fields with `"transform": "json"`: Automatically handled with getters and setters for working with JSON data.
