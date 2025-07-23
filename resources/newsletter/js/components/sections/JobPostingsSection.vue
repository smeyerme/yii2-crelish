<!-- components/sections/JobPostingsSection.vue -->
<template>
  <div class="job-postings-section-editor">
    <h2>Job Postings Section</h2>

    <div class="form-group">
      <label>Section Title</label>
      <input
          type="text"
          v-model="localSection.content.title"
          class="form-control"
          placeholder="Section Title (e.g., FORUM HOLZKARRIERE)"
          @input="updateSection"
      />
    </div>

    <div class="form-group">
      <label>Title Color</label>
      <select v-model="localSection.content.titleColor" class="form-control" @change="updateSection">
        <option value="#F7941D">Orange</option>
        <option value="#006633">Green</option>
        <option value="#333333">Dark Gray</option>
      </select>
    </div>

    <div class="jobs-container">
      <h3>Job Listings</h3>

      <VueDraggable
          v-model="localSection.content.jobs"
          item-key="id"
          handle=".job-handle"
          @end="onDragEnd"
          class="jobs-list"
      >
        <template v-for="(element, index) in localSection.content.jobs">
          <div class="job-item">
            <div class="job-handle">☰</div>
            <div class="job-content">
              <div class="job-header">
                <h4>Job Listing {{ index + 1 }}</h4>
              </div>

              <div class="form-group">
                <label>Company Name</label>
                <input
                    type="text"
                    v-model="element.company"
                    class="form-control"
                    placeholder="Company Name"
                    @input="updateSection"
                />
              </div>

              <div class="form-group">
                <label>Location</label>
                <input
                    type="text"
                    v-model="element.location"
                    class="form-control"
                    placeholder="e.g., Leinefelde-Worbis, Deutschland"
                    @input="updateSection"
                />
              </div>

              <div class="form-group">
                <label>Job Title</label>
                <input
                    type="text"
                    v-model="element.title"
                    class="form-control"
                    placeholder="e.g., Konstrukteur (CAD), Ingenieur, Zimmerermeister"
                    @input="updateSection"
                />
              </div>

              <div class="form-group">
                <label>Company Logo</label>
                <ImageSelector
                    :selected-id="element.companyLogoId"
                    @select="(logoId) => onLogoSelected(index, logoId)"
                />
              </div>

              <div class="form-group">
                <label>Company Logo Link (Optional)</label>
                <input
                    type="text"
                    v-model="element.companyLogoLink"
                    class="form-control"
                    placeholder="https://example.com/company-website"
                    @input="updateSection"
                />
              </div>

              <div class="form-group">
                <label>Job Text Link (Optional)</label>
                <input
                    type="text"
                    v-model="element.link"
                    class="form-control"
                    placeholder="https://example.com/job-listing"
                    @input="updateSection"
                />
              </div>
            </div>
            <button @click.stop="removeJob(index)" class="btn btn-sm btn-danger">x</button>
          </div>
        </template>
      </VueDraggable>

      <button @click="addJob" class="btn btn-primary add-job-btn">
        <span>+ Add Job Listing</span>
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

      // Initialize content if needed
      if (!this.localSection.content) {
        this.localSection.content = {};
      }

      // Initialize jobs array if needed
      if (!this.localSection.content.jobs) {
        this.localSection.content.jobs = [];
      }

      // Set default title if not provided
      if (!this.localSection.content.title) {
        this.localSection.content.title = 'FORUM HOLZKARRIERE';
      }

      // Set default title color if not provided
      if (!this.localSection.content.titleColor) {
        this.localSection.content.titleColor = '#F7941D';
      }
    },

    addJob() {
      this.localSection.content.jobs.push({
        id: Date.now(),
        company: '',
        location: '',
        title: '',
        companyLogoId: null,
        companyLogoLink: '',
        link: ''
      });

      this.updateSection();
    },

    removeJob(index) {
      if (confirm('Are you sure you want to remove this job listing?')) {
        this.localSection.content.jobs.splice(index, 1);
        this.updateSection();
      }
    },

    onLogoSelected(jobIndex, logoId) {
      this.localSection.content.jobs[jobIndex].companyLogoId = logoId;
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

<style>
.job-postings-section-editor {
  padding: 1rem;
}

.jobs-container {
  margin-top: 1.5rem;
}

.jobs-list {
  min-height: 50px;
}

.job-item {
  display: flex;
  margin-bottom: 1.5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  background-color: #fff;
}

.job-handle {
  padding: 1rem;
  cursor: move;
  color: #999;
  border-right: 1px solid #ddd;
  display: flex;
  align-items: center;
}

.job-content {
  flex: 1;
  padding: 1rem;
}

.job-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.job-header h4 {
  margin: 0;
}

.add-job-btn {
  margin-top: 1rem;
  width: 100%;
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