<!-- NewsletterEditor.vue -->
<template>
  <div class="newsletter-editor">
    <div class="editor-header">
      <h1>Newsletter Editor</h1>
      <div class="editor-actions">
        <button @click="saveNewsletter" class="btn btn-primary">Save Draft</button>
        <button @click="publishNewsletter" class="btn btn-success" :disabled="!newsletter.id">Publish</button>
      </div>
    </div>

    <div class="editor-content">
      <div class="editor-sidebar">
        <div class="newsletter-meta">
          <div class="form-group">
            <label>Newsletter Title</label>
            <input type="text" v-model="newsletter.title" class="form-control" placeholder="Newsletter Title" />
          </div>
          <div class="form-group">
            <label>Date</label>
            <input type="date" v-model="newsletter.date" class="form-control" />
          </div>
        </div>

        <div class="section-controls">
          <h3>Add Section</h3>
          <div class="section-buttons">
            <button
                v-for="type in availableSectionTypes"
                :key="type.type"
                @click="addSection(type.type)"
                class="btn btn-outline-secondary"
            >
              {{ type.label }}
            </button>
          </div>
        </div>

        <div class="sections-list">
          <h3>Sections</h3>
          <draggable
              v-model="newsletter.sections"
              handle=".section-handle"
              item-key="id"
              @end="updatePreview"
              class="sections-draggable"
          >
            <template #item="{element, index}">
              <div
                  class="section-item"
                  :class="{'section-active': currentEditingSection === index}"
                  @click="selectSection(index)"
              >
                <div class="section-handle">☰</div>
                <div class="section-title">
                  {{ getSectionTitle(element) }}
                </div>
                <div class="section-actions">
                  <button @click.stop="deleteSection(index)" class="btn btn-sm btn-danger">×</button>
                </div>
              </div>
            </template>
          </draggable>
        </div>
      </div>

      <div class="editor-main">
        <div v-if="currentEditingSection !== null" class="section-editor">
          <component
              :is="getSectionComponent(newsletter.sections[currentEditingSection])"
              :section="newsletter.sections[currentEditingSection]"
              @update="updateSection"
              @delete="deleteSection(currentEditingSection)"
          ></component>
        </div>

        <div v-else class="editor-placeholder">
          <p>Select a section to edit or add a new section</p>
        </div>
      </div>

      <div class="editor-preview">
        <h3>Preview</h3>
        <div class="preview-container">
          <iframe
              v-if="previewHtml"
              :srcdoc="previewHtml"
              frameborder="0"
              width="100%"
              height="100%"
          ></iframe>
          <div v-else class="preview-placeholder">
            <p>Add sections to see preview</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, computed, onMounted, watch } from 'vue';
import draggable from 'vuedraggable';
import HeroSection from './sections/HeroSection.vue';
import NavigationSection from './sections/NavigationSection.vue';
import ArticleSection from './sections/ArticleSection.vue';
import EventsSection from './sections/EventsSection.vue';
import JobPostingsSection from './sections/JobPostingsSection.vue';
import PartnersSection from './sections/PartnersSection.vue';

export default {
  components: {
    draggable,
    HeroSection,
    NavigationSection,
    ArticleSection,
    EventsSection,
    JobPostingsSection,
    PartnersSection
  },

  setup() {
    const newsletter = ref({
      title: 'New Newsletter',
      date: new Date().toISOString().split('T')[0],
      sections: []
    });

    const availableSectionTypes = [
      { type: 'hero', label: 'Hero Image' },
      { type: 'navigation', label: 'Navigation' },
      { type: 'article_section', label: 'Articles' },
      { type: 'events_list', label: 'Events List' },
      { type: 'job_postings', label: 'Job Postings' },
      { type: 'partners', label: 'Partners Grid' }
    ];

    const previewHtml = ref('');
    const currentEditingSection = ref(null);
    const previewDebounce = ref(null);

    // Load newsletter if editing existing one
    onMounted(async () => {
      const newsletterId = new URLSearchParams(window.location.search).get('id');
      if (newsletterId) {
        try {
          const response = await fetch(`/api/newsletter/${newsletterId}`);
          if (response.ok) {
            const data = await response.json();
            newsletter.value = data;
            updatePreview();
          }
        } catch (error) {
          console.error('Failed to load newsletter', error);
        }
      }
    });

    // Watch for changes to update preview
    watch(
        () => JSON.stringify(newsletter.value),
        () => {
          if (previewDebounce.value) {
            clearTimeout(previewDebounce.value);
          }
          previewDebounce.value = setTimeout(() => {
            updatePreview();
          }, 1000); // Debounce preview updates for better performance
        }
    );

    const addSection = (type) => {
      const newSection = {
        type,
        id: Date.now(), // For drag/drop tracking
        content: getDefaultContentForType(type)
      };

      newsletter.value.sections.push(newSection);
      currentEditingSection.value = newsletter.value.sections.length - 1;
    };

    const getDefaultContentForType = (type) => {
      switch(type) {
        case 'hero':
          return { imageId: null, link: '' };
        case 'navigation':
          return { links: [
              { text: 'FORUM HOLZBAU', url: 'https://forum-holzbau.com' },
              { text: 'FORUM HOLZKARRIERE', url: 'https://forum-holzkarriere.com' },
              { text: 'FORUM HOLZBRANCHE', url: 'https://forum-holzbranche.com' },
              { text: 'FORUM HOLZWISSEN', url: 'https://forum-holzwissen.com' }
            ] };
        case 'article_section':
          return { title: '', articles: [] };
        case 'events_list':
          return { events: [] };
        case 'job_postings':
          return { title: 'FORUM HOLZKARRIERE', jobs: [] };
        case 'partners':
          return { isPremium: true, partners: [] };
        default:
          return {};
      }
    };

    const updateSection = (sectionData) => {
      if (currentEditingSection.value !== null) {
        newsletter.value.sections[currentEditingSection.value] = sectionData;
      }
    };

    const deleteSection = (index) => {
      newsletter.value.sections.splice(index, 1);
      if (currentEditingSection.value === index) {
        currentEditingSection.value = null;
      } else if (currentEditingSection.value > index) {
        currentEditingSection.value--;
      }
    };

    const selectSection = (index) => {
      currentEditingSection.value = index;
    };

    const getSectionTitle = (section) => {
      switch(section.type) {
        case 'hero':
          return 'Hero Image';
        case 'navigation':
          return 'Navigation Links';
        case 'article_section':
          return section.title || 'Articles Section';
        case 'events_list':
          return 'Events List';
        case 'job_postings':
          return 'Job Postings';
        case 'partners':
          return section.content.columnCount === 3 ? 'Premium Partners' : 'Partners';
        default:
          return 'Unknown Section';
      }
    };

    const getSectionComponent = (section) => {
      switch(section.type) {
        case 'hero':
          return HeroSection;
        case 'navigation':
          return NavigationSection;
        case 'article_section':
          return ArticleSection;
        case 'events_list':
          return EventsSection;
        case 'job_postings':
          return JobPostingsSection;
        case 'partners':
          return PartnersSection;
        default:
          return null;
      }
    };

    const updatePreview = async () => {
      try {
        const response = await fetch('/api/newsletter/preview', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
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
        const url = newsletter.value.id
            ? `/api/newsletter/${newsletter.value.id}`
            : '/api/newsletter/create';

        const method = newsletter.value.id ? 'PUT' : 'POST';

        const response = await fetch(url, {
          method,
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(newsletter.value)
        });

        if (response.ok) {
          const data = await response.json();

          if (!newsletter.value.id && data.id) {
            newsletter.value.id = data.id;
            // Update URL without reloading page
            window.history.pushState({}, '', `?id=${data.id}`);
          }

          alert('Newsletter saved successfully!');
        } else {
          const error = await response.json();
          alert(`Error: ${error.message}`);
        }
      } catch (error) {
        console.error('Failed to save newsletter', error);
        alert('Failed to save newsletter');
      }
    };

    const publishNewsletter = async () => {
      if (!newsletter.value.id) {
        alert('Please save the newsletter first');
        return;
      }

      if (confirm('Are you sure you want to publish this newsletter?')) {
        try {
          const response = await fetch(`/api/newsletter/${newsletter.value.id}/publish`, {
            method: 'POST'
          });

          if (response.ok) {
            const data = await response.json();
            alert(`Newsletter published successfully! Download URL: ${data.downloadUrl}`);
          } else {
            const error = await response.json();
            alert(`Error: ${error.message}`);
          }
        } catch (error) {
          console.error('Failed to publish newsletter', error);
          alert('Failed to publish newsletter');
        }
      }
    };

    return {
      newsletter,
      availableSectionTypes,
      previewHtml,
      currentEditingSection,
      addSection,
      updateSection,
      deleteSection,
      selectSection,
      getSectionTitle,
      getSectionComponent,
      updatePreview,
      saveNewsletter,
      publishNewsletter
    };
  }
};
</script>

<style>
.newsletter-editor {
  display: flex;
  flex-direction: column;
  height: 100vh;
}

.editor-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  border-bottom: 1px solid #ddd;
}

.editor-content {
  display: flex;
  flex: 1;
  overflow: hidden;
}

.editor-sidebar {
  width: 300px;
  border-right: 1px solid #ddd;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  padding: 1rem;
}

.editor-main {
  flex: 1;
  padding: 1rem;
  overflow-y: auto;
  border-right: 1px solid #ddd;
}

.editor-preview {
  width: 375px;
  padding: 1rem;
  display: flex;
  flex-direction: column;
}

.preview-container {
  flex: 1;
  border: 1px solid #ddd;
  overflow: hidden;
}

.section-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.sections-list {
  flex: 1;
  overflow-y: auto;
}

.section-item {
  display: flex;
  align-items: center;
  padding: 0.5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  margin-bottom: 0.5rem;
  cursor: pointer;
}

.section-item:hover {
  background-color: #f9f9f9;
}

.section-active {
  background-color: #e6f7ff;
  border-color: #1890ff;
}

.section-handle {
  cursor: move;
  margin-right: 0.5rem;
  color: #999;
}

.section-title {
  flex: 1;
}

.section-actions {
  opacity: 0.3;
}

.section-item:hover .section-actions {
  opacity: 1;
}

.editor-placeholder, .preview-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #999;
  text-align: center;
}
</style>