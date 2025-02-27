<script>
import {ref, computed, onMounted} from 'vue';

export default {
  setup() {
    const newsletter = ref({
      title: 'New Newsletter',
      date: new Date().toISOString().split('T')[0],
      sections: []
    });

    const availableSectionTypes = [
      {type: 'hero', label: 'Hero Image'},
      {type: 'navigation', label: 'Navigation Links'},
      {type: 'article_section', label: 'Articles Section'},
      {type: 'events_list', label: 'Events List'},
      {type: 'job_postings', label: 'Job Postings'},
      {type: 'partners', label: 'Partners Grid'}
    ];

    const previewHtml = ref('');

// Load newsletter if editing existing one
    onMounted(async () => {
      const newsletterId = new URLSearchParams(window.location.search).get('id');
      if (newsletterId) {
        try {
          const response = await fetch(`/api/newsletters/${newsletterId}`);
          const data = await response.json();
          newsletter.value = data;
          updatePreview();
        } catch (error) {
          console.error('Failed to load newsletter', error);
        }
      }
    });

    const addSection = (type) => {
      const newSection = {
        type,
        id: Date.now(), // For drag/drop tracking
        content: getDefaultContentForType(type)
      };

      newsletter.value.sections.push(newSection);
      updatePreview();
    };

    const getDefaultContentForType = (type) => {
      switch (type) {
        case 'hero':
          return {imageId: null, link: ''};
        case 'navigation':
          return {links: []};
        case 'article_section':
          return {title: 'New Section', articles: []};
// Add defaults for other section types
        default:
          return {};
      }
    };

    const moveSection = (fromIndex, toIndex) => {
      const sections = newsletter.value.sections;
      const [removed] = sections.splice(fromIndex, 1);
      sections.splice(toIndex, 0, removed);
      updatePreview();
    };

    const updatePreview = async () => {
      try {
        const response = await fetch('/api/newsletters/preview', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(newsletter.value)
        });

        if (response.ok) {
          const data = await response.json();
          previewHtml.value = data.html;
        }
      } catch (error) {
        console.error('Failed to generate preview', error);
      }
    };

    const saveNewsletter = async () => {
      try {
        const method = newsletter.value.id ? 'PUT' : 'POST';
        const url = newsletter.value.id
            ? `/api/newsletters/${newsletter.value.id}`
            : '/api/newsletters';

        const response = await fetch(url, {
          method,
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(newsletter.value)
        });

        if (response.ok) {
          alert('Newsletter saved successfully!');
        }
      } catch (error) {
        console.error('Failed to save newsletter', error);
      }
    };

    const publishNewsletter = async () => {
      try {
        const response = await fetch(`/api/newsletters/${newsletter.value.id}/publish`, {
          method: 'POST'
        });

        if (response.ok) {
          const data = await response.json();
          alert(`Newsletter published! Download HTML: ${data.downloadUrl}`);
        }
      } catch (error) {
        console.error('Failed to publish newsletter', error);
      }
    };

    return {
      newsletter,
      availableSectionTypes,
      previewHtml,
      addSection,
      moveSection,
      updatePreview,
      saveNewsletter,
      publishNewsletter
    };
  }
};
</script>