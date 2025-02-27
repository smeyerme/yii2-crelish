<!-- components/sections/AdSection.vue -->
<template>
  <div class="ad-section-editor">
    <h2>Advertisement Banner</h2>

    <div class="form-group">
      <label>Banner Image</label>
      <ImageSelector
          :selected-id="localSection.content.imageId"
          @select="onImageSelected"
      />
    </div>

    <div class="form-group">
      <label>Link URL</label>
      <input
          type="text"
          v-model="localSection.content.url"
          class="form-control"
          placeholder="https://example.com"
          @input="updateSection"
      />
    </div>

    <div class="form-group">
      <label>Alt Text (for accessibility)</label>
      <input
          type="text"
          v-model="localSection.content.altText"
          class="form-control"
          placeholder="Describe the banner content"
          @input="updateSection"
      />
    </div>
  </div>
</template>

<script>
import ImageSelector from '../common/ImageSelector.vue';

export default {
  components: {
    ImageSelector
  },

  props: {
    section: {
      type: Object,
      required: true
    }
  },

  data() {
    return {
      localSection: null,
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

      // Set default alt text if not provided
      if (!this.localSection.content.altText) {
        this.localSection.content.altText = 'Advertisement';
      }
    },

    onImageSelected(imageId) {
      this.localSection.content.imageId = imageId;
      this.updateSection();
    },

    updateSection() {
      // Emit update event with a deep clone of the local section
      this.$emit('update', JSON.parse(JSON.stringify(this.localSection)));
    }
  }
}
</script>

<style scoped>
.ad-section-editor {
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

h2 {
  margin-bottom: 1.5rem;
}
</style>