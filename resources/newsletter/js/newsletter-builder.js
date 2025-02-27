// resources/js/newsletter-builder.js
import { createApp } from 'vue';
import NewsletterEditor from './components/NewsletterEditor.vue';

console.log('Newsletter builder script loading...');

document.addEventListener('DOMContentLoaded', () => {
  console.log('DOM loaded, attempting to mount Vue app');

  const mountElement = document.getElementById('newsletter-editor');
  console.log('Mount element found:', mountElement);

  if (mountElement) {
    console.log('Creating Vue app...');

    // Get newsletter UUID from URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const newsletterId = urlParams.get('uuid'); // Assuming your param is named 'id'

    console.log('Newsletter ID from URL:', newsletterId);

    const app = createApp(NewsletterEditor, {
      initialNewsletterId: newsletterId // Pass as prop
    });

    console.log('Mounting Vue app...');
    app.mount(mountElement);
    console.log('Vue app mounted successfully');
  } else {
    console.error('Could not find #newsletter-editor element to mount Vue app');
  }
});