# Asset Connector Vue Component

A Vue 3 component for selecting and managing assets in Crelish.

## Features

- Modern Vue 3 component-based architecture
- Search and filter assets
- Upload new assets directly from the component
- Responsive grid layout for asset selection
- Pagination with "Load More" functionality
- Preview images for selected assets
- Support for various file types

## Installation

1. Navigate to the component directory:

```bash
cd resources/asset-connector
```

2. Install dependencies:

```bash
npm install
```

3. Build the component:

```bash
npm run build
```

For development with hot-reloading:

```bash
npm run dev
```

## Usage

### PHP Integration

To use the Vue 3 version of the AssetConnector, update your field type configuration to use the `AssetConnectorVue` class instead of the original `AssetConnector`.

```php
// In your field type configuration
'assetConnector' => [
    'class' => 'giantbits\\crelish\\plugins\\assetconnector\\AssetConnectorVue'
]
```

### API Endpoints

The component requires the following API endpoints to be available:

- `/crelish/asset/api-search` - For searching assets with pagination
- `/crelish/asset/api-get` - For getting a specific asset by UUID
- `/crelish/asset/api-upload` - For uploading new assets

Make sure these endpoints are properly implemented in your backend.

## Component Structure

- `AssetConnector.vue` - The main Vue component
- `asset-connector.js` - Entry point that initializes the component
- `webpack.config.js` - Webpack configuration for building the component
- `package.json` - NPM package configuration

## Customization

You can customize the component's appearance by modifying the CSS in the `<style>` section of the `AssetConnector.vue` file.

## Browser Compatibility

This component is compatible with all modern browsers that support Vue 3:

- Chrome
- Firefox
- Safari
- Edge

## License

This component is part of the Crelish CMS and follows the same licensing terms. 