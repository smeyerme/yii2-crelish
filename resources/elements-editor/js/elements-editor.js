// elements-editor.js
import { createApp } from 'vue';
import ElementsEditor from './components/ElementsEditor.vue';

console.log('Elements editor script loading...');

document.addEventListener('DOMContentLoaded', () => {
  console.log('DOM loaded, attempting to mount Vue app');

  const mountElement = document.getElementById('elements-editor');
  console.log('Mount element found:', mountElement);

  if (mountElement) {
    console.log('Creating Vue app...');

    // Get initial element data from the hidden input
    const jsonInput = document.getElementById('element-json-input');
    let initialElementData = null;

    if (jsonInput && jsonInput.value) {
      try {
        initialElementData = JSON.parse(jsonInput.value);
        console.log('Loaded initial element data:', initialElementData);
      } catch (error) {
        console.error('Failed to parse initial element data:', error);
      }
    }

    // Get element key from data attribute if needed
    const elementKey = mountElement.dataset.elementKey;
    console.log('Element key:', elementKey);

    const app = createApp(ElementsEditor, {
      initialElementData: initialElementData,
      elementKey: elementKey
    });

    console.log('Mounting Vue app...');
    app.mount(mountElement);
    console.log('Vue app mounted successfully');
  } else {
    console.error('Could not find #elements-editor element to mount Vue app');
  }
}); 