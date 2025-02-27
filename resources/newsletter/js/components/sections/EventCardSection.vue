<!-- components/sections/EventCardSection.vue -->
<template>
  <div class="event-card-section-editor">
    <h2>Event Cards Section</h2>

    <div class="form-group">
      <label>Section Title</label>
      <input
          type="text"
          v-model="localSection.content.title"
          class="form-control"
          placeholder="e.g., Upcoming Events"
          @input="updateSection"
      />
    </div>

    <div class="form-group">
      <label>Background Color</label>
      <select v-model="localSection.content.backgroundColor" class="form-control" @change="updateSection">
        <option value="#FFFFFF">White</option>
        <option value="#F8F9FA">Light Gray</option>
        <option value="#F7941D">Orange</option>
        <option value="#006633">Green</option>
        <option value="#333333">Dark Gray</option>
      </select>
    </div>

    <div class="event-cards-container">
      <h3>Event Cards <small>(Maximum 3)</small></h3>

      <div class="event-cards-list">
        <div v-for="(event, index) in localSection.content.events" :key="index" class="event-card-item">
          <div class="event-card-header">
            <h4>Event {{ index + 1 }}</h4>
            <button @click="removeEvent(index)" class="btn btn-sm btn-danger">Remove</button>
          </div>

          <div class="form-group">
            <label>Event Title</label>
            <input
                type="text"
                v-model="event.title"
                class="form-control"
                placeholder="Event Title"
                @input="updateSection"
            />
          </div>

          <div class="form-group">
            <label>Location</label>
            <input
                type="text"
                v-model="event.location"
                class="form-control"
                placeholder="e.g., Berlin, Germany"
                @input="updateSection"
            />
          </div>

          <div class="form-group">
            <label>Date</label>
            <input
                type="text"
                v-model="event.date"
                class="form-control"
                placeholder="e.g., September 15-17, 2023"
                @input="updateSection"
            />
          </div>

          <div class="form-group">
            <label>Accent Color</label>
            <input
                type="color"
                v-model="event.color"
                class="form-control color-picker"
                @input="updateSection"
            />
          </div>

          <div class="form-group">
            <label>Event Link</label>
            <input
                type="text"
                v-model="event.link"
                class="form-control"
                placeholder="https://example.com/event"
                @input="updateSection"
            />
          </div>

          <div class="form-group">
            <label>Event Image</label>
            <ImageSelector
                :selected-id="event.imageId"
                @select="(imageId) => onImageSelected(index, imageId)"
            />
          </div>
        </div>
      </div>

      <button
          @click="addEvent"
          class="btn btn-primary add-event-btn"
          :disabled="localSection.content.events.length >= 3">
        <span>+ Add Event Card</span>
      </button>
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

      // Initialize events array if needed
      if (!this.localSection.content) {
        this.localSection.content = {};
      }

      if (!this.localSection.content.events) {
        this.localSection.content.events = [];
      }

      // Set default values if not provided
      if (!this.localSection.content.title) {
        this.localSection.content.title = 'Upcoming Events';
      }

      if (!this.localSection.content.backgroundColor) {
        this.localSection.content.backgroundColor = '#FFFFFF';
      }
    },

    addEvent() {
      // Only allow up to 3 events
      if (this.localSection.content.events.length < 3) {
        this.localSection.content.events.push({
          title: '',
          location: '',
          date: '',
          color: '#006633', // Default green color
          link: '',
          imageId: null
        });

        this.updateSection();
      }
    },

    removeEvent(index) {
      if (confirm('Are you sure you want to remove this event?')) {
        this.localSection.content.events.splice(index, 1);
        this.updateSection();
      }
    },

    onImageSelected(eventIndex, imageId) {
      this.localSection.content.events[eventIndex].imageId = imageId;
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
.event-card-section-editor {
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

.color-picker {
  height: 40px;
  padding: 2px;
}

.event-cards-container {
  margin-top: 1.5rem;
}

.event-cards-list {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.event-card-item {
  border: 1px solid #ddd;
  border-radius: 4px;
  padding: 1.5rem;
  background-color: #fff;
}

.event-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}

.event-card-header h4 {
  margin: 0;
}

.add-event-btn {
  margin-top: 1.5rem;
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

.btn:disabled {
  opacity: 0.65;
  cursor: not-allowed;
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