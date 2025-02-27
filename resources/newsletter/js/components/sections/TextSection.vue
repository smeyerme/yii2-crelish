<!-- components/sections/TextSection.vue -->
<template>
  <div class="text-section-editor">
    <h2>Text Section</h2>

    <div class="form-group">
      <label>Background Color</label>
      <select v-model="localSection.content.backgroundColor" class="form-control" @change="updateSection">
        <option value="#FFFFFF">White</option>
        <option value="#F7941D">Orange</option>
        <option value="#006633">Green</option>
        <option value="#333333">Dark Gray</option>
        <option value="#F8F9FA">Light Gray</option>
        <option value="#000000">Black</option>
      </select>
    </div>

    <div class="form-group">
      <label>Text Color</label>
      <select v-model="localSection.content.textColor" class="form-control" @change="updateSection">
        <option value="#000000">Black</option>
        <option value="#FFFFFF">White</option>
        <option value="#F7941D">Orange</option>
        <option value="#006633">Green</option>
        <option value="#333333">Dark Gray</option>
      </select>
    </div>

    <div class="form-group">
      <label>Content</label>
      <div class="editor-toolbar">
        <button type="button" @click="formatText('bold')" class="toolbar-btn" title="Bold">
          <strong>B</strong>
        </button>
        <button type="button" @click="formatText('italic')" class="toolbar-btn" title="Italic">
          <em>I</em>
        </button>
        <button type="button" @click="addLink" class="toolbar-btn" title="Add Link">
          <span>🔗</span>
        </button>
      </div>
      <textarea
          v-model="localSection.content.text"
          class="form-control text-editor"
          rows="10"
          placeholder="Enter text content here..."
          @input="updateSection"
          ref="textEditor"
      ></textarea>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    section: {
      type: Object,
      required: true
    }
  },

  data() {
    return {
      localSection: null
    };
  },

  created() {
    this.initLocalSection();
  },

  watch: {
    section: {
      handler() {
        this.initLocalSection();
      },
      deep: true
    }
  },

  methods: {
    initLocalSection() {
      // Create a deep clone of the section to avoid direct modifications
      this.localSection = JSON.parse(JSON.stringify(this.section));

      // Set default values if not provided
      if (!this.localSection.content) {
        this.localSection.content = {};
      }

      if (!this.localSection.content.text) {
        this.localSection.content.text = '';
      }

      if (!this.localSection.content.backgroundColor) {
        this.localSection.content.backgroundColor = '#FFFFFF';
      }

      if (!this.localSection.content.textColor) {
        this.localSection.content.textColor = '#000000';
      }
    },

    updateSection() {
      // Emit update event with a deep clone of the local section
      this.$emit('update', JSON.parse(JSON.stringify(this.localSection)));
    },

    formatText(format) {
      const textArea = this.$refs.textEditor;
      const start = textArea.selectionStart;
      const end = textArea.selectionEnd;
      const selectedText = this.localSection.content.text.substring(start, end);
      let formattedText = '';

      if (format === 'bold') {
        formattedText = `<strong>${selectedText}</strong>`;
      } else if (format === 'italic') {
        formattedText = `<em>${selectedText}</em>`;
      }

      if (selectedText) {
        // Replace selected text with formatted text
        this.localSection.content.text =
            this.localSection.content.text.substring(0, start) +
            formattedText +
            this.localSection.content.text.substring(end);

        this.updateSection();

        // Reset selection to after the inserted format
        this.$nextTick(() => {
          textArea.focus();
          textArea.setSelectionRange(start + formattedText.length, start + formattedText.length);
        });
      }
    },

    addLink() {
      const textArea = this.$refs.textEditor;
      const start = textArea.selectionStart;
      const end = textArea.selectionEnd;
      const selectedText = this.localSection.content.text.substring(start, end);

      // Prompt for URL
      const url = prompt('Enter link URL:', 'https://');

      if (url && url !== 'https://') {
        const linkText = selectedText || url;
        const linkHtml = `<a href="${url}">${linkText}</a>`;

        // Replace selected text with link
        this.localSection.content.text =
            this.localSection.content.text.substring(0, start) +
            linkHtml +
            this.localSection.content.text.substring(end);

        this.updateSection();

        // Reset selection to after the inserted link
        this.$nextTick(() => {
          textArea.focus();
          textArea.setSelectionRange(start + linkHtml.length, start + linkHtml.length);
        });
      }
    }
  }
}
</script>

<style scoped>
.text-section-editor {
  padding: 1rem;
  background-color: #f8f9fa;
  border-radius: 4px;
  margin-bottom: 1.5rem;
}

.form-group {
  margin-bottom: 1.5rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
}

.form-control {
  width: 100%;
  padding: 0.375rem 0.75rem;
  font-size: 1rem;
  line-height: 1.5;
  color: #495057;
  background-color: #fff;
  background-clip: padding-box;
  border: 1px solid #ced4da;
  border-radius: 0.25rem;
  transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.form-control:focus {
  color: #495057;
  background-color: #fff;
  border-color: #80bdff;
  outline: 0;
  box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.text-editor {
  min-height: 200px;
  font-family: monospace;
  white-space: pre-wrap;
  resize: vertical;
  border-top-left-radius: 0;
  border-top-right-radius: 0;
}

.editor-toolbar {
  display: flex;
  gap: 4px;
  padding: 8px;
  background-color: #e9ecef;
  border: 1px solid #ced4da;
  border-bottom: none;
  border-top-left-radius: 0.25rem;
  border-top-right-radius: 0.25rem;
}

.toolbar-btn {
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #fff;
  border: 1px solid #ced4da;
  border-radius: 2px;
  cursor: pointer;
  font-size: 14px;
}

.toolbar-btn:hover {
  background-color: #f8f9fa;
}

h2 {
  margin-bottom: 1.5rem;
}
</style>