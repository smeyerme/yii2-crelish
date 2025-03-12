<!-- components/PageBuilder.vue -->
<template>
  <div class="page-builder">
    <!-- Editor Overlay -->
    <editor-overlay
        :is-visible="editorOverlayVisible"
        :element-id="editorElementId"
        :content-type="editorContentType"
        :title="editorTitle"
        @close="closeEditorOverlay"
        @saved="handleContentSaved"
    ></editor-overlay>

    <!-- Content Selection Modal -->
    <div class="content-selection-modal" v-if="showContentSelector">
      <div class="modal-backdrop" @click="closeContentSelector"></div>
      <div class="modal-container">
        <div class="modal-header">
          <h3>Select Content</h3>
          <button type="button" @click="closeContentSelector" class="btn-close">&times;</button>
        </div>
        <div class="modal-body">
          <iframe
              ref="contentSelectorFrame"
              :src="contentSelectorUrl"
              class="content-selector-iframe"
              @load="handleIframeLoad"
          ></iframe>
        </div>
      </div>
    </div>

    <!-- Confirmation dialogs -->
    <dialog ref="deleteDialog" class="custom-dialog">
      <div class="dialog-content">
        <h3>Delete {{ deletingRow ? 'Row' : 'Column' }}</h3>
        <p>Are you sure you want to delete this {{ deletingRow ? 'row' : 'column' }}?</p>
        <div class="dialog-actions">
          <button type="button" @click="confirmDelete" class="btn btn-danger">Delete</button>
          <button type="button" @click="cancelDelete" class="btn btn-outline-secondary">Cancel</button>
        </div>
      </div>
    </dialog>

    <!-- Main Builder Interface -->
    <div class="builder-container">
      <div class="builder-toolbar">
        <button type="button" @click="addRow" class="btn btn-primary">
          <span class="btn-icon">+</span> Add Row
        </button>
      </div>

      <!-- Page Structure - Rows and Columns -->
      <div class="page-structure">
        <div v-if="pageStructure.length === 0" class="empty-structure">
          <p>Click "Add Row" to start building your page.</p>
        </div>

        <div v-for="(row, rowIndex) in pageStructure" :key="row.id" class="page-row">
          <div class="row-header">
            <div class="row-title">Row {{ rowIndex + 1 }}</div>
            <div class="row-actions">
              <button type="button" @click="addColumn(rowIndex)" class="btn btn-sm btn-outline-primary">
                Add Column
              </button>
              <button type="button" @click="startDeleteRow(rowIndex)" class="btn btn-sm btn-outline-danger">
                Delete Row
              </button>
            </div>
          </div>

          <div class="row-columns">
            <div v-if="row.columns.length === 0" class="empty-row">
              <p>Click "Add Column" to add columns to this row.</p>
            </div>

            <div v-for="(column, colIndex) in row.columns" :key="column.id" class="row-column">
              <div class="column-header">
                <input
                    type="text"
                    :value="column.areaKey"
                    class="area-key-input"
                    @input="debouncedAreaKeyChange(rowIndex, colIndex, $event)"
                    placeholder="Area name"
                />
                <button type="button" @click="startDeleteColumn(rowIndex, colIndex)" class="btn btn-sm btn-outline-danger">
                  &times;
                </button>
              </div>

              <div class="column-content">
                <div class="content-header">
                  <h4>{{ column.areaKey }} Content</h4>
                  <button type="button" @click="openContentSelector(column.areaKey)" class="btn btn-sm btn-primary">
                    Add Content
                  </button>
                </div>

                <!-- Removed the empty-content div as we now handle it inside the draggable component -->

                <!-- Always show draggable regardless of content -->
                <draggable
                    v-model="contentData[column.areaKey]"
                    class="content-items"
                    :data-area-key="column.areaKey"
                    :group="globalSortableGroup"
                    handle=".drag-handle"
                    :animation="150"
                    @start="dragStart"
                    @end="dragEnd"
                    item-key="uuid"
                >
                  <template #item="{element}">
                    <content-item
                        :key="element.uuid"
                        :uuid="element.uuid"
                        :content-type="element.ctype"
                        :area-key="column.areaKey"
                        @edit="editContent"
                        @remove="removeContent(column.areaKey, element.uuid)"
                        @data-loaded="handleContentDataLoaded(column.areaKey, element.uuid, $event)"
                    />
                  </template>
                  <!-- Show empty message when no items -->
                  <template #header v-if="getContentItems(column.areaKey).length === 0">
                    <div class="empty-content-draggable">
                      <p>Drag items here</p>
                    </div>
                  </template>
                </draggable>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import {nanoid} from 'nanoid';
import draggable from 'vuedraggable';
import EditorOverlay from './common/EditorOverlay.vue';
import ContentItem from './common/ContentItem.vue';

export default {
  name: 'PageBuilder',

  components: {
    EditorOverlay,
    ContentItem,
    draggable
  },

  props: {
    initialPageData: {
      type: Object,
      default: null
    },
    pageId: {
      type: String,
      default: null
    }
  },

  data() {
    // Initialize pageStructure and contentData outside of Vue's reactivity
    let initialPageStructure = [];
    let initialContentData = {};

    // Process initial data if available
    if (this.initialPageData) {
      try {
        // Extract content data (excluding _layout)
        for (const key in this.initialPageData) {
          if (key !== '_layout') {
            initialContentData[key] = Array.isArray(this.initialPageData[key])
                ? this.initialPageData[key]
                : [];
          }
        }

        // If there's a layout, parse it
        if (this.initialPageData._layout) {
          const layout = typeof this.initialPageData._layout === 'string'
              ? JSON.parse(this.initialPageData._layout)
              : this.initialPageData._layout;

          // Create rows and columns from layout
          layout.forEach(rowAreas => {
            const row = {
              id: nanoid(),
              columns: []
            };

            rowAreas.forEach(areaKey => {
              row.columns.push({
                id: nanoid(),
                areaKey
              });

              // Ensure this area exists in contentData
              if (!initialContentData[areaKey]) {
                initialContentData[areaKey] = [];
              }
            });

            initialPageStructure.push(row);
          });
        } else {
          // No layout defined, create a default row with one column
          const newRow = {
            id: nanoid(),
            columns: []
          };

          const newColumn = {
            id: nanoid(),
            areaKey: `area_${Date.now()}_${Math.floor(Math.random() * 1000)}`
          };

          initialContentData[newColumn.areaKey] = [];
          newRow.columns.push(newColumn);
          initialPageStructure.push(newRow);
        }
      } catch (error) {
        console.error('Failed to parse initial data:', error);
        // Create a default row with one column as fallback
        const newRow = {
          id: nanoid(),
          columns: []
        };

        const newColumn = {
          id: nanoid(),
          areaKey: `area_${Date.now()}_${Math.floor(Math.random() * 1000)}`
        };

        initialContentData[newColumn.areaKey] = [];
        newRow.columns.push(newColumn);
        initialPageStructure.push(newRow);
      }
    } else {
      // No initial data, create a default row with column
      const newRow = {
        id: nanoid(),
        columns: []
      };

      const newColumn = {
        id: nanoid(),
        areaKey: `area_${Date.now()}_${Math.floor(Math.random() * 1000)}`
      };

      initialContentData[newColumn.areaKey] = [];
      newRow.columns.push(newColumn);
      initialPageStructure.push(newRow);
    }

    return {
      pageStructure: initialPageStructure,
      contentData: initialContentData,

      // For confirmation dialog
      pendingDeleteRow: null,
      pendingDeleteColumn: null,
      deletingRow: true,

      // For editor overlay
      editorOverlayVisible: false,
      editorElementId: null,
      editorContentType: null,
      editorTitle: 'Edit Content',

      // For content selector
      showContentSelector: false,
      isSelectorLoaded: false,
      targetAreaKey: null,

      // For autosave
      autosaveInterval: null,
      updateTimeout: null,

      structureBackup: [],
      areaKeyTimeout: null,

      // For drag and drop
      isDragging: false,
      globalSortableGroup: 'page-builder-content'
    };
  },

  computed: {
    // URL for content selector modal
    contentSelectorUrl() {
      return `/crelish/content/selector?overlay=1&target=${this.targetAreaKey || ''}`;
    },

    // Generates the layout array format needed by MatrixConnectorContentProcessor
    layoutArray() {
      return this.pageStructure.map(row =>
          row.columns.map(column => column.areaKey)
      );
    }
  },

  watch: {
    // Update JSON when structure changes, with debounce and drag check
    pageStructure: {
      handler() {
        // Skip updates during active drag operations
        if (!this.isDragging) {
          this.updateHiddenInputDebounced();
        }
      },
      deep: true
    },

    // Update JSON when content changes, with debounce and drag check
    contentData: {
      handler() {
        // Skip updates during active drag operations
        if (!this.isDragging) {
          this.updateHiddenInputDebounced();
        }
      },
      deep: true
    }
  },

  mounted() {
    // Set up autosave interval if needed
    /*
    this.autosaveInterval = setInterval(() => {
      this.updateHiddenInput();
    }, 10000);
    */

    // Set up event listener for content selection messages
    window.addEventListener('message', this.handleContentSelectionMessage);

    this.backupStructure();

    console.log('PageBuilder mounted, initialized with structure:', this.pageStructure);
    console.log('Initial content data:', this.contentData);
  },

  beforeUnmount() {
    // Clean up
    if (this.autosaveInterval) {
      clearInterval(this.autosaveInterval);
    }

    // Remove event listener
    window.removeEventListener('message', this.handleContentSelectionMessage);
  },

  methods: {
    // $set and $delete methods for Vue 3 reactivity
    $set(obj, key, value) {
      obj[key] = value;
      return value;
    },

    $delete(obj, key) {
      if (key in obj) {
        delete obj[key];
      }
    },

    // Debounced update to avoid recursive updates
    updateHiddenInputDebounced() {
      if (this.updateTimeout) {
        clearTimeout(this.updateTimeout);
      }
      this.updateTimeout = setTimeout(() => {
        this.updateHiddenInput();
      }, 300);
    },

    // Drag event handlers
    dragStart() {
      this.isDragging = true;
      console.log('Drag started, pausing reactivity updates');
    },

    dragEnd() {
      // Use nextTick to ensure Vue has finished its DOM updates
      this.$nextTick(() => {
        this.isDragging = false;
        console.log('Drag ended, resuming reactivity updates');
        this.updateHiddenInputDebounced();
      });
    },

    // Row and column management
    addRow() {
      const newRow = {
        id: nanoid(),
        columns: []
      };

      // Add a default column to the new row
      this.addColumnToRow(newRow);

      // Add the row to the structure
      this.pageStructure.push(newRow);

      this.backupStructure();
    },

    addColumnToRow(row) {
      const newColumn = {
        id: nanoid(),
        areaKey: `area_${Date.now()}_${Math.floor(Math.random() * 1000)}`
      };

      // Initialize content array for this area
      this.contentData[newColumn.areaKey] = [];

      // Add the column to the row
      row.columns.push(newColumn);
    },

    addColumn(rowIndex) {
      if (rowIndex >= 0 && rowIndex < this.pageStructure.length) {
        this.addColumnToRow(this.pageStructure[rowIndex]);

        this.backupStructure();
      }
    },

    startDeleteRow(rowIndex) {
      this.pendingDeleteRow = rowIndex;
      this.pendingDeleteColumn = null;
      this.deletingRow = true;
      this.$refs.deleteDialog.showModal();
    },

    startDeleteColumn(rowIndex, colIndex) {
      this.pendingDeleteRow = rowIndex;
      this.pendingDeleteColumn = colIndex;
      this.deletingRow = false;
      this.$refs.deleteDialog.showModal();
    },

    confirmDelete() {
      if (this.deletingRow && this.pendingDeleteRow !== null) {
        this.deleteRow(this.pendingDeleteRow);
      } else if (!this.deletingRow && this.pendingDeleteRow !== null && this.pendingDeleteColumn !== null) {
        this.deleteColumn(this.pendingDeleteRow, this.pendingDeleteColumn);
      }
      this.$refs.deleteDialog.close();
    },

    cancelDelete() {
      this.pendingDeleteRow = null;
      this.pendingDeleteColumn = null;
      this.$refs.deleteDialog.close();
    },

    deleteRow(rowIndex) {
      if (rowIndex >= 0 && rowIndex < this.pageStructure.length) {
        const row = this.pageStructure[rowIndex];

        // Remove content data for all columns in this row
        row.columns.forEach(column => {
          delete this.contentData[column.areaKey];
        });

        // Remove the row
        this.pageStructure.splice(rowIndex, 1);

        // If no rows left, add a default one
        if (this.pageStructure.length === 0) {
          this.addRow();
        }
      }
    },

    deleteColumn(rowIndex, colIndex) {
      if (rowIndex >= 0 && rowIndex < this.pageStructure.length) {
        const row = this.pageStructure[rowIndex];

        if (colIndex >= 0 && colIndex < row.columns.length) {
          const column = row.columns[colIndex];

          // Check if this is the last column in the row
          if (row.columns.length === 1) {
            // Delete the entire row instead
            this.deleteRow(rowIndex);
            return;
          }

          // Remove content data for this column
          delete this.contentData[column.areaKey];

          // Remove the column
          row.columns.splice(colIndex, 1);
        }
      }
    },

    backupStructure() {
      console.log('Backing up structure');
      // Create a deep copy of just the structure without extra properties
      this.structureBackup = this.pageStructure.map(row => ({
        id: row.id,
        columns: row.columns.map(col => ({
          id: col.id,
          areaKey: col.areaKey
        }))
      }));
      console.log('Structure backed up:', this.structureBackup);
    },

    // Call this whenever you need to find the original area key for a column
    findOriginalAreaKey(rowIndex, colIndex) {
      // Make sure backup exists and indices are valid
      if (!this.structureBackup ||
          rowIndex < 0 || rowIndex >= this.structureBackup.length ||
          colIndex < 0 || colIndex >= this.structureBackup[rowIndex].columns.length) {
        return null;
      }

      // Get the column ID we're looking for
      const columnId = this.pageStructure[rowIndex].columns[colIndex].id;

      // Find the matching column in the backup
      const backupRow = this.structureBackup[rowIndex];
      const backupColumn = backupRow.columns[colIndex];

      // Verify we're looking at the same column by ID
      if (backupColumn.id === columnId) {
        return backupColumn.areaKey;
      }

      // If the structure has changed and IDs don't match, search by ID
      for (const row of this.structureBackup) {
        for (const col of row.columns) {
          if (col.id === columnId) {
            return col.areaKey;
          }
        }
      }

      return null;
    },

    updateAreaKey(rowIndex, colIndex, event) {
      console.log('updateAreaKey called', { rowIndex, colIndex });

      if (rowIndex >= 0 && rowIndex < this.pageStructure.length) {
        const row = this.pageStructure[rowIndex];

        if (colIndex >= 0 && colIndex < row.columns.length) {
          const column = row.columns[colIndex];
          const newAreaKey = event.target.value.trim();

          // Get the original area key from our backup
          const oldAreaKey = this.findOriginalAreaKey(rowIndex, colIndex) || column.areaKey;

          console.log(`Area key change: ${oldAreaKey} -> ${newAreaKey}`);

          // Basic validation
          if (!newAreaKey) {
            event.target.value = oldAreaKey;
            return;
          }

          // Skip if no actual change
          if (newAreaKey === oldAreaKey) {
            return;
          }

          // Check uniqueness
          const isUnique = !this.pageStructure.some(r =>
              r.columns.some(c => c.areaKey === newAreaKey && c !== column)
          );

          if (!isUnique) {
            alert('Area key must be unique!');
            event.target.value = oldAreaKey;
            column.areaKey = oldAreaKey; // Reset the v-model value
            return;
          }

          // Now handle the content transfer - this is the key part
          if (this.contentData[oldAreaKey]) {
            console.log(`Found content for ${oldAreaKey}, transferring to ${newAreaKey}`);

            // Create the new content area
            this.contentData[newAreaKey] = this.contentData[oldAreaKey]
                ? [...this.contentData[oldAreaKey]]
                : [];

            // Clear the old content area
            this.contentData[oldAreaKey] = [];

            // Update the column's area key in the current structure
            column.areaKey = newAreaKey;

            // Update our backup to match the new reality
            setTimeout(() => {
              this.backupStructure();

              // Clean up the empty array
              if (this.contentData[oldAreaKey] && this.contentData[oldAreaKey].length === 0) {
                delete this.contentData[oldAreaKey];
              }

              console.log('Update completed, structure backed up');
            }, 100);
          } else {
            // If there's no content, just update the key
            console.log(`No content found for ${oldAreaKey}`);
            column.areaKey = newAreaKey;
            this.contentData[newAreaKey] = [];

            // Update our backup
            setTimeout(() => {
              this.backupStructure();
            }, 100);
          }
        }
      }
    },

    // Content management
    getContentItems(areaKey) {
      return this.contentData[areaKey] || [];
    },

    // Update content item data when it's loaded by the component
    handleContentDataLoaded(areaKey, uuid, data) {
      // Disabled as this can cause reactivity issues
      /*
      if (this.contentData[areaKey]) {
        const index = this.contentData[areaKey].findIndex(item => item.uuid === uuid);
        if (index !== -1) {
          // Create a new object to avoid mutation of the original
          const updatedItem = { ...data };

          // Use direct array replacement instead of splice
          const newAreaContent = [...this.contentData[areaKey]];
          newAreaContent[index] = updatedItem;

          // Replace entire array to avoid reactivity issues
          this.contentData[areaKey] = newAreaContent;
        }
      }
      */
    },

    removeContent(areaKey, uuid) {
      if (this.contentData[areaKey]) {
        const index = this.contentData[areaKey].findIndex(item => item.uuid === uuid);
        if (index !== -1) {
          this.contentData[areaKey].splice(index, 1);
        }
      }
    },

    // Editor overlay
    editContent(item) {
      if (!item || !item.uuid || !item.ctype) return;

      this.editorElementId = item.uuid;
      this.editorContentType = item.ctype;
      this.editorTitle = `Edit ${item.ctype}`;
      this.editorOverlayVisible = true;
    },

    closeEditorOverlay() {
      this.editorOverlayVisible = false;
    },

    debouncedAreaKeyChange(rowIndex, colIndex, event) {
      // Get the current value from the input
      const newValue = event.target.value.trim();

      // Store the row and column for later reference
      const changeData = {
        rowIndex,
        colIndex,
        newValue
      };

      // If we already have a timeout pending, clear it
      if (this.areaKeyTimeout) {
        clearTimeout(this.areaKeyTimeout);
      }

      // Set a new timeout to actually process the change after typing stops
      this.areaKeyTimeout = setTimeout(() => {
        this.processAreaKeyChange(changeData.rowIndex, changeData.colIndex, changeData.newValue);
      }, 500);

      // For visual feedback, we can update the displayed value
      // But this won't affect the underlying data until the timeout completes
      event.target.value = newValue;
    },

    // Process the area key change once typing has stopped
    processAreaKeyChange(rowIndex, colIndex, newValue) {
      if (rowIndex >= 0 && rowIndex < this.pageStructure.length) {
        const row = this.pageStructure[rowIndex];

        if (colIndex >= 0 && colIndex < row.columns.length) {
          const column = row.columns[colIndex];
          const oldAreaKey = column.areaKey;
          const newAreaKey = newValue;

          console.log(`Processing area key change: ${oldAreaKey} -> ${newAreaKey}`);

          // Basic validation
          if (!newAreaKey) {
            return; // Keep the old value
          }

          // Skip if no actual change
          if (newAreaKey === oldAreaKey) {
            return;
          }

          // Check uniqueness
          const isUnique = !this.pageStructure.some(r =>
              r.columns.some(c => c.areaKey === newAreaKey && c !== column)
          );

          if (!isUnique) {
            alert('Area key must be unique!');
            // Reset the input field to the old value
            const inputField = this.$el.querySelector(`.row-column:nth-child(${colIndex + 1}) .area-key-input`);
            if (inputField) {
              inputField.value = oldAreaKey;
            }
            return;
          }

          // Now handle the content transfer
          if (this.contentData[oldAreaKey]) {
            console.log(`Found content for ${oldAreaKey}, transferring to ${newAreaKey}`);

            // Create the new content area with the content from the old area
            this.contentData[newAreaKey] = [...(this.contentData[oldAreaKey] || [])];

            // Clear the old content area
            this.contentData[oldAreaKey] = [];

            // Clean up the empty array after a short delay
            setTimeout(() => {
              if (this.contentData[oldAreaKey] && this.contentData[oldAreaKey].length === 0) {
                delete this.contentData[oldAreaKey];
              }
            }, 100);
          } else {
            // If there's no content, just create an empty array
            console.log(`No content found for ${oldAreaKey}`);
            this.contentData[newAreaKey] = [];
          }

          // Only update the actual data model after processing is complete
          column.areaKey = newAreaKey;
        }
      }
    },

    async handleContentSaved(data) {
      console.log('Content saved:', data);

      // Find the content item in all areas and refresh it
      if (data.elementId) {
        let updated = false;

        // Only refresh one instance of the content item
        for (const areaKey in this.contentData) {
          const index = this.contentData[areaKey].findIndex(item => item.uuid === data.elementId);

          if (index !== -1) {
            try {
              console.log(`Found content to update in ${areaKey} at index ${index}`);

              // Get the element by UUID - just find the first one
              const contentElement = document.querySelector(`.content-item[data-uuid="${data.elementId}"]`);

              if (contentElement) {
                console.log(`Found DOM element with UUID ${data.elementId}`);

                // Dispatch a single refresh event
                const refreshEvent = new CustomEvent('content-refresh');
                contentElement.dispatchEvent(refreshEvent);

                console.log('Dispatched content-refresh event');
                updated = true;

                // Stop after the first element is refreshed
                break;
              }
            } catch (error) {
              console.error('Error updating content item:', error);
            }
          }
        }

        if (!updated) {
          console.warn('Content was saved but not found in current layout');
        }
      }
    },

    // Content selector
    openContentSelector(areaKey) {
      if (!areaKey) return;

      this.targetAreaKey = areaKey;
      this.showContentSelector = true;
    },

    closeContentSelector() {
      this.showContentSelector = false;
      this.targetAreaKey = null;
    },

    handleIframeLoad(event) {
      // Access the iframe's window to inject communication script
      try {
        const iframe = event.target;

        // Fix iframe interaction issues
        iframe.style.pointerEvents = 'auto';
        iframe.style.cursor = 'auto';

        // Inject script to communicate back to parent
        const script = iframe.contentDocument.createElement('script');
        script.textContent = `
          // Ensure iframe content is interactive
          document.body.style.pointerEvents = 'auto';
          document.documentElement.style.pointerEvents = 'auto';

          // Find all add content buttons and attach click handlers
          document.addEventListener('click', function(event) {
            if (event.target && event.target.classList.contains('cntAdd')) {
              event.preventDefault();
              const contentData = event.target.dataset.content;

              // Send message to parent window
              window.parent.postMessage({
                action: 'contentSelected',
                content: contentData,
                targetArea: '${this.targetAreaKey}'
              }, '*');
            }
          });

          // Style adjustments for overlay mode
          const style = document.createElement('style');
          style.textContent = \`
            header, footer, .sidebar { display: none !important; }
            .content-wrapper { margin: 0 !important; padding: 0 !important; }
            body { background: white !important; }

            /* Fix interaction issues */
            html, body, a, button, input, select, textarea {
              pointer-events: auto !important;
              cursor: auto !important;
            }
          \`;
          document.head.appendChild(style);
        `;

        iframe.contentDocument.body.appendChild(script);
      } catch (error) {
        console.warn('Could not access iframe content:', error);
      }
    },

    handleContentSelectionMessage(event) {
      // Process messages from content selector iframe
      if (event.data && event.data.action === 'contentSelected') {
        try {
          // Parse the content data
          let contentDataString = event.data.content;
          if (typeof contentDataString !== 'string') {
            contentDataString = JSON.stringify(contentDataString);
          }

          // Extract the UUID and content type
          let contentData;
          try {
            contentData = JSON.parse(contentDataString);
          } catch (parseError) {
            console.error('Error parsing content data:', parseError);
            return;
          }

          // Add the content to the target area
          const targetArea = event.data.targetArea || this.targetAreaKey;

          if (targetArea && contentData && contentData.uuid && contentData.ctype) {
            // Ensure the area exists
            if (!this.contentData[targetArea]) {
              this.contentData[targetArea] = [];
            }

            // Add basic reference - the ContentItem component will fetch the full data
            const contentReference = {
              uuid: contentData.uuid,
              ctype: contentData.ctype
            };

            // Add the content by creating a new array to ensure reactivity
            this.contentData[targetArea] = [...this.contentData[targetArea], contentReference];

            // Close the selector
            this.closeContentSelector();
          } else {
            console.error('Invalid content data received:', contentData);
          }
        } catch (error) {
          console.error('Error processing selected content:', error);
          this.closeContentSelector();
        }
      }
    },

    // Save functionality
    createFinalJson() {
      // Create the JSON structure expected by MatrixConnectorContentProcessor
      return {
        // Add all content areas
        ...this.contentData,

        // Add layout information
        _layout: JSON.stringify(this.layoutArray)
      };
    },

    updateHiddenInput() {
      const jsonData = this.createFinalJson();

      // Find the hidden input field
      const jsonInput = document.getElementById('page-json-input');
      if (jsonInput) {
        // Update the value
        jsonInput.value = JSON.stringify(jsonData);
        console.log('Updated hidden input with page data');
      } else {
        console.warn('Could not find hidden input field with id "page-json-input"');
      }
    },

    // We're relying on the parent form's submit functionality
    // This method is left for potential programmatic submission if needed
    savePage() {
      // Update the hidden input
      this.updateHiddenInput();
    }
  }
};
</script>

<style>
.page-builder {
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Ubuntu, "Helvetica Neue", sans-serif;
  max-width: 1200px;
  margin: 0 auto;
}

.builder-container {
  display: flex;
  flex-direction: column;
  min-height: 90vh;
}

.builder-toolbar {
  margin-bottom: 1rem;
  padding: 0 1rem;
}

.page-structure {
  padding: 0 1rem;
}

.empty-structure {
  padding: 2rem;
  text-align: center;
  background-color: #f8f9fa;
  border-radius: 4px;
  border: 1px dashed #ddd;
  margin-bottom: 1rem;
}

.page-row {
  margin-bottom: 1.5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  overflow: hidden;
  background-color: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.row-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 1rem;
  background-color: #f8f9fa;
  border-bottom: 1px solid #ddd;
}

.row-title {
  font-weight: 600;
}

.row-actions {
  display: flex;
  gap: 0.5rem;
}

.row-columns {
  padding: 1rem;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 1rem;
}

.empty-row {
  width: 100%;
  padding: 2rem;
  text-align: center;
  background-color: #f8f9fa;
  border-radius: 4px;
  border: 1px dashed #ddd;
}

.row-column {
  border: 1px solid #ddd;
  border-radius: 4px;
  overflow: hidden;
  background-color: #f8f9fa;
}

.column-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.5rem;
  background-color: #e9ecef;
  border-bottom: 1px solid #ddd;
}

.area-key-input {
  flex: 1;
  border: 1px solid #ddd;
  border-radius: 3px;
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
  margin-right: 0.5rem;
}

.column-content {
  padding: 0.5rem;
  background-color: #fff;
}

.content-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.5rem;
  border-bottom: 1px solid #eee;
  margin-bottom: 0.75rem;
}

.content-header h4 {
  margin: 0;
  font-size: 1rem;
}

.empty-content-draggable {
  padding: 1.5rem;
  text-align: center;
  color: #6c757d;
  font-style: italic;
  border: 1px dashed #ddd;
  border-radius: 4px;
  background-color: #f9f9f9;
  min-height: 80px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 8px;
}

.content-items {
  min-height: 50px;
}

/* Content selection modal */
.content-selection-modal {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
}

.modal-backdrop {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
}

.modal-container {
  position: relative;
  width: 90%;
  max-width: 1200px;
  height: 80vh;
  background-color: #fff;
  border-radius: 6px;
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  z-index: 9999;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  background-color: #f8f9fa;
  border-bottom: 1px solid #ddd;
}

.modal-header h3 {
  margin: 0;
}

.btn-close {
  background: none;
  border: none;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
  padding: 0;
  color: #6c757d;
}

.modal-body {
  flex: 1;
  overflow: hidden;
}

.content-selector-iframe {
  width: 100%;
  height: 100%;
  border: none;
  pointer-events: auto !important;
  cursor: auto !important;
}

/* Dialog styles */
.custom-dialog {
  padding: 0;
  border: none;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  max-width: 400px;
}

.custom-dialog::backdrop {
  background-color: rgba(0, 0, 0, 0.4);
}

.dialog-content {
  padding: 1.5rem;
}

.dialog-content h3 {
  margin-top: 0;
  margin-bottom: 1rem;
}

.dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 1.5rem;
}

/* Button styles */
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
  transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out;
  cursor: pointer;
}

.btn-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
  border-radius: 0.2rem;
}

.btn-primary {
  color: #fff;
  background-color: #007bff;
  border-color: #007bff;
}

.btn-success {
  color: #fff;
  background-color: #28a745;
  border-color: #28a745;
}

.btn-danger {
  color: #fff;
  background-color: #dc3545;
  border-color: #dc3545;
}

.btn-outline-primary {
  color: #007bff;
  background-color: transparent;
  border-color: #007bff;
}

.btn-outline-secondary {
  color: #6c757d;
  background-color: transparent;
  border-color: #6c757d;
}

.btn-outline-danger {
  color: #dc3545;
  background-color: transparent;
  border-color: #dc3545;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .row-header, .column-header, .content-item-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .row-actions, .content-actions {
    margin-top: 0.5rem;
    width: 100%;
    justify-content: flex-end;
  }

  .area-key-input {
    width: 100%;
    margin-bottom: 0.5rem;
  }

  .row-columns {
    flex-direction: column;
  }

  .row-column {
    width: 100%;
    max-width: none;
  }
}
</style>