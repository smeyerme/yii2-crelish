import { createApp } from 'vue';
import RelationSelector from './components/RelationSelector.vue';

// Initialize all relation selector instances on the page
document.addEventListener('DOMContentLoaded', () => {
  // Find all relation selector containers
  const containers = document.querySelectorAll('.relation-selector-container');

  // Get global translations if available
  let globalTranslations = {};
  try {
    if (window.relationSelectorTranslations) {
      globalTranslations = window.relationSelectorTranslations;
    }
  } catch (error) {
    // Ignore errors
  }

  containers.forEach((container, index) => {
    // Get the configuration from the data attributes
    const config = {
      value: container.dataset.value || '[]',
      fieldKey: container.dataset.fieldKey,
      contentType: container.dataset.contentType,
      label: container.dataset.label || '',
      inputName: container.dataset.inputName || '',
      required: container.dataset.required === 'true',
      isMultiple: container.dataset.multiple === 'true',
      columns: []
    };

    // Parse columns configuration if available
    if (container.dataset.columns) {
      try {
        config.columns = JSON.parse(container.dataset.columns);
      } catch (error) {
        console.warn('Failed to parse columns JSON', error);
      }
    }

    // Check for container-specific translations
    let translations = { ...globalTranslations };
    if (container.dataset.translations) {
      try {
        const containerTranslations = JSON.parse(container.dataset.translations);
        translations = { ...translations, ...containerTranslations };
      } catch (error) {
        console.warn('Failed to parse translations JSON', error);
      }
    }

    try {
      // Create a Vue app for this container
      const app = createApp(RelationSelector, {
        modelValue: config.value,
        fieldKey: config.fieldKey,
        contentType: config.contentType,
        label: config.label,
        inputName: config.inputName,
        required: config.required,
        isMultiple: config.isMultiple,
        columns: config.columns,
        translations: translations,
        'onUpdate:modelValue': (newValue) => {
          // Update the data attribute on the container
          container.dataset.value = newValue || '[]';

          // Dispatch a custom event
          container.dispatchEvent(new CustomEvent('relation-updated', {
            detail: { value: newValue }
          }));
        }
      });

      app.mount(container);
    } catch (error) {
      console.error('Error mounting relation selector', error);
    }
  });
});