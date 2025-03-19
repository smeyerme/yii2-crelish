<!-- components/common/ImageSelector.vue -->
<template>
  <div class="image-selector" :class="{'required': required}">
    <div v-if="label" class="image-selector-label">
      {{ label }}
    </div>
    
    <!-- File preview area - always visible -->
    <div class="file-preview-area">
      <div v-if="currentImageUrl" class="selected-file">
        <img :src="currentImageUrl" :key="'img-'+modelValue" :alt="selectedFileTitle || 'Selected file'" class="preview-img">
        <div class="file-details">
          <div v-if="selectedFileTitle" class="file-title">{{ selectedFileTitle }}</div>
          <div v-if="selectedFileMime" class="file-mime">{{ selectedFileMime }}</div>
          <div class="file-actions">
            <button @click="openImageSelector" class="btn btn-sm btn-primary" type="button">{{ t('labelChangeFile') }}</button>
            <button @click="clearImage" class="btn btn-sm btn-outline-danger" type="button">{{ t('labelClear') }}</button>
          </div>
        </div>
      </div>
      <div v-else class="empty-preview">
        <div class="placeholder-icon">
          <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
            <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>
            <polyline points="13 2 13 9 20 9"></polyline>
          </svg>
        </div>
        <p class="placeholder-text">{{ t('labelNoFileSelected') }}</p>
        <button @click="openImageSelector" class="btn btn-outline-primary" type="button">
          {{ t('labelSelectFile') }} <span v-if="required" class="required-indicator">*</span>
        </button>
      </div>
    </div>

    <!-- File selector modal -->
    <div v-if="selectorOpen" class="image-selector-modal">
      <div class="image-selector-content">
        <div class="image-selector-header">
          <h3>{{ t('titleSelectFile') }}</h3>
          <button @click="cancelImageSelection" class="close-btn">&times;</button>
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
                <option value="application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">{{ t('labelWordDocuments') }}</option>
                <option value="application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">{{ t('labelExcelDocuments') }}</option>
                <option value="text/">{{ t('labelTextDocuments') }}</option>
                <option value="audio/">{{ t('labelAudioFiles') }}</option>
                <option value="video/">{{ t('labelVideoFiles') }}</option>
              </select>
            </div>
          </div>

          <div class="upload-container">
            <label for="file-upload" class="btn btn-success upload-btn">
              <span>{{ t('labelUploadNewFile') }}</span>
            </label>
            <input
                id="file-upload"
                type="file"
                @change="uploadFile"
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

          <div v-else class="image-grid">
            <div
                v-for="item in filteredImages"
                :key="item.uuid"
                class="image-item"
                :class="{'selected': tempSelectedId === item.uuid}"
                @click="selectImage(item.uuid)"
            >
              <img :src="item.preview_url" :alt="item.title">
              <div class="image-title">{{ item.title }}</div>
              <div class="file-type">{{ getFileTypeLabel(item.mime) }}</div>
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
          <button @click="cancelImageSelection" class="btn btn-secondary" type="button">{{ t('labelCancel') }}</button>
          <button @click="confirmImageSelection" class="btn btn-primary" :disabled="!tempSelectedId" type="button">{{ t('labelSelect') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    modelValue: {
      type: [Number, String],
      default: null
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
      tempSelectedId: null,
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
      imageCache: {},
      currentImageUrl: null,
      selectedFileTitle: null,
      selectedFileMime: null,
      // Default translations as fallbacks
      defaultTranslations: {
        labelSelectFile: 'Select File',
        labelChangeFile: 'Change File',
        labelClear: 'Clear',
        labelUploadNewFile: 'Upload New File',
        labelSearchFiles: 'Search files...',
        labelAllFileTypes: 'All file types',
        labelJpegImages: 'JPEG images',
        labelPngImages: 'PNG images',
        labelGifImages: 'GIF images',
        labelSvgImages: 'SVG images',
        labelPdfDocuments: 'PDF documents',
        labelWordDocuments: 'Word documents',
        labelExcelDocuments: 'Excel spreadsheets',
        labelTextDocuments: 'Text documents',
        labelAudioFiles: 'Audio files',
        labelVideoFiles: 'Video files',
        labelLoadingFiles: 'Loading files...',
        labelNoFilesFound: 'No files found. Try adjusting your search or upload a new file.',
        labelNoFileSelected: 'No file selected',
        labelLoadMore: 'Load More',
        labelCancel: 'Cancel',
        labelSelect: 'Select',
        labelUploadingStatus: 'Uploading...',
        labelUploadSuccessful: 'Upload successful!',
        labelUploadFailed: 'Upload failed. Please try again.',
        titleSelectFile: 'Select File'
      }
    };
  },

  computed: {
    hasMoreImages() {
      return this.filteredImages.length < this.total;
    },
    
    // Computed property to get translations with fallbacks
    t() {
      return key => {
        // Return the translation from props if it exists, otherwise use default
        return (this.translations && this.translations[key]) || this.defaultTranslations[key] || key;
      };
    }
  },

  watch: {
    modelValue: {
      immediate: true,
      handler(newVal, oldVal) {
        // Reset the currentImageUrl when modelValue changes
        if (!newVal) {
          this.currentImageUrl = null;
          this.tempSelectedId = null;
          this.selectedFileTitle = null;
          this.selectedFileMime = null;
        } else {
          this.fetchImageDetails(newVal);
          this.tempSelectedId = newVal;
        }
        
        // If the value is cleared, make sure to clear the cache for the old value
        if (!newVal && oldVal && this.imageCache[oldVal]) {
          delete this.imageCache[oldVal];
        }
      }
    }
  },

  created() {
    // Prefetch the selected image if available
    this.prefetchImages();
    
    // Initialize tempSelectedId with modelValue
    this.tempSelectedId = this.modelValue;
    
    // If we have an initial value, make sure to call onUpdateValue
    if (this.modelValue && typeof this.onUpdateValue === 'function') {
      this.onUpdateValue(this.modelValue);
    }
  },

  methods: {
    getFileTypeLabel(mimeType) {
      if (!mimeType) return '';
      
      if (mimeType.startsWith('image/')) {
        return 'Image';
      } else if (mimeType === 'application/pdf') {
        return 'PDF';
      } else if (mimeType.includes('word')) {
        return 'Word';
      } else if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) {
        return 'Excel';
      } else if (mimeType.startsWith('text/')) {
        return 'Text';
      } else if (mimeType.startsWith('audio/')) {
        return 'Audio';
      } else if (mimeType.startsWith('video/')) {
        return 'Video';
      } else {
        return 'File';
      }
    },
    
    async fetchImageDetails(imageId) {
      // If no image is selected, return null
      if (!imageId) return null;

      try {
        const response = await fetch(`/crelish/asset/api-get?uuid=${imageId}`);

        if (response.ok) {
          const data = await response.json();
          // Store the URL in the cache
          this.imageCache[imageId] = data.preview_url || data.full_url;
          this.currentImageUrl = this.imageCache[imageId];
          this.selectedFileTitle = data.title;
          this.selectedFileMime = data.mime;
          return this.imageCache[imageId];
        }
      } catch (error) {
        // Handle error silently
      }

      return null;
    },

    prefetchImages() {
      if (this.modelValue) {
        this.fetchImageDetails(this.modelValue);
        this.tempSelectedId = this.modelValue;
      }
    },

    openImageSelector() {
      this.tempSelectedId = this.modelValue;
      this.searchTerm = '';
      this.mimeFilter = '';
      this.page = 1;
      this.selectorOpen = true;
      this.searchImages();
    },

    cancelImageSelection() {
      this.selectorOpen = false;
      this.tempSelectedId = this.modelValue; // Reset to the current selection
      this.uploadStatus = '';
    },

    clearImage() {
      // Store the old value to clear from cache if needed
      const oldValue = this.modelValue;
      
      // Reset state first to ensure UI changes
      this.currentImageUrl = null;
      this.tempSelectedId = null;
      this.selectedFileTitle = null;
      this.selectedFileMime = null;
      
      // Update the model value
      this.$emit('update:modelValue', null);
      
      // Call the onUpdateValue callback to ensure the hidden input is updated
      if (typeof this.onUpdateValue === 'function') {
        this.onUpdateValue(null);
      }
      
      // Clear the cached image
      if (oldValue && this.imageCache[oldValue]) {
        delete this.imageCache[oldValue];
      }
    },

    selectImage(imageId) {
      this.tempSelectedId = imageId;
    },

    confirmImageSelection() {
      if (this.tempSelectedId) {
        // Get the selected image from the filtered images
        const selectedImage = this.filteredImages.find(img => img.uuid === this.tempSelectedId);
        
        // If we have the image data, update the cache immediately
        if (selectedImage) {
          if (selectedImage.preview_url) {
            this.imageCache[this.tempSelectedId] = selectedImage.preview_url;
            this.currentImageUrl = selectedImage.preview_url;
          }
          this.selectedFileTitle = selectedImage.title;
          this.selectedFileMime = selectedImage.mime;
        }
        
        // Update the model value
        this.$emit('update:modelValue', this.tempSelectedId);
        
        // Call the onUpdateValue callback to ensure the hidden input is updated
        if (typeof this.onUpdateValue === 'function') {
          this.onUpdateValue(this.tempSelectedId);
        }
        
        // If we don't have the image preview yet, fetch it
        if (!this.currentImageUrl) {
          this.fetchImageDetails(this.tempSelectedId);
        }
      }
      
      this.selectorOpen = false;
      this.uploadStatus = '';
    },

    debounceSearch() {
      // Cancel the previous timeout
      if (this.searchTimeout) {
        clearTimeout(this.searchTimeout);
      }

      // Set a new timeout to search after 300ms of inactivity
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

          // If the currently selected image is not in the response, fetch it specifically
          if (this.tempSelectedId && !this.filteredImages.find(img => img.uuid === this.tempSelectedId)) {
            await this.fetchSelectedImage();
          }
        }
      } catch (error) {
        // Handle error silently
      } finally {
        this.loading = false;
      }
    },

    async fetchSelectedImage() {
      if (!this.tempSelectedId) return;

      try {
        const response = await fetch(`/crelish/asset/api-get?uuid=${this.tempSelectedId}`);

        if (response.ok) {
          const data = await response.json();
          // Add this image to the start of our images array if it's not already there
          if (data && !this.images.find(img => img.uuid === data.uuid)) {
            this.images.unshift(data);
            this.filteredImages.unshift(data);
          }
        }
      } catch (error) {
        // Handle error silently
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
        // Handle error silently
      } finally {
        this.loading = false;
      }
    },

    async uploadFile(event) {
      const file = event.target.files[0];
      if (!file) return;

      this.uploadStatus = this.t('labelUploadingStatus');
      this.uploadSuccess = false;
      this.loading = true;

      const formData = new FormData();
      formData.append('file', file);

      try {
        const response = await fetch('/crelish/asset/api-upload', {
          method: 'POST',
          body: formData
        });

        if (response.ok) {
          const data = await response.json();

          if (data.success) {
            this.uploadStatus = this.t('labelUploadSuccessful');
            this.uploadSuccess = true;

            // Select the newly uploaded image
            if (data.asset) {
              this.tempSelectedId = data.asset.uuid;
              
              // Reset the file input
              event.target.value = '';
              
              // Refresh the search to get the updated list from the server
              // This prevents duplicate entries in the list
              await this.searchImages();
            }
          } else {
            this.uploadStatus = data.message || 'Upload failed';
            this.uploadSuccess = false;
          }
        } else {
          this.uploadStatus = this.t('labelUploadFailed');
          this.uploadSuccess = false;
        }
      } catch (error) {
        this.uploadStatus = this.t('labelUploadFailed');
        this.uploadSuccess = false;
      } finally {
        this.loading = false;
      }
    }
  }
};
</script>

<style scoped>
.image-selector {
  width: 100%;
}

.image-selector-label {
  font-weight: 500;
  margin-bottom: 0.5rem;
}

.required-indicator {
  color: #dc3545;
  margin-left: 2px;
}

/* File preview area */
.file-preview-area {
  width: 100%;
  max-width: 38rem;
  margin-bottom: 1rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  background-color: #f8f9fa;
  overflow: hidden;
}

.selected-file {
  display: flex;
  flex-direction: column;
  width: 100%;
}

.preview-img {
  width: 100%;
  height: auto;
  object-fit: contain;
  background-color: #f8f9fa;
  border-bottom: 1px solid #ddd;
}

.file-details {
  padding: 0.75rem;
}

.file-title {
  font-weight: 500;
  margin-bottom: 0.25rem;
  word-break: break-word;
}

.file-mime {
  font-size: 0.8rem;
  color: #6c757d;
  margin-bottom: 0.75rem;
}

.file-actions {
  display: flex;
  gap: 0.5rem;
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

/* Image selector modal */
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
}

.close-btn {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
}

.image-selector-body {
  padding: 1rem;
  overflow-y: auto;
  max-height: 70vh;
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

.image-selector-footer {
  display: flex;
  justify-content: flex-end;
  padding: 1rem;
  border-top: 1px solid #ddd;
  gap: 0.5rem;
}

.image-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 1rem;
  margin-top: 1rem;
}

.image-item {
  border: 2px solid #ddd;
  border-radius: 4px;
  padding: 0.5rem;
  cursor: pointer;
  transition: all 0.2s;
  height: 190px;
  display: flex;
  flex-direction: column;
}

.image-item:hover {
  border-color: #007bff;
}

.image-item.selected {
  border-color: #007bff;
  background-color: #e6f7ff;
}

.image-item img {
  width: 100%;
  height: 120px;
  object-fit: contain;
  background-color: #f8f9fa;
  margin-bottom: 0.5rem;
}

.image-title {
  font-size: 0.85rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-align: center;
}

.file-type {
  font-size: 0.75rem;
  color: #6c757d;
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
  line-height: 1.5;
  border-radius: 0.2rem;
}

.btn:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.image-selector.required .image-selector-label::after {
  content: "*";
  color: #dc3545;
  margin-left: 2px;
}

@media (max-width: 768px) {
  .file-preview-area {
    max-width: 100%;
  }
  
  .search-filter-container {
    flex-direction: column;
    gap: 0.5rem;
  }
  
  .image-grid {
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  }
}
</style>