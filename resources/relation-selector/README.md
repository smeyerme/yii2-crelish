# Relation Selector

A Vue 3 component for selecting and ordering relations in Crelish CMS.

## Features

- Select related items using a searchable dropdown (Select2)
- Add, remove, and reorder items without page reloads
- JSON-based data storage for form submission
- API-based item lookup for better performance
- Full keyboard accessibility
- Customizable columns for the items table

## Installation

```bash
cd resources/relation-selector
npm install
npm run build
```

## Usage

To use this component, you need to add the necessary markup to your templates and ensure the compiled JavaScript is included.

### Include scripts

Include the compiled JavaScript file:

```html
<script src="/path/to/relation-selector.js"></script>
```

### Template markup

Add a container element with the necessary data attributes:

```html
<div class="relation-selector-container"
     data-field-key="related_items"
     data-content-type="article"
     data-value='["uuid1", "uuid2"]'
     data-input-name="CrelishDynamicModel[related_items]"
     data-label="Related Articles"
     data-required="true"
     data-columns='[{"key": "systitle", "label": "Title"}, {"key": "published_date", "label": "Date"}]'
     data-filter-fields='["systitle", "description", "tags"]'>
</div>
```

### Available data attributes

- `data-field-key`: Field identifier (required)
- `data-content-type`: The type of content to relate (required)
- `data-value`: JSON array of UUIDs (required, can be empty array)
- `data-input-name`: Name of the hidden input for form submission (required)
- `data-label`: Field label (optional)
- `data-required`: Whether the field is required (optional, defaults to false)
- `data-columns`: JSON array of column definitions (optional)
- `data-translations`: JSON object of custom translations (optional)
- `data-filter-fields`: JSON array of field names to use for filtering (optional, defaults to ["systitle"])

### Column definition format

```json
[
  {"key": "systitle", "label": "Title"},
  {"key": "created_at", "label": "Created Date"}
]
```

### Translations

You can provide translations either globally or per component:

```html
<script>
window.relationSelectorTranslations = {
  choosePlaceholder: "Select an item...",
  addButton: "Add",
  assignedItems: "Assigned Items",
  actions: "Actions",
  noItemsSelected: "No items selected",
  itemAlreadyAdded: "This item has already been added",
  loadingOptions: "Loading options..."
};
</script>
```

Or per component:

```html
<div class="relation-selector-container"
     data-translations='{"choosePlaceholder": "Select an item..."}'>
</div>
```

## Events

The component dispatches a custom event when relations are updated:

```javascript
document.querySelector('.relation-selector-container').addEventListener('relation-updated', (event) => {
  console.log('Relations updated:', event.detail.value);
});
``` 