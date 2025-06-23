import { createApp } from 'vue';
import AssetConnector from './components/AssetConnector.vue';

// Function to initialize a single asset connector container
window.initializeAssetConnector = function(container) {
  if (!container || container.dataset.vueInitialized === 'true') {
    return;
  }
  
  // Mark as initialized to prevent double initialization
  container.dataset.vueInitialized = 'true';
  
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
  
  // Get the configuration from the data attributes
  const config = {
    value: container.dataset.value || null,
    fieldKey: container.dataset.fieldKey,
    label: container.dataset.label || '',
    inputName: container.dataset.inputName || null,
    required: container.dataset.required === 'true',
    multiple: container.dataset.multiple === 'true'
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
    // Find the hidden input element - it should be a sibling of the container
    let hiddenInput = null;
    
    // First try to find by the input name attribute
    if (config.inputName) {
      hiddenInput = document.querySelector(`input[name="${config.inputName}"]`);
    }
    
    // If not found, try finding it as a sibling of the container
    if (!hiddenInput) {
      const parentElement = container.parentElement;
      if (parentElement) {
        hiddenInput = parentElement.querySelector('input[type="hidden"]');
      }
    }
    
    // Last resort: try the old method
    if (!hiddenInput) {
      hiddenInput = document.getElementById(`asset_${config.fieldKey}`);
    }
    
    // Debug logging
    if (window.console && console.log) {
      console.log('AssetConnector init:', {
        fieldKey: config.fieldKey,
        inputName: config.inputName,
        hiddenInputFound: !!hiddenInput,
        hiddenInputId: hiddenInput ? hiddenInput.id : 'not found',
        multiple: config.multiple,
        rawDataValue: container.dataset.value
      });
    }
    
    // Parse the initial value correctly
    let initialValue;
    if (config.multiple) {
      if (config.value) {
        try {
          const parsed = JSON.parse(config.value);
          // Ensure we have an array of UUIDs, not complex objects
          if (Array.isArray(parsed)) {
            initialValue = parsed.map(item => {
              if (typeof item === 'string') {
                return item; // Direct UUID
              } else if (typeof item === 'object' && item.uuid) {
                return item.uuid; // Extract UUID from object
              } else {
                return null;
              }
            }).filter(uuid => uuid !== null);
          } else {
            initialValue = [];
          }
        } catch (e) {
          console.warn('Failed to parse multiple asset value:', e);
          initialValue = [];
        }
      } else {
        initialValue = [];
      }
    } else {
      initialValue = config.value;
    }
    
    // Debug logging
    if (window.console && console.log) {
      console.log('AssetConnector initial value:', {
        multiple: config.multiple,
        rawValue: config.value,
        parsedValue: initialValue,
        isArray: Array.isArray(initialValue)
      });
    }
    
    // Create a Vue app for this container
    const app = createApp(AssetConnector, {
      modelValue: initialValue,
      fieldKey: config.fieldKey,
      label: config.label,
      inputName: config.inputName,
      required: config.required,
      multiple: config.multiple,
      translations: translations,
      'onUpdate:modelValue': (newValue) => {
        // Debug logging
        if (window.console && console.log) {
          console.log('AssetConnector modelValue update:', {
            newValue: newValue,
            multiple: config.multiple,
            hiddenInputExists: !!hiddenInput
          });
        }
        
        // Update the hidden input if it exists
        if (hiddenInput) {
          const valueToStore = config.multiple ? JSON.stringify(newValue || []) : (newValue || '');
          hiddenInput.value = valueToStore;
          
          // Debug logging
          if (window.console && console.log) {
            console.log('Updated hidden input:', {
              inputId: hiddenInput.id,
              oldValue: hiddenInput.getAttribute('value'),
              newValue: valueToStore
            });
          }
          
          // Trigger a change event on the hidden input
          const event = new Event('change', { bubbles: true });
          hiddenInput.dispatchEvent(event);
          
          // Also update the data attribute on the container
          container.dataset.value = valueToStore;
        } else {
          console.warn('AssetConnector: Hidden input not found for update');
        }
      },
      onUpdateValue: (value) => {
        // Update the hidden input if it exists
        if (hiddenInput) {
          const valueToStore = config.multiple ? JSON.stringify(value || []) : (value || '');
          hiddenInput.value = valueToStore;
          
          // Trigger a change event on the hidden input
          const event = new Event('change', { bubbles: true });
          hiddenInput.dispatchEvent(event);
          
          // Also update the data attribute on the container
          container.dataset.value = valueToStore;
        }
        
        // Dispatch a custom event
        container.dispatchEvent(new CustomEvent('asset-selected', {
          detail: { value }
        }));
      }
    });
    
    app.mount(container);
    console.log('AssetConnector Vue app mounted successfully');
  } catch (error) {
    // Handle error
    console.error('Error mounting asset connector', error);
  }
};

// Initialize all asset connector instances on the page
function initializeAllAssetConnectors() {
  const containers = document.querySelectorAll('.asset-connector-container');
  containers.forEach(container => {
    window.initializeAssetConnector(container);
  });
}

// Initialize on DOMContentLoaded
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeAllAssetConnectors);
} else {
  // DOM is already loaded
  initializeAllAssetConnectors();
}