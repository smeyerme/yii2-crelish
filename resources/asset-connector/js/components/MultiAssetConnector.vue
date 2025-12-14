<!-- components/MultiAssetConnector.vue -->
<template>
  <div class="multi-asset-selector" :class="{'required': required}">
    <div v-if="label" class="multi-asset-selector-label">
      {{ label }}
    </div>

    <!-- Selected assets preview area -->
    <div class="selected-assets-area">
      <div v-if="selectedAssets.length > 0" class="selected-assets-grid">
        <div
            v-for="(asset, index) in selectedAssets"
            :key="asset.uuid"
            class="selected-asset-item"
            draggable="true"
            @dragstart="onDragStart($event, index)"
            @dragover.prevent="onDragOver($event, index)"
            @drop="onDrop($event, index)"
            @dragend="onDragEnd"
        >
          <div class="asset-drag-handle" :title="t('labelDragToReorder')">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="9" cy="5" r="1"></circle>
              <circle cx="9" cy="12" r="1"></circle>
              <circle cx="9" cy="19" r="1"></circle>
              <circle cx="15" cy="5" r="1"></circle>
              <circle cx="15" cy="12" r="1"></circle>
              <circle cx="15" cy="19" r="1"></circle>
            </svg>
          </div>
          <img v-if="asset.preview_url" :src="asset.preview_url" :alt="asset.title || 'Asset'" class="asset-preview-img">
          <div v-else class="asset-loading">
            <div class="spinner-small"></div>
          </div>
          <button type="button" class="asset-remove-btn" @click="removeAsset(index)" :title="t('labelClear')">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
          <div class="asset-index">{{ index + 1 }}</div>
        </div>

        <!-- Add more button (inline) -->
        <div v-if="canAddMore" class="add-more-item" @click="openImageSelector">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
        </div>
      </div>

      <!-- Empty state -->
      <div v-else class="empty-preview">
        <div class="placeholder-icon">
          <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
            <circle cx="8.5" cy="8.5" r="1.5"></circle>
            <polyline points="21 15 16 10 5 21"></polyline>
          </svg>
        </div>
        <p class="placeholder-text">{{ t('labelNoFileSelected') }}</p>
        <button @click="openImageSelector" class="btn btn-outline-primary" type="button">
          {{ t('labelSelectFile') }} <span v-if="required" class="required-indicator">*</span>
        </button>
      </div>
    </div>

    <!-- Action buttons -->
    <div v-if="selectedAssets.length > 0" class="multi-asset-actions">
      <button @click="openImageSelector" class="btn btn-sm btn-primary" type="button" :disabled="!canAddMore">
        {{ t('labelAddMore') }}
        <span v-if="maxItems">({{ selectedAssets.length }}/{{ maxItems }})</span>
      </button>
      <button @click="clearAllAssets" class="btn btn-sm btn-outline-danger" type="button">
        {{ t('labelClearAll') }}
      </button>
    </div>

    <!-- File selector modal -->
    <div v-if="selectorOpen" class="image-selector-modal">
      <div class="image-selector-content">
        <div class="image-selector-header">
          <h3>{{ t('titleSelectImages') }}</h3>
          <div class="selection-info" v-if="tempSelectedIds.length > 0">
            {{ t('labelSelectedCount').replace('{count}', tempSelectedIds.length) }}
          </div>
          <button @click="cancelImageSelection" class="close-btn" type="button">&times;</button>
        </div>

        <div class="image-selector-body">
          <div class="search-filter-container">
            <div class="search-box">
              <input
                  v-model="searchTerm"
                  type="text"
                  :placeholder="t('labelSearchFiles')"
                  class="form-control"
                  @input="debounceSearch"
              >
            </div>
            <div class="filter-box">
              <select v-model="mimeFilter" class="form-control" @change="searchImages">
                <option value="">{{ t('labelAllFileTypes') }}</option>
                <option value="image/jpeg">{{ t('labelJpegImages') }}</option>
                <option value="image/png">{{ t('labelPngImages') }}</option>
                <option value="image/gif">{{ t('labelGifImages') }}</option>
                <option value="image/svg+xml">{{ t('labelSvgImages') }}</option>
                <option value="application/pdf">{{ t('labelPdfDocuments') }}</option>
              </select>
            </div>
          </div>

          <div class="upload-container">
            <label for="multi-file-upload" class="btn btn-success upload-btn">
              <span>{{ t('labelUploadNewFile') }}</span>
            </label>
            <input
                id="multi-file-upload"
                type="file"
                multiple
                @change="uploadFiles"
                class="file-input"
            >
            <div v-if="uploadStatus" class="upload-status" :class="{'upload-success': uploadSuccess, 'upload-error': !uploadSuccess}">
              {{ uploadStatus }}
            </div>
          </div>

          <div v-if="loading" class="loading-indicator">
            <div class="spinner"></div>
            <p>{{ t('labelLoadingFiles') }}</p>
          </div>

          <div v-else class="image-grid multi-select-grid">
            <div
                v-for="item in filteredImages"
                :key="item.uuid"
                class="image-item"
                :class="{
                  'selected': isSelected(item.uuid),
                  'disabled': !isSelected(item.uuid) && !canSelectMore
                }"
                @click="toggleSelection(item.uuid)"
            >
              <div class="selection-checkbox">
                <svg v-if="isSelected(item.uuid)" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                </svg>
              </div>
              <img :src="item.preview_url" :alt="item.title">
              <div class="image-title">{{ item.title }}</div>
              <div class="selection-order" v-if="isSelected(item.uuid)">
                {{ getSelectionOrder(item.uuid) }}
              </div>
            </div>
          </div>

          <div v-if="!loading && filteredImages.length === 0" class="no-results">
            <p>{{ t('labelNoFilesFound') }}</p>
          </div>

          <div v-if="hasMoreImages && !loading" class="load-more">
            <button @click="loadMoreImages" class="btn btn-outline-primary" type="button">{{ t('labelLoadMore') }}</button>
          </div>
        </div>

        <div class="image-selector-footer">
          <div class="footer-info">
            <span v-if="maxItems && !canSelectMore" class="max-reached">
              {{ t('labelMaxItemsReached').replace('{max}', maxItems) }}
            </span>
          </div>
          <div class="footer-actions">
            <button @click="cancelImageSelection" class="btn btn-secondary" type="button">{{ t('labelCancel') }}</button>
            <button @click="confirmImageSelection" class="btn btn-primary" type="button">
              {{ t('labelSelect') }} ({{ tempSelectedIds.length }})
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    modelValue: {
      type: Array,
      default: () => []
    },
    fieldKey: {
      type: String,
      default: ''
    },
    label: {
      type: String,
      default: ''
    },
    inputName: {
      type: String,
      default: ''
    },
    required: {
      type: Boolean,
      default: false
    },
    multiple: {
      type: Boolean,
      default: true
    },
    maxItems: {
      type: Number,
      default: null
    },
    mimeFilterDefault: {
      type: String,
      default: ''
    },
    onUpdateValue: {
      type: Function,
      default: null
    },
    translations: {
      type: Object,
      default: () => ({})
    }
  },

  emits: ['update:modelValue'],

  data() {
    return {
      selectorOpen: false,
      tempSelectedIds: [],
      searchTerm: '',
      mimeFilter: '',
      images: [],
      filteredImages: [],
      loading: false,
      page: 1,
      limit: 20,
      total: 0,
      uploadStatus: '',
      uploadSuccess: false,
      searchTimeout: null,
      selectedAssets: [],
      draggedIndex: null,
      defaultTranslations: {
        labelSelectFile: 'Select Files',
        labelChangeFile: 'Change Files',
        labelClear: 'Remove',
        labelClearAll: 'Remove All',
        labelUploadNewFile: 'Upload New Files',
        labelSearchFiles: 'Search files...',
        labelAllFileTypes: 'All file types',
        labelJpegImages: 'JPEG images',
        labelPngImages: 'PNG images',
        labelGifImages: 'GIF images',
        labelSvgImages: 'SVG images',
        labelPdfDocuments: 'PDF documents',
        labelLoadingFiles: 'Loading files...',
        labelNoFilesFound: 'No files found. Try adjusting your search or upload a new file.',
        labelNoFileSelected: 'No files selected',
        labelLoadMore: 'Load More',
        labelCancel: 'Cancel',
        labelSelect: 'Select',
        labelUploadingStatus: 'Uploading...',
        labelUploadSuccessful: 'Upload successful!',
        labelUploadFailed: 'Upload failed. Please try again.',
        titleSelectImages: 'Select Files',
        labelAddMore: 'Add More',
        labelSelectedCount: '{count} selected',
        labelMaxItemsReached: 'Maximum reached ({max})',
        labelDragToReorder: 'Drag to reorder'
      }
    };
  },

  computed: {
    hasMoreImages() {
      return this.filteredImages.length < this.total;
    },

    canAddMore() {
      if (!this.maxItems) return true;
      return this.selectedAssets.length < this.maxItems;
    },

    canSelectMore() {
      if (!this.maxItems) return true;
      return this.tempSelectedIds.length < this.maxItems;
    },

    t() {
      return key => {
        return (this.translations && this.translations[key]) || this.defaultTranslations[key] || key;
      };
    }
  },

  watch: {
    modelValue: {
      immediate: true,
      deep: true,
      handler(newVal) {
        if (Array.isArray(newVal) && newVal.length > 0) {
          this.loadAssetDetails(newVal);
        } else if (typeof newVal === 'string' && newVal.startsWith('[')) {
          // Value might be a JSON string that wasn't parsed
          try {
            const parsed = JSON.parse(newVal);
            if (Array.isArray(parsed) && parsed.length > 0) {
              this.loadAssetDetails(parsed);
              return;
            }
          } catch (e) {
            // Ignore parse errors
          }
          this.selectedAssets = [];
        } else {
          this.selectedAssets = [];
        }
      }
    }
  },

  created() {
    if (this.mimeFilterDefault) {
      this.mimeFilter = this.mimeFilterDefault;
    }
  },

  methods: {
    async loadAssetDetails(uuids) {
      // Ensure uuids is an array of strings
      if (!Array.isArray(uuids)) {
        console.warn('loadAssetDetails: uuids is not an array', uuids);
        return;
      }

      // Filter out any non-string values and ensure we have valid UUIDs
      const validUuids = uuids.filter(uuid => typeof uuid === 'string' && uuid.length > 0);

      if (validUuids.length === 0) {
        this.selectedAssets = [];
        return;
      }

      // Keep existing assets that are still in the list
      const existingMap = {};
      this.selectedAssets.forEach(a => { existingMap[a.uuid] = a; });

      const newAssets = [];
      const fetchPromises = [];

      for (const uuid of validUuids) {
        if (existingMap[uuid] && existingMap[uuid].preview_url) {
          // Already have full data
          newAssets.push(existingMap[uuid]);
        } else {
          // Placeholder while loading
          const index = newAssets.length;
          newAssets.push({ uuid, preview_url: null, title: 'Loading...' });
          // Collect fetch promises
          fetchPromises.push(this.fetchAssetDetails(uuid, index));
        }
      }

      this.selectedAssets = newAssets;

      // Wait for all fetches to complete
      if (fetchPromises.length > 0) {
        await Promise.all(fetchPromises);
      }
    },

    async fetchAssetDetails(uuid, index) {
      try {
        const response = await fetch(`/crelish/asset/api-get?uuid=${uuid}`);
        if (response.ok) {
          const data = await response.json();
          // Find the asset by uuid instead of relying on index (more reliable)
          const assetIndex = this.selectedAssets.findIndex(a => a.uuid === uuid);
          if (assetIndex !== -1) {
            // Use splice for Vue reactivity
            this.selectedAssets.splice(assetIndex, 1, {
              uuid: data.uuid,
              preview_url: data.preview_url || data.full_url,
              title: data.title,
              mime: data.mime
            });
          }
        }
      } catch (error) {
        console.error('Failed to fetch asset details for', uuid, error);
      }
    },

    isSelected(uuid) {
      return this.tempSelectedIds.includes(uuid);
    },

    getSelectionOrder(uuid) {
      return this.tempSelectedIds.indexOf(uuid) + 1;
    },

    toggleSelection(uuid) {
      const index = this.tempSelectedIds.indexOf(uuid);
      if (index > -1) {
        this.tempSelectedIds.splice(index, 1);
      } else if (this.canSelectMore) {
        this.tempSelectedIds.push(uuid);
      }
    },

    openImageSelector() {
      // Start with currently selected assets
      this.tempSelectedIds = this.selectedAssets.map(a => a.uuid);
      this.searchTerm = '';
      this.page = 1;
      this.selectorOpen = true;
      this.searchImages();
    },

    cancelImageSelection() {
      this.selectorOpen = false;
      this.tempSelectedIds = [];
      this.uploadStatus = '';
    },

    confirmImageSelection() {
      // Get full asset data for newly selected items
      const newAssets = [];
      for (const uuid of this.tempSelectedIds) {
        // Check if we already have this asset's data
        const existing = this.selectedAssets.find(a => a.uuid === uuid);
        if (existing) {
          newAssets.push(existing);
        } else {
          // Check if it's in the filtered images
          const fromGrid = this.filteredImages.find(img => img.uuid === uuid);
          if (fromGrid) {
            newAssets.push({
              uuid: fromGrid.uuid,
              preview_url: fromGrid.preview_url,
              title: fromGrid.title,
              mime: fromGrid.mime
            });
          } else {
            // Will need to fetch
            newAssets.push({ uuid, preview_url: null, title: 'Loading...' });
          }
        }
      }

      this.selectedAssets = newAssets;
      this.emitUpdate();
      this.selectorOpen = false;
      this.uploadStatus = '';
    },

    removeAsset(index) {
      this.selectedAssets.splice(index, 1);
      this.emitUpdate();
    },

    clearAllAssets() {
      this.selectedAssets = [];
      this.emitUpdate();
    },

    emitUpdate() {
      const uuids = this.selectedAssets.map(a => a.uuid);
      this.$emit('update:modelValue', uuids);
      if (typeof this.onUpdateValue === 'function') {
        this.onUpdateValue(uuids);
      }
    },

    // Drag and drop handlers
    onDragStart(event, index) {
      this.draggedIndex = index;
      event.dataTransfer.effectAllowed = 'move';
      event.target.classList.add('dragging');
    },

    onDragOver(event, index) {
      event.dataTransfer.dropEffect = 'move';
    },

    onDrop(event, index) {
      if (this.draggedIndex !== null && this.draggedIndex !== index) {
        const item = this.selectedAssets.splice(this.draggedIndex, 1)[0];
        this.selectedAssets.splice(index, 0, item);
        this.emitUpdate();
      }
    },

    onDragEnd(event) {
      this.draggedIndex = null;
      event.target.classList.remove('dragging');
    },

    debounceSearch() {
      if (this.searchTimeout) {
        clearTimeout(this.searchTimeout);
      }
      this.searchTimeout = setTimeout(() => {
        this.page = 1;
        this.searchImages();
      }, 300);
    },

    async searchImages() {
      this.loading = true;
      this.filteredImages = [];

      try {
        const params = new URLSearchParams();
        if (this.searchTerm) {
          params.append('q', this.searchTerm);
        }
        if (this.mimeFilter) {
          params.append('mime', this.mimeFilter);
        }
        params.append('page', this.page);
        params.append('limit', this.limit);

        const response = await fetch(`/crelish/asset/api-search?${params.toString()}`);

        if (response.ok) {
          const data = await response.json();
          this.images = data.items;
          this.filteredImages = data.items;
          this.total = data.total;
        }
      } catch (error) {
        console.error('Search failed', error);
      } finally {
        this.loading = false;
      }
    },

    async loadMoreImages() {
      this.page++;
      this.loading = true;

      try {
        const params = new URLSearchParams();
        if (this.searchTerm) {
          params.append('q', this.searchTerm);
        }
        if (this.mimeFilter) {
          params.append('mime', this.mimeFilter);
        }
        params.append('page', this.page);
        params.append('limit', this.limit);

        const response = await fetch(`/crelish/asset/api-search?${params.toString()}`);

        if (response.ok) {
          const data = await response.json();
          this.images = [...this.images, ...data.items];
          this.filteredImages = [...this.filteredImages, ...data.items];
          this.total = data.total;
        }
      } catch (error) {
        console.error('Load more failed', error);
      } finally {
        this.loading = false;
      }
    },

    async uploadFiles(event) {
      const files = event.target.files;
      if (!files || files.length === 0) return;

      this.uploadStatus = this.t('labelUploadingStatus');
      this.uploadSuccess = false;
      this.loading = true;

      let successCount = 0;
      const uploadedUuids = [];

      for (const file of files) {
        const formData = new FormData();
        formData.append('file', file);

        try {
          const response = await fetch('/crelish/asset/api-upload', {
            method: 'POST',
            body: formData
          });

          if (response.ok) {
            const data = await response.json();
            if (data.success && data.asset) {
              successCount++;
              uploadedUuids.push(data.asset.uuid);
            }
          }
        } catch (error) {
          console.error('Upload failed for file', file.name, error);
        }
      }

      if (successCount > 0) {
        this.uploadStatus = `${successCount} ${this.t('labelUploadSuccessful')}`;
        this.uploadSuccess = true;

        // Add uploaded files to selection (respecting maxItems)
        for (const uuid of uploadedUuids) {
          if (this.canSelectMore) {
            this.tempSelectedIds.push(uuid);
          }
        }

        // Refresh the search
        await this.searchImages();
      } else {
        this.uploadStatus = this.t('labelUploadFailed');
        this.uploadSuccess = false;
      }

      // Reset file input
      event.target.value = '';
      this.loading = false;
    }
  }
};
</script>

<style scoped>
.multi-asset-selector {
  width: 100%;
}

.multi-asset-selector-label {
  font-weight: 500;
  margin-bottom: 0.5rem;
}

.required-indicator {
  color: #dc3545;
  margin-left: 2px;
}

/* Selected assets grid */
.selected-assets-area {
  width: 100%;
  margin-bottom: 1rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  background-color: #f8f9fa;
  padding: 0.75rem;
  min-height: 120px;
}

.selected-assets-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.selected-asset-item {
  position: relative;
  width: 100px;
  height: 100px;
  border: 2px solid #ddd;
  border-radius: 4px;
  overflow: hidden;
  background: white;
  cursor: grab;
  transition: all 0.2s;
}

.selected-asset-item:hover {
  border-color: #007bff;
}

.selected-asset-item.dragging {
  opacity: 0.5;
  border-style: dashed;
}

.asset-preview-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.asset-drag-handle {
  position: absolute;
  top: 2px;
  left: 2px;
  background: rgba(255,255,255,0.9);
  border-radius: 2px;
  padding: 2px;
  cursor: grab;
  opacity: 0;
  transition: opacity 0.2s;
}

.selected-asset-item:hover .asset-drag-handle {
  opacity: 1;
}

.asset-remove-btn {
  position: absolute;
  top: 2px;
  right: 2px;
  background: rgba(220, 53, 69, 0.9);
  border: none;
  border-radius: 50%;
  width: 22px;
  height: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.2s;
  color: white;
}

.selected-asset-item:hover .asset-remove-btn {
  opacity: 1;
}

.asset-index {
  position: absolute;
  bottom: 2px;
  left: 2px;
  background: rgba(0,0,0,0.6);
  color: white;
  font-size: 10px;
  padding: 1px 5px;
  border-radius: 2px;
}

.asset-loading {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f0f0f0;
}

.spinner-small {
  border: 2px solid #f3f3f3;
  border-top: 2px solid #007bff;
  border-radius: 50%;
  width: 20px;
  height: 20px;
  animation: spin 1s linear infinite;
}

.add-more-item {
  width: 100px;
  height: 100px;
  border: 2px dashed #ccc;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: #999;
  transition: all 0.2s;
}

.add-more-item:hover {
  border-color: #007bff;
  color: #007bff;
}

/* Empty state */
.empty-preview {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2rem 1rem;
  text-align: center;
}

.placeholder-icon {
  margin-bottom: 1rem;
  color: #6c757d;
}

.placeholder-text {
  margin-bottom: 1rem;
  color: #6c757d;
}

/* Action buttons */
.multi-asset-actions {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

/* Modal styles */
.image-selector-modal {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.image-selector-content {
  background-color: white;
  border-radius: 4px;
  width: 90%;
  max-width: 1000px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}

.image-selector-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  border-bottom: 1px solid #ddd;
  gap: 1rem;
}

.image-selector-header h3 {
  margin: 0;
}

.selection-info {
  background: #007bff;
  color: white;
  padding: 0.25rem 0.75rem;
  border-radius: 20px;
  font-size: 0.875rem;
}

.close-btn {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
  margin-left: auto;
}

.image-selector-body {
  padding: 1rem;
  overflow-y: auto;
  flex: 1;
}

.search-filter-container {
  display: flex;
  gap: 1rem;
  margin-bottom: 1rem;
}

.search-box {
  flex: 2;
}

.filter-box {
  flex: 1;
}

.upload-container {
  margin-bottom: 1rem;
  display: flex;
  align-items: center;
}

.file-input {
  display: none;
}

.upload-btn {
  cursor: pointer;
  display: inline-block;
}

.upload-status {
  margin-left: 1rem;
  padding: 0.5rem;
  border-radius: 4px;
}

.upload-success {
  background-color: #d4edda;
  color: #155724;
}

.upload-error {
  background-color: #f8d7da;
  color: #721c24;
}

/* Multi-select grid */
.multi-select-grid .image-item {
  position: relative;
}

.selection-checkbox {
  position: absolute;
  top: 8px;
  left: 8px;
  width: 24px;
  height: 24px;
  background: white;
  border: 2px solid #ddd;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1;
}

.image-item.selected .selection-checkbox {
  background: #007bff;
  border-color: #007bff;
  color: white;
}

.selection-order {
  position: absolute;
  top: 8px;
  right: 8px;
  background: #007bff;
  color: white;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: bold;
}

.image-item.disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.image-selector-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  border-top: 1px solid #ddd;
}

.footer-info .max-reached {
  color: #dc3545;
  font-size: 0.875rem;
}

.footer-actions {
  display: flex;
  gap: 0.5rem;
}

/* Image grid */
.image-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 1rem;
  margin-top: 1rem;
}

.image-item {
  border: 2px solid #ddd;
  border-radius: 4px;
  padding: 0.5rem;
  cursor: pointer;
  transition: all 0.2s;
  height: 160px;
  display: flex;
  flex-direction: column;
}

.image-item:hover:not(.disabled) {
  border-color: #007bff;
}

.image-item.selected {
  border-color: #007bff;
  background-color: #e6f7ff;
}

.image-item img {
  width: 100%;
  height: 100px;
  object-fit: contain;
  background-color: #f8f9fa;
  margin-bottom: 0.5rem;
}

.image-title {
  font-size: 0.8rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-align: center;
}

.loading-indicator {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2rem;
}

.spinner {
  border: 4px solid #f3f3f3;
  border-top: 4px solid #007bff;
  border-radius: 50%;
  width: 30px;
  height: 30px;
  animation: spin 1s linear infinite;
  margin-bottom: 1rem;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.no-results {
  text-align: center;
  padding: 2rem;
  color: #6c757d;
}

.load-more {
  text-align: center;
  margin-top: 1rem;
}

/* Form controls */
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
  cursor: pointer;
}

.btn-primary {
  color: #fff;
  background-color: #007bff;
  border-color: #007bff;
}

.btn-outline-primary {
  color: #007bff;
  background-color: transparent;
  border-color: #007bff;
}

.btn-secondary {
  color: #fff;
  background-color: #6c757d;
  border-color: #6c757d;
}

.btn-success {
  color: #fff;
  background-color: #28a745;
  border-color: #28a745;
}

.btn-outline-danger {
  color: #dc3545;
  background-color: transparent;
  border-color: #dc3545;
}

.btn-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
}

.btn:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.multi-asset-selector.required .multi-asset-selector-label::after {
  content: "*";
  color: #dc3545;
  margin-left: 2px;
}

@media (max-width: 768px) {
  .search-filter-container {
    flex-direction: column;
    gap: 0.5rem;
  }

  .image-grid {
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  }

  .selected-asset-item {
    width: 80px;
    height: 80px;
  }

  .add-more-item {
    width: 80px;
    height: 80px;
  }
}
</style>
