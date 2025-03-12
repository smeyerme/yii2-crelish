<!-- components/common/ContentItem.vue -->
<template>
  <div
      class="content-item"
      :data-uuid="uuid"
      :data-area-key="areaKey"
  >
    <div class="content-item-header">
      <div class="drag-handle">☰</div>
      <div class="content-title">{{ contentTitle }}</div>
      <div class="content-actions">
        <button type="button" @click="$emit('edit', {uuid: uuid, ctype: contentType})" class="btn btn-sm btn-primary" title="Edit Content">
          Edit
        </button>
        <button type="button" @click="$emit('remove')" class="btn btn-sm btn-danger" title="Remove Content">
          Remove
        </button>
      </div>
    </div>

    <div class="content-details">
      <span class="content-type-badge">{{ contentType }}</span>
      <div v-if="loading" class="content-loading">
        Loading content details...
      </div>
      <div v-else-if="error" class="content-error">
        Error loading content details: {{ error }}
      </div>
      <div v-else class="content-info">
        <dl>
          <template v-for="(info, index) in contentInfo" :key="index">
            <template v-if="index < 3">
              <dt>{{ info.label }}:</dt>
              <dd>{{ formatInfoValue(info.value) }}</dd>
            </template>
          </template>
        </dl>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'ContentItem',

  props: {
    uuid: {
      type: String,
      required: true
    },
    contentType: {
      type: String,
      required: true
    },
    areaKey: {
      type: String,
      required: true
    },
    // Optional prop to force refresh when it changes
    refreshKey: {
      type: [Number, String],
      default: null
    }
  },

  emits: ['edit', 'remove', 'data-loaded'],

  data() {
    return {
      contentData: null,
      loading: true,
      error: null,
      fetchAttempted: false,
      lastFetchTime: null,
      lastApiCallTimestamp: 0
    };
  },

  computed: {
    contentTitle() {
      if (this.loading) return 'Loading...';
      if (this.error) return 'Error';

      if (this.contentData && this.contentData.info && Array.isArray(this.contentData.info)) {
        // Try to find a title field in the info array
        const titleInfo = this.contentData.info.find(info =>
            ['Titel', 'Titel intern', 'systitle', 'title', 'name'].includes(info.label)
        );

        if (titleInfo) {
          return titleInfo.value;
        }
      }

      // Fallback
      return `${this.contentType} element`;
    },

    contentInfo() {
      return this.contentData && this.contentData.info ? this.contentData.info : [];
    }
  },

  watch: {
    // Watch both uuid and contentType for changes
    uuid() {
      this.resetAndFetch();
    },
    contentType() {
      this.resetAndFetch();
    },
    // Watch refreshKey for changes to force data refresh
    refreshKey() {
      this.refresh();
    }
  },

  mounted() {
    console.log(`ContentItem mounted for ${this.uuid} (${this.contentType})`);
    this.fetchContentData();

    // Add an event listener for the custom refresh event
    this.$el.addEventListener('content-refresh', this.refresh);
  },

  beforeUnmount() {
    // Remove the event listener when component is unmounted
    this.$el.removeEventListener('content-refresh', this.refresh);
  },

  methods: {

    resetAndFetch() {
      // Reset the component state
      this.contentData = null;
      this.loading = true;
      this.error = null;
      this.fetchAttempted = false;
      // Fetch new data
      this.fetchContentData();
    },

    async fetchContentData() {
      const now = Date.now();

      // Prevent multiple calls within a short time window (500ms)
      if (now - this.lastApiCallTimestamp < 500) {
        console.log(`Skipping duplicate API call - last call was ${now - this.lastApiCallTimestamp}ms ago`);
        return;
      }

      // Update timestamp before making the call
      this.lastApiCallTimestamp = now;

      this.loading = true;
      this.error = null;
      this.fetchAttempted = true;

      try {
        console.log(`Fetching data for content: ${this.uuid}, type: ${this.contentType}`);
        const cacheBuster = Date.now();
        const response = await fetch(`/crelish/content/api-get?uuid=${this.uuid}&ctype=${this.contentType}&t=${cacheBuster}`);

        if (!response.ok) {
          throw new Error(`Failed to fetch content: ${response.status} ${response.statusText}`);
        }

        const newData = await response.json();
        console.log('Content data fetched successfully');

        // Compare with existing data to see if anything changed
        if (this.contentData && JSON.stringify(this.contentData) === JSON.stringify(newData)) {
          console.log('No changes detected in content data');
        } else {
          console.log('Content data has changed, updating component');
          this.contentData = newData;
        }

        // Emit event with the fetched data
        this.$emit('data-loaded', newData);
      } catch (error) {
        console.error('Error fetching content:', error);
        this.error = error.message;
      } finally {
        this.loading = false;
      }
    },

    formatInfoValue(value) {
      if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
      }

      if (value === null || value === undefined) {
        return '-';
      }

      // Format date timestamps
      if (typeof value === 'number' && value > 1000000000 && value < 10000000000) {
        return new Date(value * 1000).toLocaleDateString();
      }

      return String(value);
    },

    refresh() {
      console.log(`Refreshing ContentItem ${this.uuid}`);

      // Use the same timestamp-based protection
      const now = Date.now();
      if (now - this.lastApiCallTimestamp < 500) {
        console.log('Skipping refresh - too soon after last API call');
        return;
      }

      this.fetchContentData();
    }
  }
};
</script>

<style scoped>
.content-item {
  margin-bottom: 8px;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  background-color: #fff;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.content-item-header {
  display: flex;
  align-items: center;
  padding: 8px 12px;
  background-color: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}

.drag-handle {
  cursor: move;
  margin-right: 8px;
  color: #6c757d;
}

.content-title {
  flex: 1;
  font-weight: 500;
  margin-right: 8px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.content-actions {
  display: flex;
  gap: 4px;
}

.content-details {
  padding: 8px 12px;
}

.content-type-badge {
  display: inline-block;
  padding: 2px 6px;
  font-size: 0.75rem;
  background-color: #e9ecef;
  border-radius: 3px;
  margin-bottom: 8px;
}

.content-info dl {
  display: grid;
  grid-template-columns: max-content auto;
  gap: 4px 12px;
  margin: 0;
}

.content-info dt {
  font-weight: 500;
  color: #6c757d;
}

.content-info dd {
  margin: 0;
}

.content-loading,
.content-error {
  padding: 0.75rem;
  font-style: italic;
  color: #6c757d;
}

.content-error {
  color: #dc3545;
}
</style>