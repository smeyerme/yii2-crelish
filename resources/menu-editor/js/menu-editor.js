import { createApp } from 'vue';
import MenuEditor from './components/MenuEditor.vue';

document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('menu-editor');
  if (!el) return;

  createApp(MenuEditor, {
    treeUrl: el.dataset.treeUrl,
    saveUrl: el.dataset.saveUrl,
    typesUrl: el.dataset.typesUrl,
    searchUrl: el.dataset.searchUrl,
    csrfToken: el.dataset.csrfToken,
    labels: JSON.parse(el.dataset.labels || '{}')
  }).mount(el);
});
