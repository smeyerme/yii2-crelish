<!-- components/sections/HeroSection.vue -->
<template>
  <div class="hero-section-editor">
    <h2>Hero Section</h2>

    <div class="form-group">
      <label>Hero Title</label>
      <input
          type="text"
          v-model="localSection.content.title"
          class="form-control"
          placeholder="Hero Title (Optional)"
          @input="updateSection"
      />
    </div>

    <div class="form-group">
      <label>Subtitle</label>
      <input
          type="text"
          v-model="localSection.content.subtitle"
          class="form-control"
          placeholder="Subtitle (Optional)"
          @input="updateSection"
      />
    </div>

    <div class="form-group">
      <label>Title Color</label>
      <select v-model="localSection.content.titleColor" class="form-control" @change="updateSection">
        <option value="#FFFFFF">White</option>
        <option value="#F7941D">Orange</option>
        <option value="#006633">Green</option>
        <option value="#333333">Dark Gray</option>
      </select>
    </div>

    <div class="form-group">
      <label>Hero Image</label>
      <ImageSelector
          :selected-id="localSection.content.imageId"
          @select="onImageSelected"
      />
    </div>

    <div class="form-group">
      <label>Link URL (Optional)</label>
      <input
          type="text"
          v-model="localSection.content.link"
          class="form-control"
          placeholder="https://example.com"
          @input="updateSection"
      />
    </div>

    <div class="form-group">
      <label>Call to Action Text</label>
      <input
          type="text"
          v-model="localSection.content.ctaText"
          class="form-control"
          placeholder="e.g., Learn More"
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

      // Set default title color if not provided
      if (!this.localSection.content.titleColor) {
        this.localSection.content.titleColor = '#FFFFFF';
      }

      // Set default CTA text if not provided
      if (!this.localSection.content.ctaText) {
        this.localSection.content.ctaText = 'Mehr erfahren';
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
.hero-section-editor {
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