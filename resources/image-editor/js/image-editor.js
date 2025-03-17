import { createApp } from 'vue';
import ImageEditor from './components/ImageEditor.vue';

console.log('Image editor script loading...');

// Initialize all image editor instances on the page
document.addEventListener('DOMContentLoaded', () => {
  console.log('DOM loaded, looking for image editor containers');
  
  // Find all image editor containers
  const containers = document.querySelectorAll('.image-editor-container');
  console.log(`Found ${containers.length} image editor containers`);
  
  containers.forEach((container, index) => {
    console.log(`Initializing image editor container ${index}:`, container);
    
    // Get the configuration from the data attributes
    const config = {
      assetUuid: container.dataset.assetUuid || null,
      fieldKey: container.dataset.fieldKey,
      inputName: container.dataset.inputName || null
    };
    
    console.log(`Container ${index} config:`, config);
    
    try {
      // Create a Vue app for this container
      const app = createApp(ImageEditor, {
        assetUuid: config.assetUuid,
        fieldKey: config.fieldKey,
        inputName: config.inputName
      });
      
      console.log(`Mounting Vue app to container ${index}`);
      app.mount(container);
      console.log(`Vue app mounted successfully to container ${index}`);
    } catch (error) {
      console.error(`Error creating/mounting Vue app for container ${index}:`, error);
    }
  });
}); 