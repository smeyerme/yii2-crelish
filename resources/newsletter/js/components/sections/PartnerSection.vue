<!-- components/sections/PartnerSection.vue -->
<template>
  <div class="partner-section-editor">
    <h2>Partners Section</h2>

    <div class="form-group">
      <label>Section Title</label>
      <input
          type="text"
          v-model="localSection.content.title"
          class="form-control"
          placeholder="e.g., Our Partners"
          @input="updateSection"
      />
    </div>

    <div class="form-group">
      <label>Background Color</label>
      <select v-model="localSection.content.backgroundColor" class="form-control" @change="updateSection">
        <option value="#FFFFFF">White</option>
        <option value="#F7941D">Orange</option>
        <option value="#006633">Green</option>
        <option value="#333333">Dark Gray</option>
        <option value="#000000">Black</option>
      </select>
    </div>

    <div class="form-group">
      <label>Title Color</label>
      <select v-model="localSection.content.titleColor" class="form-control" @change="updateSection">
        <option value="#FFFFFF">White</option>
        <option value="#F7941D">Orange</option>
        <option value="#006633">Green</option>
        <option value="#333333">Dark Gray</option>
        <option value="#000000">Black</option>
      </select>
    </div>

    <div class="form-group">
      <label>Column Count</label>
      <select v-model="localSection.content.columnCount" class="form-control" @change="updateSection">
        <option :value="3">3 Columns</option>
        <option :value="4">4 Columns</option>
      </select>
    </div>

    <div class="partners-container">
      <h3>Partner Logos</h3>

      <VueDraggable
          v-model="localSection.content.partners"
          item-key="id"
          handle=".partner-handle"
          @end="onDragEnd"
          class="partners-list"
      >
        <template v-for="(partner, index) in localSection.content.partners">
          <div class="partner-item">
            <div class="partner-handle">☰</div>
            <div class="partner-content">
              <div class="partner-header">
                <h4>Partner {{ index + 1 }}</h4>
              </div>

              <div class="form-group">
                <label>Partner Name</label>
                <input
                    type="text"
                    v-model="partner.name"
                    class="form-control"
                    placeholder="Partner Name"
                    @input="updateSection"
                />
              </div>

              <div class="form-group">
                <label>Partner Logo</label>
                <ImageSelector
                    :selected-id="partner.logoId"
                    @select="(logoId) => onLogoSelected(index, logoId)"
                />
              </div>

              <div class="form-group">
                <label>Link URL (Optional)</label>
                <input
                    type="text"
                    v-model="partner.url"
                    class="form-control"
                    placeholder="https://example.com"
                    @input="updateSection"
                />
              </div>
            </div>
            <button @click.stop="removePartner(index)" class="btn btn-sm btn-danger">x</button>
          </div>
        </template>
      </VueDraggable>

      <button @click="addPartner" class="btn btn-primary add-partner-btn">
        <span>+ Add Partner</span>
      </button>
    </div>
  </div>
</template>

<script>
import { VueDraggable } from 'vue-draggable-plus';
import ImageSelector from '../common/ImageSelector.vue';

export default {
  components: {
    VueDraggable,
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

      // Initialize partners array if needed
      if (!this.localSection.content) {
        this.localSection.content = {};
      }

      if (!this.localSection.content.partners) {
        this.localSection.content.partners = [];
      }

      // Set default values if not provided
      if (!this.localSection.content.title) {
        this.localSection.content.title = 'Our Partners';
      }

      if (!this.localSection.content.backgroundColor) {
        this.localSection.content.backgroundColor = '#000000';
      }

      if (!this.localSection.content.titleColor) {
        this.localSection.content.titleColor = '#FFFFFF';
      }

      if (!this.localSection.content.columnCount) {
        this.localSection.content.columnCount = 4;
      }
    },

    addPartner() {
      this.localSection.content.partners.push({
        id: Date.now(), // Unique ID for dragging
        name: '',
        logoId: null,
        url: ''
      });

      this.updateSection();
    },

    removePartner(index) {
      if (confirm('Are you sure you want to remove this partner?')) {
        this.localSection.content.partners.splice(index, 1);
        this.updateSection();
      }
    },

    onLogoSelected(partnerIndex, logoId) {
      this.localSection.content.partners[partnerIndex].logoId = logoId;
      this.updateSection();
    },

    onDragEnd() {
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
.partner-section-editor {
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

.partners-container {
  margin-top: 1.5rem;
}

.partners-list {
  min-height: 50px;
}

.partner-item {
  display: flex;
  margin-bottom: 1.5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  background-color: #fff;
}

.partner-handle {
  padding: 1rem;
  cursor: move;
  color: #999;
  border-right: 1px solid #ddd;
  display: flex;
  align-items: center;
}

.partner-content {
  flex: 1;
  padding: 1rem;
}

.partner-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.partner-header h4 {
  margin: 0;
}

.add-partner-btn {
  margin-top: 1rem;
  width: 100%;
}

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
  transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
  cursor: pointer;
}

.btn-primary {
  color: #fff;
  background-color: #007bff;
  border-color: #007bff;
}

.btn-danger {
  color: #fff;
  background-color: #dc3545;
  border-color: #dc3545;
}

.btn-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
  line-height: 1.5;
  border-radius: 0.2rem;
}
</style>