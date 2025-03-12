// js/page-builder.js
import { createApp } from 'vue';
import PageBuilder from './components/PageBuilder.vue';
import { nanoid } from 'nanoid';
// No need to import Sortable directly anymore

console.log('Page builder script loading...');

document.addEventListener('DOMContentLoaded', () => {
  console.log('DOM loaded, attempting to mount Vue app');

  const mountElement = document.getElementById('page-builder');
  console.log('Mount element found:', mountElement);

  if (mountElement) {
    console.log('Creating Vue app...');

    // Get initial page data from the hidden input
    const jsonInput = document.getElementById('page-json-input');
    let initialPageData = null;

    if (jsonInput && jsonInput.value) {
      try {
        initialPageData = JSON.parse(jsonInput.value);
        console.log('Loaded initial page data:', initialPageData);
      } catch (error) {
        console.error('Failed to parse initial page data:', error);
      }
    }

    // Get page ID from URL or data attribute if needed
    const pageId = mountElement.dataset.pageId;
    console.log('Page ID:', pageId);

    const app = createApp(PageBuilder, {
      initialPageData: initialPageData,
      pageId: pageId
    });

    // Make utilities available globally for Vue components
    app.config.globalProperties.$nanoid = nanoid;

    // Note: vuedraggable is imported directly in the component file
    // so we don't need to register it here

    console.log('Mounting Vue app...');
    app.mount(mountElement);
    console.log('Vue app mounted successfully');
  } else {
    console.error('Could not find #page-builder element to mount Vue app');
  }
});