import { createApp } from 'vue';
import AssetConnector from './components/AssetConnector.vue';

console.log('Asset connector script loading...');

// Initialize all asset connector instances on the page
document.addEventListener('DOMContentLoaded', () => {
  console.log('DOM loaded, looking for asset connector containers');
  
  // Find all asset connector containers
  const containers = document.querySelectorAll('.asset-connector-container');
  console.log(`Found ${containers.length} asset connector containers`);
  
  containers.forEach((container, index) => {
    console.log(`Initializing asset connector container ${index}:`, container);
    
    // Get the configuration from the data attributes
    const config = {
      value: container.dataset.value || null,
      fieldKey: container.dataset.fieldKey,
      label: container.dataset.label || '',
      inputName: container.dataset.inputName || null,
      required: container.dataset.required === 'true'
    };
    
    console.log(`Container ${index} config:`, config);
    
    try {
      // Create a Vue app for this container
      const app = createApp(AssetConnector, {
        value: config.value,
        selectedId: config.value,
        fieldKey: config.fieldKey,
        label: config.label,
        inputName: config.inputName,
        required: config.required,
        onUpdateValue: (value) => {
          console.log(`Asset selected in container ${index}:`, value);
          // Update the hidden input if it exists
          const hiddenInput = document.getElementById(`asset_${config.fieldKey}`);
          if (hiddenInput) {
            hiddenInput.value = value;
          }
          
          // Dispatch a custom event
          container.dispatchEvent(new CustomEvent('asset-selected', {
            detail: { value }
          }));
        }
      });
      
      console.log(`Mounting Vue app to container ${index}`);
      app.mount(container);
      console.log(`Vue app mounted successfully to container ${index}`);
    } catch (error) {
      console.error(`Error creating/mounting Vue app for container ${index}:`, error);
    }
  });
}); 