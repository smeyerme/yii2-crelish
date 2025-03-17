import { createApp } from 'vue';
import AssetConnector from './components/AssetConnector.vue';

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
    const config = {
      value: container.dataset.value || null,
      fieldKey: container.dataset.fieldKey,
      label: container.dataset.label || '',
      inputName: container.dataset.inputName || null,
      required: container.dataset.required === 'true'
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
      
      // Create a Vue app for this container
      const app = createApp(AssetConnector, {
        modelValue: config.value,
        fieldKey: config.fieldKey,
        label: config.label,
        inputName: config.inputName,
        required: config.required,
        translations: translations,
        'onUpdate:modelValue': (newValue) => {
          // Update the hidden input if it exists
          if (hiddenInput) {
            hiddenInput.value = newValue || '';
            
            // Trigger a change event on the hidden input
            const event = new Event('change', { bubbles: true });
            hiddenInput.dispatchEvent(event);
            
            // Also update the data attribute on the container
            container.dataset.value = newValue || '';
          }
        },
        onUpdateValue: (value) => {
          // Update the hidden input if it exists
          if (hiddenInput) {
            hiddenInput.value = value || '';
            
            // Trigger a change event on the hidden input
            const event = new Event('change', { bubbles: true });
            hiddenInput.dispatchEvent(event);
            
            // Also update the data attribute on the container
            container.dataset.value = value || '';
          }
          
          // Dispatch a custom event
          container.dispatchEvent(new CustomEvent('asset-selected', {
            detail: { value }
          }));
        }
      });
      
      app.mount(container);
    } catch (error) {
      // Handle error silently
      console.error('Error mounting asset connector', error);
    }
  });
});