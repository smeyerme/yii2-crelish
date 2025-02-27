<!-- components/sections/EventsSection.vue -->
<template>
  <div class="events-section-editor">
    <h2>Events List</h2>

    <div class="form-group">
      <label>Background Color</label>
      <select v-model="section.content.bgColor" class="form-control" @change="updateSection">
        <option value="dark">Dark</option>
        <option value="light">Light</option>
      </select>
    </div>

    <VueDraggable
        v-model="section.content.events"
        item-key="id"
        handle=".event-handle"
        @end="updateSection"
        class="events-list"
    >
      <template v-for="(element, index) in section.content.events">
        <div class="event-item">
          <div class="event-handle">☰</div>
          <div class="event-content">
            <div class="event-header">
              <h4>Event {{ index + 1 }}</h4>
            </div>

            <div class="form-group">
              <label>Title</label>
              <input
                  type="text"
                  v-model="element.title"
                  class="form-control"
                  placeholder="Event Title"
                  @input="updateSection"
              />
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Date</label>
                <input
                    type="text"
                    v-model="element.date"
                    class="form-control"
                    placeholder="e.g., 19./20. März 2025"
                    @input="updateSection"
                />
              </div>
              <div class="form-group">
                <label>Location</label>
                <input
                    type="text"
                    v-model="element.location"
                    class="form-control"
                    placeholder="e.g., Berlin, Deutschland"
                    @input="updateSection"
                />
              </div>
            </div>

            <div class="form-group">
              <label>Link URL (Optional)</label>
              <input
                  type="text"
                  v-model="element.link"
                  class="form-control"
                  placeholder="https://example.com/event"
                  @input="updateSection"
              />
            </div>
          </div>
          <button @click.stop="removeEvent(index)" class="btn btn-sm btn-danger">x</button>
        </div>
      </template>
    </VueDraggable>

    <button @click="addEvent" class="btn btn-primary add-event-btn">
      <span>+ Add Event</span>
    </button>
  </div>
</template>

<script>
import { VueDraggable } from 'vue-draggable-plus';

export default {
  components: {
    VueDraggable
  },

  props: {
    section: {
      type: Object,
      required: true
    }
  },

  created() {
    // Initialize arrays and default values if needed
    if (!this.section.content.events) {
      this.section.content.events = [];
    }
    if (!this.section.content.bgColor) {
      this.section.content.bgColor = 'dark';
    }
    this.updateSection();
  },

  methods: {
    addEvent() {
      this.section.content.events.push({
        id: Date.now(),
        title: '',
        date: '',
        location: '',
        link: ''
      });
      this.updateSection();
    },

    removeEvent(index) {
      if (confirm('Are you sure you want to remove this event?')) {
        this.section.content.events.splice(index, 1);
        this.updateSection();
      }
    },

    updateSection() {
      this.$emit('update', JSON.parse(JSON.stringify(this.section)));
    }
  }
}
</script>

<style>
.event-item {
  display: flex;
  margin-bottom: 1.5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  background-color: #fff;
}

.event-handle {
  padding: 1rem;
  cursor: move;
  color: #999;
  border-right: 1px solid #ddd;
  display: flex;
  align-items: center;
}

.event-content {
  flex: 1;
  padding: 1rem;
}
</style>