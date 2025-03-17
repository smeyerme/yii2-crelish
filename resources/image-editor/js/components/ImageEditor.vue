<template>
  <div class="image-editor">
    <!-- Original Image Display -->
    <div class="original-image-container" v-if="originalAsset">
      <h3>Original Image</h3>
      <div class="image-preview">
        <img :src="originalAsset.full_url" :alt="originalAsset.title" class="original-image" ref="originalImage" />
      </div>
      <div class="image-details">
        <p><strong>Title:</strong> {{ originalAsset.title }}</p>
        <p><strong>Type:</strong> {{ originalAsset.mime }}</p>
      </div>
    </div>

    <!-- Image Selection if no image is selected -->
    <div class="image-selection" v-if="!originalAsset">
      <button @click.prevent="openAssetSelector" class="btn btn-primary" type="button">Select Image</button>
    </div>

    <!-- Editor Controls -->
    <div class="editor-controls" v-if="originalAsset">
      <div class="control-section">
        <h4>Editing Tools</h4>
        <div class="tool-buttons">
          <button 
            @click.prevent="activateTool('crop')" 
            class="btn" 
            :class="{'btn-primary': activeTool === 'crop', 'btn-outline-primary': activeTool !== 'crop'}"
            type="button"
          >
            Crop
          </button>
          <button 
            @click.prevent="activateTool('rotate')" 
            class="btn" 
            :class="{'btn-primary': activeTool === 'rotate', 'btn-outline-primary': activeTool !== 'rotate'}"
            type="button"
          >
            Rotate
          </button>
          <button 
            @click.prevent="activateTool('flip')" 
            class="btn" 
            :class="{'btn-primary': activeTool === 'flip', 'btn-outline-primary': activeTool !== 'flip'}"
            type="button"
          >
            Flip
          </button>
          <button 
            @click.prevent="resetEdits" 
            class="btn btn-outline-danger"
            type="button"
          >
            Reset
          </button>
        </div>
      </div>

      <!-- Tool-specific controls -->
      <div class="tool-controls">
        <!-- Crop Controls -->
        <div v-if="activeTool === 'crop'" class="crop-controls">
          <div class="crop-actions">
            <button @click.prevent="applyCrop" class="btn btn-success" type="button">Apply Crop</button>
            <button @click.prevent="cancelCrop" class="btn btn-outline-secondary" type="button">Cancel</button>
          </div>
        </div>

        <!-- Rotate Controls -->
        <div v-if="activeTool === 'rotate'" class="rotate-controls">
          <div class="rotate-slider">
            <label for="rotation-angle">Rotation Angle: {{ editParams.rotate }}°</label>
            <input 
              type="range" 
              id="rotation-angle" 
              v-model.number="editParams.rotate" 
              min="-180" 
              max="180" 
              step="1"
              @input="updatePreview"
            />
          </div>
          <div class="rotate-buttons">
            <button @click.prevent="rotateLeft" class="btn btn-outline-primary" type="button">Rotate -90°</button>
            <button @click.prevent="rotateRight" class="btn btn-outline-primary" type="button">Rotate +90°</button>
          </div>
        </div>

        <!-- Flip Controls -->
        <div v-if="activeTool === 'flip'" class="flip-controls">
          <button @click.prevent="flipHorizontal" class="btn btn-outline-primary" type="button">Flip Horizontal</button>
          <button @click.prevent="flipVertical" class="btn btn-outline-primary" type="button">Flip Vertical</button>
        </div>
      </div>

      <!-- Preview -->
      <div class="preview-section">
        <h4>Preview</h4>
        <div class="preview-container">
          <img :src="previewUrl" alt="Preview" class="preview-image" />
        </div>
      </div>

      <!-- Save Controls -->
      <div class="save-controls">
        <button @click.prevent="saveEditedImage" class="btn btn-success" :disabled="!hasChanges" type="button">Save Edited Image</button>
        <div v-if="saveStatus" class="save-status" :class="{'save-success': saveSuccess, 'save-error': !saveSuccess}">
          {{ saveStatus }}
        </div>
      </div>

      <!-- Edited Versions -->
      <div class="edited-versions" v-if="editedVersions.length > 0">
        <h4>Edited Versions</h4>
        <div class="versions-list">
          <div 
            v-for="version in editedVersions" 
            :key="version.uuid" 
            class="version-item"
            @click="selectVersion(version)"
          >
            <img :src="version.preview_url" :alt="version.title" class="version-thumbnail" />
            <div class="version-info">
              <p class="version-title">{{ version.title }}</p>
              <p class="version-type">{{ version.edit_type }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Asset Selector Modal -->
    <div v-if="showAssetSelector" class="asset-selector-modal">
      <div class="modal-backdrop" @click.prevent="closeAssetSelector"></div>
      <div class="modal-container">
        <div class="modal-header">
          <h3>Select Image</h3>
          <button type="button" @click.prevent="closeAssetSelector" class="btn-close">&times;</button>
        </div>
        <div class="modal-body">
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
              <select v-model="mimeFilter" class="form-control" @change="searchAssets">
                <option value="">All image types</option>
                <option value="image/jpeg">JPEG images</option>
                <option value="image/png">PNG images</option>
                <option value="image/gif">GIF images</option>
                <option value="image/webp">WebP images</option>
              </select>
            </div>
          </div>

          <div v-if="loading" class="loading-indicator">
            <div class="spinner"></div>
            <p>Loading images...</p>
          </div>

          <div v-else class="image-grid">
            <div
              v-for="asset in filteredAssets"
              :key="asset.uuid"
              class="image-item"
              :class="{'selected': selectedAssetId === asset.uuid}"
              @click.prevent="selectAsset(asset.uuid)"
            >
              <img :src="asset.preview_url" :alt="asset.title">
              <div class="image-title">{{ asset.title }}</div>
            </div>
          </div>

          <div v-if="!loading && filteredAssets.length === 0" class="no-results">
            <p>No images found. Try adjusting your search.</p>
          </div>

          <div v-if="hasMoreAssets && !loading" class="load-more">
            <button @click.prevent="loadMoreAssets" class="btn btn-outline-primary" type="button">Load More</button>
          </div>
        </div>

        <div class="modal-footer">
          <button @click.prevent="closeAssetSelector" class="btn btn-secondary" type="button">Cancel</button>
          <button @click.prevent="confirmAssetSelection" class="btn btn-primary" :disabled="!selectedAssetId" type="button">Select</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

export default {
  name: 'ImageEditor',

  props: {
    assetUuid: {
      type: String,
      default: null
    },
    fieldKey: {
      type: String,
      required: true
    },
    inputName: {
      type: String,
      default: null
    }
  },

  data() {
    return {
      originalAsset: null,
      editedVersions: [],
      activeTool: null,
      cropper: null,
      editParams: {
        crop: null,
        rotate: 0,
        flip: null
      },
      previewUrl: '',
      showAssetSelector: false,
      selectedAssetId: null,
      searchTerm: '',
      mimeFilter: '',
      filteredAssets: [],
      loading: false,
      page: 1,
      limit: 20,
      total: 0,
      searchTimeout: null,
      saveStatus: '',
      saveSuccess: false,
      hasChanges: false
    };
  },

  computed: {
    hasMoreAssets() {
      return this.filteredAssets.length < this.total;
    }
  },

  watch: {
    assetUuid: {
      immediate: true,
      handler(newVal) {
        if (newVal) {
          this.loadAsset(newVal);
        }
      }
    }
  },

  mounted() {
    if (this.assetUuid) {
      this.loadAsset(this.assetUuid);
    }
    
    // Prevent form submission when interacting with the component
    this.preventFormSubmission();
  },

  beforeUnmount() {
    this.destroyCropper();
    
    // Remove event listeners
    const form = this.findParentForm();
    if (form) {
      form.removeEventListener('submit', this.handleFormSubmit);
    }
  },

  methods: {
    // Add this new method to prevent form submission
    preventFormSubmission() {
      const form = this.findParentForm();
      if (form) {
        form.addEventListener('submit', this.handleFormSubmit);
      }
    },
    
    // Find the parent form element
    findParentForm() {
      let element = this.$el;
      while (element && element.tagName !== 'FORM') {
        element = element.parentElement;
      }
      return element;
    },
    
    // Handle form submission
    handleFormSubmit(event) {
      // Only prevent submission if it was triggered by a button inside our component
      const activeElement = document.activeElement;
      if (this.$el.contains(activeElement) && activeElement.tagName === 'BUTTON') {
        event.preventDefault();
      }
    },

    async loadAsset(uuid) {
      this.loading = true;
      try {
        const response = await fetch(`/crelish/asset/api-get?uuid=${uuid}`);
        if (response.ok) {
          const data = await response.json();
          this.originalAsset = data;
          this.previewUrl = data.full_url;
          
          // Load edited versions
          this.loadEditedVersions(uuid);
        } else {
          console.error('Failed to load asset:', response.statusText);
        }
      } catch (error) {
        console.error('Error loading asset:', error);
      } finally {
        this.loading = false;
      }
    },

    async loadEditedVersions(uuid) {
      try {
        const response = await fetch(`/crelish/asset/api-get-edited-versions?uuid=${uuid}`);
        if (response.ok) {
          const data = await response.json();
          if (data.success && data.versions) {
            this.editedVersions = data.versions;
          }
        }
      } catch (error) {
        console.error('Error loading edited versions:', error);
      }
    },

    activateTool(tool) {
      // If selecting the same tool, deactivate it
      if (this.activeTool === tool) {
        this.activeTool = null;
        this.destroyCropper();
        return;
      }

      // Destroy any existing cropper instance
      this.destroyCropper();
      
      this.activeTool = tool;
      
      // Initialize cropper if crop tool is selected
      if (tool === 'crop' && this.$refs.originalImage) {
        this.$nextTick(() => {
          this.initCropper();
        });
      } else {
        this.updatePreview();
      }
    },

    initCropper() {
      const image = this.$refs.originalImage;
      if (!image) return;

      this.cropper = new Cropper(image, {
        viewMode: 1,
        dragMode: 'crop',
        aspectRatio: NaN,
        autoCropArea: 1,
        cropBoxMovable: true,
        cropBoxResizable: true,
        guides: true,
        center: true,
        highlight: true,
        background: true,
        zoomable: false,
        ready: () => {
          // Cropper is ready
        },
        crop: (event) => {
          // Update crop data
          const { x, y, width, height } = event.detail;
          this.editParams.crop = {
            x: Math.round(x),
            y: Math.round(y),
            width: Math.round(width),
            height: Math.round(height)
          };
          this.hasChanges = true;
        }
      });
    },

    destroyCropper() {
      if (this.cropper) {
        this.cropper.destroy();
        this.cropper = null;
      }
    },

    applyCrop() {
      if (this.editParams.crop) {
        this.updatePreview();
        this.activeTool = null;
        this.destroyCropper();
      }
    },

    cancelCrop() {
      this.editParams.crop = null;
      this.activeTool = null;
      this.destroyCropper();
      this.updatePreview();
    },

    rotateLeft() {
      this.editParams.rotate -= 90;
      if (this.editParams.rotate < -180) {
        this.editParams.rotate += 360;
      }
      this.updatePreview();
    },

    rotateRight() {
      this.editParams.rotate += 90;
      if (this.editParams.rotate > 180) {
        this.editParams.rotate -= 360;
      }
      this.updatePreview();
    },

    flipHorizontal() {
      if (this.editParams.flip === 'h') {
        this.editParams.flip = null;
      } else if (this.editParams.flip === 'v') {
        this.editParams.flip = 'both';
      } else if (this.editParams.flip === 'both') {
        this.editParams.flip = 'v';
      } else {
        this.editParams.flip = 'h';
      }
      this.updatePreview();
    },

    flipVertical() {
      if (this.editParams.flip === 'v') {
        this.editParams.flip = null;
      } else if (this.editParams.flip === 'h') {
        this.editParams.flip = 'both';
      } else if (this.editParams.flip === 'both') {
        this.editParams.flip = 'h';
      } else {
        this.editParams.flip = 'v';
      }
      this.updatePreview();
    },

    resetEdits() {
      this.editParams = {
        crop: null,
        rotate: 0,
        flip: null
      };
      this.destroyCropper();
      this.activeTool = null;
      this.previewUrl = this.originalAsset ? this.originalAsset.full_url : '';
      this.hasChanges = false;
    },

    updatePreview() {
      if (!this.originalAsset) return;

      // Build the preview URL with edit parameters
      let url = `/crelish/asset/glide?path=${this.originalAsset.full_url.replace(/^\//, '')}`;
      
      // Add crop parameters
      if (this.editParams.crop) {
        url += `&crop=${this.editParams.crop.width},${this.editParams.crop.height},${this.editParams.crop.x},${this.editParams.crop.y}`;
      }
      
      // Add rotation
      if (this.editParams.rotate !== 0) {
        url += `&rot=${this.editParams.rotate}`;
      }
      
      // Add flip
      if (this.editParams.flip) {
        url += `&flip=${this.editParams.flip}`;
      }
      
      this.previewUrl = url;
      this.hasChanges = this.hasEditParams();
    },

    hasEditParams() {
      return (
        (this.editParams.crop !== null) || 
        (this.editParams.rotate !== 0) || 
        (this.editParams.flip !== null)
      );
    },

    async saveEditedImage() {
      if (!this.originalAsset || !this.hasChanges) return;

      this.saveStatus = 'Saving...';
      this.saveSuccess = false;

      try {
        const formData = new FormData();
        formData.append('original_uuid', this.originalAsset.uuid);
        formData.append('edit_params', JSON.stringify(this.editParams));
        formData.append('edit_type', this.getEditTypeName());

        const response = await fetch('/crelish/asset/api-save-edited-asset', {
          method: 'POST',
          body: formData
        });

        if (response.ok) {
          const data = await response.json();
          if (data.success) {
            this.saveStatus = 'Image saved successfully!';
            this.saveSuccess = true;
            
            // Add the new version to the list
            if (data.asset) {
              this.editedVersions.unshift(data.asset);
            }
            
            // Reset edit params
            this.resetEdits();
          } else {
            this.saveStatus = data.message || 'Failed to save image';
            this.saveSuccess = false;
          }
        } else {
          this.saveStatus = 'Failed to save image. Server error.';
          this.saveSuccess = false;
        }
      } catch (error) {
        console.error('Error saving edited image:', error);
        this.saveStatus = 'Error saving image. Please try again.';
        this.saveSuccess = false;
      }
    },

    getEditTypeName() {
      const types = [];
      if (this.editParams.crop) types.push('crop');
      if (this.editParams.rotate !== 0) types.push('rotate');
      if (this.editParams.flip) types.push('flip');
      
      return types.length > 0 ? types.join('_') : 'edit';
    },

    selectVersion(version) {
      // Set this version as the selected one
      if (this.inputName) {
        const hiddenInput = document.getElementById(`${this.inputName}`);
        if (hiddenInput) {
          hiddenInput.value = version.uuid;
        }
      }
      
      // Dispatch a custom event
      this.$el.dispatchEvent(new CustomEvent('version-selected', {
        detail: { uuid: version.uuid }
      }));
    },

    openAssetSelector() {
      this.showAssetSelector = true;
      this.selectedAssetId = this.assetUuid;
      this.searchTerm = '';
      this.mimeFilter = '';
      this.page = 1;
      this.searchAssets();
    },

    closeAssetSelector() {
      this.showAssetSelector = false;
    },

    selectAsset(uuid) {
      this.selectedAssetId = uuid;
    },

    confirmAssetSelection() {
      if (this.selectedAssetId) {
        this.loadAsset(this.selectedAssetId);
        
        // Update hidden input if it exists
        if (this.inputName) {
          const hiddenInput = document.getElementById(`${this.inputName}`);
          if (hiddenInput) {
            hiddenInput.value = this.selectedAssetId;
          }
        }
        
        // Dispatch a custom event
        this.$el.dispatchEvent(new CustomEvent('asset-selected', {
          detail: { uuid: this.selectedAssetId }
        }));
      }
      this.showAssetSelector = false;
    },

    debounceSearch() {
      if (this.searchTimeout) {
        clearTimeout(this.searchTimeout);
      }
      
      this.searchTimeout = setTimeout(() => {
        this.page = 1;
        this.searchAssets();
      }, 300);
    },

    async searchAssets() {
      this.loading = true;
      this.filteredAssets = [];

      try {
        const params = new URLSearchParams();
        if (this.searchTerm) {
          params.append('q', this.searchTerm);
        }
        
        // Only search for image types
        params.append('mime', this.mimeFilter || 'image/');
        
        params.append('page', this.page);
        params.append('limit', this.limit);

        const response = await fetch(`/crelish/asset/api-search?${params.toString()}`);

        if (response.ok) {
          const data = await response.json();
          this.filteredAssets = data.items;
          this.total = data.total;
        } else {
          console.error('Failed to fetch assets:', response.statusText);
        }
      } catch (error) {
        console.error('Error searching assets:', error);
      } finally {
        this.loading = false;
      }
    },

    async loadMoreAssets() {
      this.page++;
      this.loading = true;

      try {
        const params = new URLSearchParams();
        if (this.searchTerm) {
          params.append('q', this.searchTerm);
        }
        
        // Only search for image types
        params.append('mime', this.mimeFilter || 'image/');
        
        params.append('page', this.page);
        params.append('limit', this.limit);

        const response = await fetch(`/crelish/asset/api-search?${params.toString()}`);

        if (response.ok) {
          const data = await response.json();
          this.filteredAssets = [...this.filteredAssets, ...data.items];
          this.total = data.total;
        } else {
          console.error('Failed to load more assets:', response.statusText);
        }
      } catch (error) {
        console.error('Error loading more assets:', error);
      } finally {
        this.loading = false;
      }
    }
  }
};
</script>

<style scoped>
.image-editor {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.original-image-container {
  border: 1px solid #ddd;
  padding: 15px;
  border-radius: 4px;
}

.image-preview {
  max-width: 100%;
  overflow: hidden;
  margin-bottom: 10px;
}

.original-image {
  max-width: 100%;
  height: auto;
}

.editor-controls {
  display: flex;
  flex-direction: column;
  gap: 15px;
}

.control-section {
  border: 1px solid #ddd;
  padding: 15px;
  border-radius: 4px;
}

.tool-buttons {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.tool-controls {
  border: 1px solid #ddd;
  padding: 15px;
  border-radius: 4px;
}

.crop-controls, .rotate-controls, .flip-controls {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.rotate-slider {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.rotate-buttons, .crop-actions {
  display: flex;
  gap: 10px;
}

.preview-section {
  border: 1px solid #ddd;
  padding: 15px;
  border-radius: 4px;
}

.preview-container {
  max-width: 100%;
  overflow: hidden;
}

.preview-image {
  max-width: 100%;
  height: auto;
}

.save-controls {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.save-status {
  padding: 10px;
  border-radius: 4px;
}

.save-success {
  background-color: #d4edda;
  color: #155724;
}

.save-error {
  background-color: #f8d7da;
  color: #721c24;
}

.edited-versions {
  border: 1px solid #ddd;
  padding: 15px;
  border-radius: 4px;
}

.versions-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.version-item {
  border: 1px solid #ddd;
  border-radius: 4px;
  padding: 5px;
  cursor: pointer;
  width: 150px;
  transition: all 0.2s;
}

.version-item:hover {
  border-color: #007bff;
  background-color: #f8f9fa;
}

.version-thumbnail {
  width: 100%;
  height: 100px;
  object-fit: contain;
}

.version-info {
  padding: 5px;
}

.version-title {
  font-size: 0.9rem;
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.version-type {
  font-size: 0.8rem;
  color: #6c757d;
  margin: 0;
}

/* Asset Selector Modal */
.asset-selector-modal {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 1050;
  display: flex;
  align-items: center;
  justify-content: center;
}

.modal-backdrop {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  z-index: -1;
}

.modal-container {
  background-color: white;
  border-radius: 4px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
  width: 90%;
  max-width: 800px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 15px;
  border-bottom: 1px solid #ddd;
}

.modal-body {
  padding: 15px;
  overflow-y: auto;
  flex-grow: 1;
}

.modal-footer {
  padding: 15px;
  border-top: 1px solid #ddd;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.search-filter-container {
  display: flex;
  gap: 10px;
  margin-bottom: 15px;
}

.search-box {
  flex-grow: 1;
}

.filter-box {
  width: 200px;
}

.image-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 15px;
}

.image-item {
  border: 1px solid #ddd;
  border-radius: 4px;
  padding: 5px;
  cursor: pointer;
  transition: all 0.2s;
}

.image-item:hover {
  border-color: #007bff;
  background-color: #f8f9fa;
}

.image-item.selected {
  border-color: #007bff;
  background-color: #e7f5ff;
}

.image-item img {
  width: 100%;
  height: 120px;
  object-fit: contain;
}

.image-title {
  margin-top: 5px;
  font-size: 0.9rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.loading-indicator {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 30px;
}

.spinner {
  border: 4px solid #f3f3f3;
  border-top: 4px solid #007bff;
  border-radius: 50%;
  width: 30px;
  height: 30px;
  animation: spin 1s linear infinite;
  margin-bottom: 10px;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.no-results {
  text-align: center;
  padding: 20px;
  color: #6c757d;
}

.load-more {
  text-align: center;
  margin-top: 15px;
}
</style> 