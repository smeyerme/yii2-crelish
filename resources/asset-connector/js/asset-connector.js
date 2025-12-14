import { createApp } from 'vue';
import AssetConnector from './components/AssetConnector.vue';
import MultiAssetConnector from './components/MultiAssetConnector.vue';

// Initialize all asset connector instances on the page
document.addEventListener('DOMContentLoaded', () => {
  // Find all asset connector containers
  const containers = document.querySelectorAll('.asset-connector-container');

  // Get global translations if available
  let globalTranslations = {};
  try {
    // Check if translations are available from a global variable
    if (window.assetConnectorTranslations) {
      globalTranslations = window.assetConnectorTranslations;
    }
  } catch (error) {
    // Ignore errors
  }

  containers.forEach((container, index) => {
    // Get the configuration from the data attributes
    const isMultiple = container.dataset.multiple === 'true';
    const maxItems = container.dataset.maxItems ? parseInt(container.dataset.maxItems, 10) : null;
    const mimeFilter = container.dataset.mimeFilter || '';

    // Parse value based on mode
    let value;
    if (isMultiple) {
      // Multiple mode: parse JSON array
      try {
        const rawValue = container.dataset.value;
        if (rawValue && rawValue.trim()) {
          value = JSON.parse(rawValue);
          if (!Array.isArray(value)) {
            // Single value came through, wrap in array
            value = value ? [value] : [];
          }
        } else {
          value = [];
        }
      } catch (e) {
        console.error('Failed to parse multiple asset value:', e);
        value = [];
      }
    } else {
      // Single mode: string value
      value = container.dataset.value || null;
    }

    const config = {
      value: value,
      fieldKey: container.dataset.fieldKey,
      label: container.dataset.label || '',
      inputName: container.dataset.inputName || null,
      required: container.dataset.required === 'true',
      multiple: isMultiple,
      maxItems: maxItems,
      mimeFilter: mimeFilter
    };

    // Check for container-specific translations as JSON in a data attribute
    let translations = { ...globalTranslations };
    if (container.dataset.translations) {
      try {
        const containerTranslations = JSON.parse(container.dataset.translations);
        // Merge with any global translations, with container translations taking precedence
        translations = { ...translations, ...containerTranslations };
      } catch (error) {
        // If JSON parsing fails, continue with global translations
        console.warn('Failed to parse translations JSON', error);
      }
    }

    try {
      // Find the hidden input element
      const hiddenInput = document.getElementById(`asset_${config.fieldKey}`);

      // Choose component based on mode
      const Component = isMultiple ? MultiAssetConnector : AssetConnector;

      // Build props based on mode
      const props = {
        modelValue: config.value,
        fieldKey: config.fieldKey,
        label: config.label,
        inputName: config.inputName,
        required: config.required,
        translations: translations,
        'onUpdate:modelValue': (newValue) => {
          // Update the hidden input if it exists
          if (hiddenInput) {
            if (isMultiple) {
              // Multiple mode: JSON stringify the array
              hiddenInput.value = JSON.stringify(newValue || []);
              container.dataset.value = JSON.stringify(newValue || []);
            } else {
              // Single mode: string value
              hiddenInput.value = newValue || '';
              container.dataset.value = newValue || '';
            }

            // Trigger a change event on the hidden input
            const event = new Event('change', { bubbles: true });
            hiddenInput.dispatchEvent(event);
          }
        },
        onUpdateValue: (value) => {
          // Update the hidden input if it exists
          if (hiddenInput) {
            if (isMultiple) {
              hiddenInput.value = JSON.stringify(value || []);
              container.dataset.value = JSON.stringify(value || []);
            } else {
              hiddenInput.value = value || '';
              container.dataset.value = value || '';
            }

            // Trigger a change event on the hidden input
            const event = new Event('change', { bubbles: true });
            hiddenInput.dispatchEvent(event);
          }

          // Dispatch a custom event
          container.dispatchEvent(new CustomEvent('asset-selected', {
            detail: { value, multiple: isMultiple }
          }));
        }
      };

      // Add multiple-specific props
      if (isMultiple) {
        props.multiple = true;
        if (maxItems) {
          props.maxItems = maxItems;
        }
        if (mimeFilter) {
          props.mimeFilterDefault = mimeFilter;
        }
      }

      // Create a Vue app for this container
      const app = createApp(Component, props);

      app.mount(container);
    } catch (error) {
      // Handle error silently
      console.error('Error mounting asset connector', error);
    }
  });
});