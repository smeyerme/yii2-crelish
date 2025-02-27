<!-- NewsletterEditor.vue - Updated with preview panel -->
<template>
  <dialog ref="draftRestoreDialog" class="custom-dialog">
    <div class="dialog-content">
      <h3>Restore Draft?</h3>
      <p>Found an unsaved draft from <span ref="draftTimeAgo"></span>. Would you like to restore it?</p>
      <div class="dialog-actions">
        <button @click="restoreDraft" class="btn btn-primary">Restore Draft</button>
        <button @click="cancelRestoreDraft" class="btn btn-outline-secondary">Load Original</button>
      </div>
    </div>
  </dialog>

  <dialog ref="deleteSectionDialog" class="custom-dialog">
    <div class="dialog-content">
      <h3>Delete Section</h3>
      <p>Are you sure you want to delete this section?</p>
      <div class="dialog-actions">
        <button @click="confirmDeleteSection" class="btn btn-danger">Delete Section</button>
        <button @click="cancelDeleteSection" class="btn btn-outline-secondary">Cancel</button>
      </div>
    </div>
  </dialog>

  <dialog ref="discardDraftDialog" class="custom-dialog">
    <div class="dialog-content">
      <h3>Discard Draft</h3>
      <p>Are you sure you want to discard this draft? This action cannot be undone.</p>
      <div class="dialog-actions">
        <button @click="confirmDiscardDraft" class="btn btn-danger">Discard Draft</button>
        <button @click="cancelDiscardDraft" class="btn btn-outline-secondary">Cancel</button>
      </div>
    </div>
  </dialog>

  <div class="newsletter-editor">
    <div class="editor-header">
      <h1></h1>
      <div class="editor-actions">
        <button v-if="newsletter.id" @click="cloneNewsletter" class="btn btn-outline-primary">Kopie erstellen</button>
        <button @click="saveNewsletter" class="btn btn-primary">Entwurf speichern</button>
        <button @click="publishNewsletter" class="btn btn-success" :disabled="!newsletter.id">Veröffentlichen</button>
        <a v-if="publishedUrl" :href="publishedUrl" target="_blank" class="btn btn-view-published">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
            <circle cx="12" cy="12" r="3"></circle>
          </svg>
        </a>
      </div>
    </div>

    <div class="editor-content">
      <!-- Left sidebar -->
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
          <VueDraggable
              id="sections"
              v-model="newsletter.sections"
              item-key="id"
              handle=".section-handle"
              @end="updateSections"
              group="a"
              class="sections-draggable"
          >
            <template v-for="(element, index) in newsletter.sections">
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
          </VueDraggable>
        </div>
      </div>

      <!-- Main content area -->
      <div class="editor-main">
        <div v-if="currentEditingSection !== null" class="section-editor">
          <!-- Dynamic component based on section type -->
          <component
              :is="getSectionComponent(newsletter.sections[currentEditingSection])"
              :section="newsletter.sections[currentEditingSection]"
              @update="(sectionData) => updateSection(sectionData)"
          ></component>
        </div>

        <div v-else class="editor-placeholder">
          <p>Select a section to edit or add a new section</p>
        </div>
      </div>

      <!-- Preview panel -->
      <div class="editor-preview">
        <newsletter-preview :newsletter="newsletter" />
      </div>
    </div>
  </div>
</template>

<script>
// Import draggable component for Vue 3
import { VueDraggable } from 'vue-draggable-plus';

// Import section components
import HeroSection from './sections/HeroSection.vue';
import ArticleSection from './sections/ArticleSection.vue';
import NewsletterPreview from './NewsletterPreview.vue';
import EventsSection from './sections/EventsSection.vue';
import NavigationSection from './sections/NavigationSection.vue';
import JobPostingsSection from './sections/JobPostingsSection.vue';
import AdSection from './sections/AdSection.vue';
import PartnerSection from "./sections/PartnerSection.vue";
import TextSection from "./sections/TextSection.vue";
import EventCardSection from "./sections/EventCardSection.vue";

export default {
  name: 'NewsletterEditor',

  props: {
    initialNewsletterId: {
      type: String,
      default: null
    }
  },

  components: {
    VueDraggable,
    HeroSection,
    ArticleSection,
    NewsletterPreview,
    EventsSection,
    NavigationSection,
    JobPostingsSection,
    AdSection,
    PartnerSection,
    TextSection,
    EventCardSection
  },

  data() {
    return {
      newsletter: {
        title: 'New Newsletter',
        date: new Date().toISOString().split('T')[0],
        sections: [],
        id: null,
        status: 0,
        publishedUrl: null,
      },
      availableSectionTypes: [
        { type: 'hero', label: 'Hero Bild' },
        { type: 'navigation', label: 'Navigation' },
        { type: 'article_section', label: 'Artikel' },
        { type: 'events_list', label: 'Event-Liste' },
        { type: 'job_postings', label: 'Stellenangebote' },
        { type: 'partners', label: 'Partner' },
        { type: 'ad', label: 'Banner' },
        { type: 'text', label: 'Text' },
        { type: 'event_cards', label: 'Event Logos' }
      ],
      currentEditingSection: null,
      autosaveInterval: null,
      lastSaved: null,
      autosaveId: null,
      pendingNewsletterId: null,
      pendingDraftData: null,
      pendingDraftKey: null,
      pendingSectionIndex: null,
    };
  },

  mounted() {
    // Check for existing draft in localStorage when component mounts
    if (this.initialNewsletterId) {
      this.loadNewsletterWithDraftCheck(this.initialNewsletterId);
    } else {
      // Check for existing drafts when no ID is provided
      this.checkForExistingDraft();
    }

    // Set up autosave interval
    this.autosaveInterval = setInterval(() => {
      this.autosaveNewsletter();
    }, 30000); // Save every 30 seconds
  },

  beforeUnmount() {
    // Clear the interval when the component is destroyed
    if (this.autosaveInterval) {
      clearInterval(this.autosaveInterval);
    }
  },

  watch: {
    // Watch for changes to the newsletter to trigger immediate autosave
    newsletter: {
      handler() {
        // Debounce the autosave to avoid too many saves
        if (this.autosaveTimer) {
          clearTimeout(this.autosaveTimer);
        }

        this.autosaveTimer = setTimeout(() => {
          this.autosaveNewsletter();
        }, 3000); // Wait 3 seconds after changes before saving
      },
      deep: true
    }
  },

  methods: {
    async loadExistingNewsletter(id) {
      try {
        const url = '/crelish/newsletter/load';
        const method = 'POST';
        const response = await fetch(url, {
          method,
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ 'id': id })
        });

        if (response.ok) {
          const data = await response.json();
          this.newsletter = data;
          this.autosaveId = `newsletter_${id}`;
          console.log('Existing newsletter loaded successfully');

          // If the newsletter is published (status=1), set the published URL
          if (this.newsletter.status === 1 && this.newsletter.publishedUrl) {
            this.publishedUrl = this.newsletter.publishedUrl;
            console.log('Newsletter has been published at:', this.publishedUrl);
          } else {
            this.publishedUrl = null;
          }
        } else {
          console.error('Failed to load newsletter:', await response.text());
          alert('Could not load the requested newsletter');
        }
      } catch (error) {
        console.error('Error loading newsletter:', error);
        alert('Error loading newsletter');
      }
    },

    async loadNewsletterWithDraftCheck(id) {
      const draftKey = `newsletter_${id}`;
      const savedData = localStorage.getItem(draftKey);

      if (savedData) {
        try {
          const parsedData = JSON.parse(savedData);
          const savedTime = new Date(parsedData.timestamp);
          const timeAgo = this.getTimeAgo(savedTime);

          // Store pending data for use by dialog response handlers
          this.pendingNewsletterId = id;
          this.pendingDraftData = parsedData;
          this.pendingDraftKey = draftKey;

          // Update dialog content and display it
          this.$refs.draftTimeAgo.textContent = timeAgo;
          this.$refs.draftRestoreDialog.showModal();
        } catch (error) {
          console.error('Failed to parse draft:', error);
          await this.loadExistingNewsletter(id);
        }
      } else {
        // No draft found, load from server
        await this.loadExistingNewsletter(id);
      }
    },

    async cloneNewsletter() {
      if (!this.newsletter.id) {
        alert('Cannot clone an unsaved newsletter. Please save first.');
        return;
      }

      try {
        const url = '/crelish/newsletter/clone';
        const response = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: this.newsletter.id })
        });

        if (response.ok) {
          const data = await response.json();

          // Navigate to the new cloned newsletter
          if (data.id) {
            // Remove any existing autosave for the current newsletter
            if (this.autosaveId) {
              localStorage.removeItem(this.autosaveId);
            }

            // Redirect to the new newsletter
            window.location.href = `/crelish/newsletter/create?ctype=bulletin&uuid=${data.id}`;
          } else {
            alert('Cloned successfully but could not navigate to the new newsletter.');
          }
        } else {
          const error = await response.json();
          alert(`Error: ${error.message}`);
        }
      } catch (error) {
        console.error('Failed to clone newsletter', error);
        alert('Failed to clone newsletter');
      }
    },

    // Handler for "Restore Draft" button
    restoreDraft() {
      if (this.pendingDraftData) {
        this.newsletter = this.pendingDraftData.newsletter;
        this.autosaveId = this.pendingDraftKey;
        console.log('Draft restored successfully');
        this.$refs.draftRestoreDialog.close();
        this.resetPendingDialogData();
      }
    },

    cancelRestoreDraft() {
      this.$refs.draftRestoreDialog.close();

      // Load from server if we have a pending ID
      if (this.pendingNewsletterId) {
        this.loadExistingNewsletter(this.pendingNewsletterId);
      }

      this.resetPendingDialogData();
    },

    async discardDraftAndLoadFromServer() {
      // Close the dialog
      this.$refs.draftRestoreDialog.close();

      // Load from server if we have a pending ID
      if (this.pendingNewsletterId) {
        await this.loadExistingNewsletter(this.pendingNewsletterId);

        // Reset pending data
        this.pendingDraftData = null;
        this.pendingNewsletterId = null;
      }
    },

    generateAutosaveId() {
      // If we already have an autosave ID, use it
      if (this.autosaveId) {
        return this.autosaveId;
      }

      // If this is an existing newsletter, use its ID
      if (this.newsletter.id) {
        this.autosaveId = `newsletter_${this.newsletter.id}`;
      } else {
        // For new newsletters, generate a unique ID
        this.autosaveId = `newsletter_draft_${Date.now()}`;
      }

      return this.autosaveId;
    },

    autosaveNewsletter() {
      try {
        const saveId = this.generateAutosaveId();
        const saveData = {
          newsletter: JSON.parse(JSON.stringify(this.newsletter)),
          timestamp: new Date().toISOString(),
          isAutosave: true
        };

        localStorage.setItem(saveId, JSON.stringify(saveData));
        this.lastSaved = new Date();

        console.log(`Newsletter autosaved at ${this.lastSaved.toLocaleTimeString()}`);
      } catch (error) {
        console.error('Failed to autosave newsletter:', error);
      }
    },

    checkForExistingDraft() {
      try {
        // For existing newsletters
        if (this.newsletter.id) {
          const savedData = localStorage.getItem(`newsletter_${this.newsletter.id}`);
          if (savedData) {
            this.promptRestoreDraft(savedData);
          }
          return;
        }

        // For new newsletters, look for any draft
        const draftKeys = this.findDraftKeys();
        if (draftKeys.length > 0) {
          // Get the most recent draft
          const latestDraftKey = this.findLatestDraft(draftKeys);
          const savedData = localStorage.getItem(latestDraftKey);

          if (savedData) {
            this.promptRestoreDraft(savedData, latestDraftKey);
          }
        }
      } catch (error) {
        console.error('Failed to check for existing drafts:', error);
      }
    },

    findDraftKeys() {
      const keys = [];
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && key.startsWith('newsletter_draft_')) {
          keys.push(key);
        }
      }
      return keys;
    },

    findLatestDraft(draftKeys) {
      let latestKey = draftKeys[0];
      let latestTime = 0;

      draftKeys.forEach(key => {
        try {
          const data = JSON.parse(localStorage.getItem(key));
          const time = new Date(data.timestamp).getTime();

          if (time > latestTime) {
            latestTime = time;
            latestKey = key;
          }
        } catch (e) {
          // Skip invalid data
        }
      });

      return latestKey;
    },

    promptRestoreDraft(savedDataString, draftKey = null) {
      try {
        const savedData = JSON.parse(savedDataString);
        const savedTime = new Date(savedData.timestamp);
        const timeAgo = this.getTimeAgo(savedTime);

        // Only prompt if there's actual content
        if (!savedData.newsletter || !savedData.newsletter.sections || savedData.newsletter.sections.length === 0) {
          return;
        }

        // Store pending data
        this.pendingDraftData = savedData;
        this.pendingDraftKey = draftKey;

        // Update dialog and show it
        this.$refs.draftTimeAgo.textContent = timeAgo;
        this.$refs.draftRestoreDialog.showModal();
      } catch (error) {
        console.error('Failed to restore draft:', error);
      }
    },

    getTimeAgo(date) {
      const now = new Date();
      const diffMs = now - date;
      const diffSec = Math.round(diffMs / 1000);
      const diffMin = Math.round(diffSec / 60);
      const diffHour = Math.round(diffMin / 60);
      const diffDay = Math.round(diffHour / 24);

      if (diffSec < 60) {
        return 'just now';
      } else if (diffMin < 60) {
        return `${diffMin} minute${diffMin === 1 ? '' : 's'} ago`;
      } else if (diffHour < 24) {
        return `${diffHour} hour${diffHour === 1 ? '' : 's'} ago`;
      } else {
        return `${diffDay} day${diffDay === 1 ? '' : 's'} ago`;
      }
    },

    discardDraft() {
      this.$refs.discardDraftDialog.showModal();
    },

    confirmDiscardDraft() {
      if (this.autosaveId) {
        localStorage.removeItem(this.autosaveId);
      }

      // Reset the newsletter to its initial state for a new draft
      this.newsletter = {
        title: 'New Newsletter',
        date: new Date().toISOString().split('T')[0],
        sections: [],
        id: null
      };

      // Generate a new autosave ID for the fresh draft
      this.autosaveId = null;
      this.generateAutosaveId();

      console.log('Draft discarded');
      this.$refs.discardDraftDialog.close();
    },

    resetPendingDialogData() {
      this.pendingNewsletterId = null;
      this.pendingDraftData = null;
      this.pendingDraftKey = null;
      this.pendingSectionIndex = null;
    },

    cancelDiscardDraft() {
      this.$refs.discardDraftDialog.close();
    },

    addSection(type) {
      const newSection = {
        type,
        id: Date.now(),
        content: this.getDefaultContentForType(type)
      };

      this.newsletter.sections.push(newSection);
      this.currentEditingSection = this.newsletter.sections.length - 1;
    },

    getDefaultContentForType(type) {
      switch(type) {
        case 'hero':
          return { imageId: null, link: '' };
        case 'navigation':
          return { links: [
              { text: 'FORUM HOLZBAU', url: 'https://forum-holzbau.com' },
              { text: 'FORUM HOLZKARRIERE', url: 'https://forum-holzkarriere.com' }
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
    },

    getSectionTitle(section) {
      switch(section.type) {
        case 'hero':
          return 'Hero Image';
        case 'navigation':
          return 'Navigation Links';
        case 'article_section':
          return section.content.title || 'Articles Section';
        case 'events_list':
          return 'Events List';
        case 'job_postings':
          return 'Job Postings';
        case 'partners':
          return section.content.isPremium ? 'Premium Partners' : 'Partners';
        case 'ad':
          return 'Banner';
        case 'text':
          return 'Text';
        case 'event_cards':
          return 'Event Logos';
        default:
          return 'Unknown Section';
      }
    },

    getSectionComponent(section) {
      // Map section types to components
      const componentMap = {
        'hero': 'HeroSection',
        'article_section': 'ArticleSection',
        'navigation': 'NavigationSection',
        'events_list': 'EventsSection',
        'job_postings': 'JobPostingsSection',
        'ad': 'AdSection',
        'partners': 'PartnerSection',
        'text': 'TextSection',
        'event_cards': 'EventCardSection',
      };

      return componentMap[section.type] || null;
    },

    selectSection(index) {
      this.currentEditingSection = index;
    },

    updateSection(updatedSection) {
      if (this.currentEditingSection !== null) {
        this.newsletter.sections[this.currentEditingSection] = updatedSection;
      }
    },

    updateSections() {
      //console.log('Sections reordered:', this.newsletter.sections);
    },

    deleteSection(index) {
      this.pendingSectionIndex = index;
      this.$refs.deleteSectionDialog.showModal();
    },

    confirmDeleteSection() {
      if (this.pendingSectionIndex !== null) {
        this.newsletter.sections.splice(this.pendingSectionIndex, 1);

        if (this.currentEditingSection === this.pendingSectionIndex) {
          this.currentEditingSection = null;
        } else if (this.currentEditingSection > this.pendingSectionIndex) {
          this.currentEditingSection--;
        }

        this.$refs.deleteSectionDialog.close();
        this.pendingSectionIndex = null;
      }
    },

    cancelDeleteSection() {
      this.$refs.deleteSectionDialog.close();
      this.pendingSectionIndex = null;
    },

    async saveNewsletter() {
      try {
        const url = '/crelish/newsletter/draft';
        const method = this.newsletter.id ? 'PUT' : 'POST';
        const response = await fetch(url, {
          method,
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(this.newsletter)
        });

        if (response.ok) {
          const data = await response.json();

          if (!this.newsletter.id && data.id) {
            this.newsletter.id = data.id;
          }

          try {
            if (this.autosaveId) {
              // After successful save, update the autosave with current data
              // but mark it as a saved version
              const saveData = {
                newsletter: JSON.parse(JSON.stringify(this.newsletter)),
                timestamp: new Date().toISOString(),
                isAutosave: false
              };

              localStorage.setItem(this.autosaveId, JSON.stringify(saveData));
            }
          } catch (error) {
            console.error('Failed to update localStorage after save:', error);
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
    },

    async publishNewsletter() {
      if (!this.newsletter.id) {
        await this.saveNewsletter();
        if (!this.newsletter.id) return;
      }

      try {
        const url = '/crelish/newsletter/publish';
        const method = 'POST';
        const response = await fetch(url, {
          method,
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(this.newsletter)
        });

        if (response.ok) {
          const data = await response.json();
          try {
            if (this.autosaveId) {
              // After successful publish, remove the autosave
              localStorage.removeItem(this.autosaveId);
              console.log('Autosave removed after publishing');
            }
          } catch (error) {
            console.error('Failed to clear localStorage after publish:', error);
          }

          // Update the newsletter status and URL
          this.newsletter.status = 1;
          if (data.downloadUrl) {
            this.publishedUrl = data.downloadUrl;
            this.newsletter.downloadUrl = data.downloadUrl;
          }

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
  },

  computed: {
    isPublished() {
      return this.newsletter.status === 1;
    }
  }
}
</script>

<style>
.newsletter-editor {
  display: flex;
  flex-direction: column;
  height: 94vh;
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
  width: 340px;
  border-right: 1px solid #ddd;
  padding: 1rem;
  overflow-y: auto;
}

.editor-main {
  flex: 1;
  padding: 1rem;
  overflow-y: auto;
  border-right: 1px solid #ddd;
}

.editor-preview {
  width: 680px;
  padding: 1rem;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.section-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.sections-draggable {
  min-height: 50px;
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

.editor-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #999;
  text-align: center;
}

.section-editor {
  padding: 1rem;
  background-color: #f8f9fa;
  border-radius: 4px;
  border: 1px solid #ddd;
}

/* Form elements */
.form-group {
  margin-bottom: 1rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
}

.form-control {
  display: block;
  width: 92%;
  padding: 0.375rem 0.75rem;
  font-size: 1rem;
  line-height: 1.5;
  color: #495057;
  background-color: #fff;
  border: 1px solid #ced4da;
  border-radius: 0.25rem;
  transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.form-control-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
  line-height: 1.5;
  border-radius: 0.2rem;
}

/* Buttons */
.btn {
  display: inline-block;
  font-weight: 400;
  text-align: center;
  white-space: nowrap;
  vertical-align: middle;
  user-select: none;
  border: 1px solid transparent;
  padding: 0.375rem 0.75rem;
  font-size: 1rem;
  line-height: 1.5;
  border-radius: 0.25rem;
  transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out;
  cursor: pointer;
}

.btn-primary {
  color: #fff;
  background-color: #007bff;
  border-color: #007bff;
}

.btn-success {
  color: #fff;
  background-color: #28a745;
  border-color: #28a745;
}

.btn-outline-secondary {
  color: #6c757d;
  background-color: transparent;
  border-color: #6c757d;
}

.btn-outline-primary {
  color: #007bff;
  background-color: transparent;
  border-color: #007bff;
}

.btn-danger {
  color: #fff;
  background-color: #dc3545;
  border-color: #dc3545;
}

.btn-secondary {
  color: #fff;
  background-color: #6c757d;
  border-color: #6c757d;
}

.btn-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
  line-height: 1.5;
  border-radius: 0.2rem;
}

.draft-restore-dialog {
  padding: 0;
  border: none;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  max-width: 400px;
}

.draft-restore-dialog::backdrop {
  background-color: rgba(0, 0, 0, 0.4);
}

.dialog-content {
  padding: 1.5rem;
}

.dialog-content h3 {
  margin-top: 0;
  margin-bottom: 1rem;
}

.dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 1.5rem;
}
</style>