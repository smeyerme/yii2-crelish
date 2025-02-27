<!-- components/NewsletterPreview.vue -->
<template>
  <div class="newsletter-preview">
    <div class="preview-header">
      <h3>Preview</h3>
      <div class="preview-actions">
        <select v-model="previewWidth" class="form-control form-control-sm preview-width-select">
          <option value="375">Mobile (375px)</option>
          <option value="768">Tablet (768px)</option>
          <option value="100%">Full Width</option>
        </select>
        <button @click="refreshPreview" class="btn btn-sm btn-outline-primary">
          Refresh
        </button>
      </div>
    </div>

    <div class="preview-container" :style="{ width: previewWidthStyle }">
      <div v-if="loading" class="preview-loading">
        <div class="spinner"></div>
        <p>Generating preview...</p>
      </div>

      <iframe
          :style="{ width: previewWidthStyle }"
          v-show="!loading && previewHtml"
          :srcdoc="previewHtml"
          frameborder="0"
          class="preview-iframe"
      ></iframe>

      <div v-if="!loading && !previewHtml" class="preview-placeholder">
        <p>No preview available. Add sections to see preview.</p>
      </div>
    </div>
  </div>
</template>

<script>

function removeCircularReferences(obj) {
  const seen = new WeakSet();

  console.log(JSON.parse(JSON.stringify(obj)));

  return JSON.parse(JSON.stringify(obj));
}

export default {
  props: {
    newsletter: {
      type: Object,
      required: true
    }
  },

  data() {
    return {
      previewHtml: '',
      loading: false,
      previewWidth: '100%', // default to tablet width
      previewDebounce: null
    };
  },

  computed: {
    previewWidthStyle() {
      return this.previewWidth === '100%' ? '100%' : `${this.previewWidth}px`;
    }
  },

  watch: {
    newsletter: {
      handler() {
        this.debouncedRefresh();
      },
      deep: true
    }
  },

  mounted() {
    this.refreshPreview();
  },

  methods: {
    debouncedRefresh() {
      // Cancel previous debounce
      if (this.previewDebounce) {
        clearTimeout(this.previewDebounce);
      }

      // Set new timeout
      this.previewDebounce = setTimeout(() => {
        this.refreshPreview();
      }, 1000); // 1 second debounce
    },

    async refreshPreview() {
      if (!this.newsletter || !this.newsletter.sections || this.newsletter.sections.length === 0) {
        this.previewHtml = '';
        return;
      }

      this.loading = true;

      try {
        const cleanNewsletter = removeCircularReferences(this.newsletter);

        const response = await fetch('/crelish/newsletter/preview', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(cleanNewsletter)
        });

        if (response.ok) {
          const data = await response.json();
          this.previewHtml = data.html;
        } else {
          console.error('Failed to generate preview:', await response.text());
        }
      } catch (error) {
        console.error('Error generating preview:', error);
      } finally {
        this.loading = false;
      }
    }
  }
}
</script>

<style>
.newsletter-preview {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.preview-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 1rem;
  border-bottom: 1px solid #ddd;
  margin-bottom: 1rem;
}

.preview-header h3 {
  margin: 0;
}

.preview-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}

.preview-width-select {
  width: auto;
}

.preview-container {
  flex: 1;
  overflow: auto;
  border: 1px solid #ddd;
  border-radius: 4px;
  margin: 0 auto;
  background-color: #f8f9fa;
}

.preview-iframe {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
}

.preview-placeholder, .preview-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #6c757d;
  text-align: center;
  padding: 2rem;
}

.spinner {
  border: 4px solid rgba(0, 0, 0, 0.1);
  border-radius: 50%;
  border-top: 4px solid #007bff;
  width: 40px;
  height: 40px;
  animation: spin 1s linear infinite;
  margin-bottom: 1rem;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>