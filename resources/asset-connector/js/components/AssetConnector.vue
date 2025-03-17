<!-- components/common/ImageSelector.vue -->
<template>
  <div class="image-selector">
    <div v-if="getImageUrl(selectedId)" class="selected-image">
      <img :src="getImageUrl(selectedId)" alt="Selected image" class="preview-img">
      <div class="image-details">
        <button @click="openImageSelector" class="btn btn-sm btn-primary" type="button">Change Image</button>
        <button @click="clearImage" class="btn btn-sm btn-outline-danger" type="button">Clear</button>
      </div>
    </div>
    <button v-else @click="openImageSelector" class="btn btn-outline-primary" type="button">
      Select Image
    </button>

    <!-- Image selector modal -->
    <div v-if="selectorOpen" class="image-selector-modal">
      <div class="image-selector-content">
        <div class="image-selector-header">
          <h3>Select Image</h3>
          <button @click="cancelImageSelection" class="close-btn">&times;</button>
        </div>

        <div class="image-selector-body">
          <div class="search-filter-container">
            <div class="search-box">
              <input
                  v-model="searchTerm"
                  type="text"
                  placeholder="Search images..."
                  class="form-control"
                  @input="debounceSearch"
              >
            </div>
            <div class="filter-box">
              <select v-model="mimeFilter" class="form-control" @change="searchImages">
                <option value="">All file types</option>
                <option value="image/jpeg">JPEG images</option>
                <option value="image/png">PNG images</option>
                <option value="image/gif">GIF images</option>
                <option value="image/svg+xml">SVG images</option>
                <option value="application/pdf">PDF documents</option>
              </select>
            </div>
          </div>

          <div class="upload-container">
            <label for="file-upload" class="btn btn-success upload-btn">
              <span>Upload New Image</span>
            </label>
            <input
                id="file-upload"
                type="file"
                @change="uploadFile"
                accept="image/*"
                class="file-input"
            >
            <div v-if="uploadStatus" class="upload-status" :class="{'upload-success': uploadSuccess, 'upload-error': !uploadSuccess}">
              {{ uploadStatus }}
            </div>
          </div>

          <div v-if="loading" class="loading-indicator">
            <div class="spinner"></div>
            <p>Loading images...</p>
          </div>

          <div v-else class="image-grid">
            <div
                v-for="img in filteredImages"
                :key="img.uuid"
                class="image-item"
                :class="{'selected': tempSelectedId === img.uuid}"
                @click="selectImage(img.uuid)"
            >
              <img :src="img.preview_url" :alt="img.title">
              <div class="image-title">{{ img.title }}</div>
            </div>
          </div>

          <div v-if="!loading && filteredImages.length === 0" class="no-results">
            <p>No images found. Try adjusting your search or upload a new image.</p>
          </div>

          <div v-if="hasMoreImages && !loading" class="load-more">
            <button @click="loadMoreImages" class="btn btn-outline-primary" type="button">Load More</button>
          </div>
        </div>

        <div class="image-selector-footer">
          <button @click="cancelImageSelection" class="btn btn-secondary" type="button">Cancel</button>
          <button @click="confirmImageSelection" class="btn btn-primary" :disabled="!tempSelectedId" type="button">Select</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    selectedId: {
      type: [Number, String],
      default: null
    }
  },

  emits: ['select'],

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
      imageCache: {}
    };
  },

  computed: {
    hasMoreImages() {
      return this.filteredImages.length < this.total;
    }
  },

  watch: {
    selectedId(newId) {
      if (newId) {
        this.fetchImageUrl(newId);
      }
    }
  },

  created() {
    // Prefetch the selected image if available
    this.prefetchImages();
  },

  methods: {
    async fetchImageUrl(imageId) {
      // If no image is selected, return null
      if (!imageId) return null;

      // If we already have this image URL cached, return it
      if (this.imageCache[imageId]) {
        return this.imageCache[imageId];
      }

      try {
        const response = await fetch(`/crelish/asset/api-get?uuid=${imageId}`);

        if (response.ok) {
          const data = await response.json();
          // Store the URL in the cache
          this.imageCache[imageId] = data.preview_url || data.full_url;
          return this.imageCache[imageId];
        }
      } catch (error) {
        console.error('Error fetching selected image:', error);
      }

      return null;
    },

    getImageUrl(imageId) {
      if (!imageId) return null;

      // If we have the URL cached, return it immediately
      if (this.imageCache[imageId]) {
        return this.imageCache[imageId];
      }

      // Trigger the fetch for this image but don't wait for it
      this.fetchImageUrl(imageId);

      // Return a loading placeholder or null
      return 'https://via.placeholder.com/150x80?text=Loading...';
    },

    prefetchImages() {
      if (this.selectedId) {
        this.fetchImageUrl(this.selectedId);
      }
    },

    openImageSelector() {
      this.tempSelectedId = this.selectedId;
      this.searchTerm = '';
      this.mimeFilter = '';
      this.page = 1;
      this.selectorOpen = true;
      this.searchImages();
    },

    cancelImageSelection() {
      this.selectorOpen = false;
      this.tempSelectedId = null;
      this.uploadStatus = '';
    },

    clearImage() {
      this.$emit('select', null);
    },

    selectImage(imageId) {
      this.tempSelectedId = imageId;
    },

    confirmImageSelection() {
      if (this.tempSelectedId) {
        this.$emit('select', this.tempSelectedId);
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
        } else {
          console.error('Failed to fetch images:', response.statusText);
        }
      } catch (error) {
        console.error('Error searching images:', error);
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
          }
        }
      } catch (error) {
        console.error('Error fetching selected image:', error);
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
        } else {
          console.error('Failed to load more images:', response.statusText);
        }
      } catch (error) {
        console.error('Error loading more images:', error);
      } finally {
        this.loading = false;
      }
    },

    async uploadFile(event) {
      const file = event.target.files[0];
      if (!file) return;

      this.uploadStatus = 'Uploading...';
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
            this.uploadStatus = 'Upload successful!';
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
          this.uploadStatus = 'Upload failed. Please try again.';
          this.uploadSuccess = false;
        }
      } catch (error) {
        console.error('Error uploading file:', error);
        this.uploadStatus = 'Upload failed. Please try again.';
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

.selected-image {
  display: flex;
  align-items: flex-start;
  margin-bottom: 0.5rem;
}

.preview-img {
  width: 150px;
  height: 80px;
  object-fit: contain;
  margin-right: 1rem;
  border: 1px solid #ddd;
  background-color: #f8f9fa;
  padding: 5px;
}

.image-details {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
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
  height: 170px;
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
</style>