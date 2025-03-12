<!-- components/common/EditorOverlay.vue -->
<template>
  <div class="editor-overlay" v-if="isVisible">
    <div class="overlay-backdrop" @click="handleCloseAttempt"></div>
    <div class="overlay-container">
      <div class="overlay-header">
        <h2>{{ title }}</h2>
        <button class="btn-close" @click="handleCloseAttempt">&times;</button>
      </div>
      <div class="overlay-content">
        <div v-if="loading" class="iframe-loading">
          <div class="spinner"></div>
          <p>Loading editor...</p>
        </div>
        <iframe
            ref="editorFrame"
            :src="editorUrl"
            class="editor-iframe"
            @load="iframeLoaded"
            allow="fullscreen"
            sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-downloads"
        ></iframe>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'EditorOverlay',

  props: {
    isVisible: {
      type: Boolean,
      default: false
    },
    elementId: {
      type: String,
      default: null
    },
    contentType: {
      type: String,
      default: null
    },
    title: {
      type: String,
      default: 'Edit Content'
    }
  },

  emits: ['close', 'saved'],

  data() {
    return {
      loading: true,
      saveDetected: false,
      checkInterval: null,
      hasUnsavedChanges: false
    };
  },

  computed: {
    editorUrl() {
      if (!this.elementId || !this.contentType) return '';
      return `/crelish/content/update?uuid=${this.elementId}&ctype=${this.contentType}&overlay=1`;
    }
  },

  watch: {
    isVisible(newVal) {
      if (newVal) {
        // When overlay becomes visible
        this.loading = true;
        this.saveDetected = false;
        this.hasUnsavedChanges = false;
        document.body.classList.add('overlay-open'); // Prevent scrolling of the main page
      } else {
        // When overlay is hidden
        document.body.classList.remove('overlay-open'); // Restore scrolling
        this.stopSaveDetection();
      }
    }
  },

  mounted() {
    // Add escape key handler
    window.addEventListener('keydown', this.handleKeyDown);
  },

  beforeUnmount() {
    // Clean up
    this.stopSaveDetection();
    window.removeEventListener('keydown', this.handleKeyDown);
    window.removeEventListener('message', this.handleIframeMessage);
    document.body.classList.remove('overlay-open');
  },

  methods: {
    iframeLoaded() {
      this.loading = false;

      try {
        // Access the iframe content
        const iframe = this.$refs.editorFrame;
        if (!iframe || !iframe.contentWindow) return;

        // Start monitoring for save events
        this.startSaveDetection();

        // Add a special class to the iframe body to indicate it's in an overlay
        if (iframe.contentDocument && iframe.contentDocument.body) {
          iframe.contentDocument.body.classList.add('in-overlay-mode');

          // Add our custom script to the iframe to communicate save events
          this.injectHelperScript(iframe);
        }
      } catch (error) {
        // Handle potential cross-origin errors
        console.warn('Could not access iframe content:', error);
      }
    },

    startSaveDetection() {
      // Clear any existing interval
      this.stopSaveDetection();

      // Start new interval
      this.checkInterval = setInterval(() => {
        this.checkForSaveCompletion();
      }, 500);

      // Set up event listener for messages from the iframe
      window.addEventListener('message', this.handleIframeMessage);
    },

    stopSaveDetection() {
      if (this.checkInterval) {
        clearInterval(this.checkInterval);
        this.checkInterval = null;
      }

      // Remove message event listener
      window.removeEventListener('message', this.handleIframeMessage);
    },

    checkForSaveCompletion() {
      try {
        const iframe = this.$refs.editorFrame;
        if (!iframe || !iframe.contentWindow) return;

        // Look for success messages or redirects that indicate a successful save
        const successAlert = iframe.contentDocument.querySelector('.alert-success');
        const saveCompleted = successAlert || this.saveDetected;

        if (saveCompleted) {
          // Allow a brief moment to see the success message
          setTimeout(() => {
            this.stopSaveDetection();
            this.$emit('saved', {
              elementId: this.elementId,
              contentType: this.contentType
            });
            this.$emit('close');
          }, 1500);
        }
      } catch (error) {
        // Handle potential cross-origin errors
        console.warn('Save detection error:', error);
      }
    },

    injectHelperScript(iframe) {
      try {
        // Create a script element
        const script = iframe.contentDocument.createElement('script');
        script.textContent = `
          // Helper function to notify parent window of save events
          window.notifyParentOfSave = function() {
            window.parent.postMessage({
              action: 'content-saved',
              elementId: '${this.elementId}',
              contentType: '${this.contentType}'
            }, '*');
          };

          // Ensure iframe content is interactive
          document.body.style.pointerEvents = 'auto';
          document.documentElement.style.pointerEvents = 'auto';

          // Track form modifications
          window.hasUnsavedChanges = false;

          // Find form and attach listeners
          document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
              // Track changes
              form.addEventListener('change', function() {
                window.hasUnsavedChanges = true;
                // Notify parent window about unsaved changes
                window.parent.postMessage({
                  action: 'content-modified',
                  state: true
                }, '*');
              });

              // Track submission
              form.addEventListener('submit', function() {
                // Store a flag that we've submitted the form
                window.hasUnsavedChanges = false;
                localStorage.setItem('form_submitted', '${this.elementId}');

                // Notify parent
                window.notifyParentOfSave();
              });
            });

            // Ensure all inputs are interactive
            const allInputs = document.querySelectorAll('input, button, select, textarea, a');
            allInputs.forEach(input => {
              input.style.pointerEvents = 'auto';
            });

            // Hide header, footer, and other unnecessary elements in overlay mode
            if (document.body.classList.contains('in-overlay-mode')) {
              // Add styles to hide elements
              const style = document.createElement('style');
              style.textContent = \`
                /* Hide unnecessary elements */
                #cr-left-pane {
                  display: none !important;
                }

                /* Adjust layout */
                .content-wrapper, .content, .main-content {
                  padding: 0 !important;
                  margin: 0 !important;
                  width: 100% !important;
                }

                body {
                  background: white !important;
                  pointer-events: auto !important;
                }

                /* Ensure all form elements can be interacted with */
                input, select, textarea, button, a {
                  pointer-events: auto !important;
                }

                .container {
                  width: 100% !important;
                  max-width: none !important;
                  padding: 0 15px !important;
                }

                /* Format alerts nicely */
                .alert-success {
                  position: fixed;
                  top: 10px;
                  right: 10px;
                  z-index: 9999;
                  animation: fadeInOut 4s forwards;
                }

                @keyframes fadeInOut {
                  0% { opacity: 0; }
                  10% { opacity: 1; }
                  80% { opacity: 1; }
                  100% { opacity: 0; }
                }

                /* Make form buttons sticky at bottom */
                .form-group.buttons {
                  position: sticky !important;
                  bottom: 0 !important;
                  background: #f8f9fa !important;
                  padding: 15px !important;
                  margin-bottom: 0 !important;
                  border-top: 1px solid #ddd !important;
                  z-index: 100 !important;
                }
              \`;
              document.head.appendChild(style);

              // Add a save listener for ajax forms
              window.addEventListener('ajaxFormSaved', function(e) {
                window.hasUnsavedChanges = false;
                window.notifyParentOfSave();
              });
            }
          });
        `;

        // Append the script to the iframe document
        iframe.contentDocument.head.appendChild(script);
      } catch (error) {
        console.warn('Could not inject helper script:', error);
      }
    },

    handleIframeMessage(event) {
      // Process messages from the iframe
      if (event.data && typeof event.data === 'object') {
        switch (event.data.action) {
          case 'content-saved':
            // Check if this is for our current element
            if (event.data.elementId === this.elementId) {
              this.saveDetected = true;
              this.hasUnsavedChanges = false;
              this.checkForSaveCompletion();
            }
            break;

          case 'content-modified':
            // Update unsaved changes state
            this.hasUnsavedChanges = !!event.data.state;
            break;
        }
      }
    },

    handleCloseAttempt() {
      // Check if there are unsaved changes before closing
      if (this.hasUnsavedChanges) {
        if (confirm('You have unsaved changes. Are you sure you want to close?')) {
          this.$emit('close');
        }
      } else {
        this.$emit('close');
      }
    },

    handleKeyDown(event) {
      // Close overlay on Escape key
      if (event.key === 'Escape' && this.isVisible) {
        this.handleCloseAttempt();
      }
    }
  }
};
</script>

<style>
.editor-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 10000;
  display: flex;
  align-items: center;
  justify-content: center;
}

.overlay-backdrop {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  cursor: pointer;
}

.overlay-container {
  position: relative;
  width: 95vw;
  height: 95vh;
  background-color: white;
  border-radius: 6px;
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  z-index: 1;
}

.overlay-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background-color: #f8f9fa;
  border-bottom: 1px solid #ddd;
}

.overlay-header h2 {
  margin: 0;
  font-size: 1.25rem;
}

.btn-close {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  color: #666;
  line-height: 1;
}

.btn-close:hover {
  color: #000;
}

.overlay-content {
  flex: 1;
  position: relative;
  overflow: hidden;
}

.editor-iframe {
  width: 100%;
  height: 100%;
  border: none;
  z-index: 2;
  position: relative;
}

.iframe-loading {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background-color: white;
  z-index: 3;
}

.spinner {
  width: 50px;
  height: 50px;
  border: 5px solid #f3f3f3;
  border-top: 5px solid #3498db;
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin-bottom: 15px;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

/* Add styles to prevent scrolling when overlay is open */
body.overlay-open {
  overflow: hidden;
  position: fixed;
  width: 100%;
  height: 100%;
}
</style>