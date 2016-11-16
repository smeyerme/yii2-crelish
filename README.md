# yii2-crelish
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
