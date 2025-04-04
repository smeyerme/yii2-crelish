<template>
  <div class="relation-selector" :class="{'required': required}">
    <div v-if="label" class="relation-selector-label form-label has-star">
      {{ label }}
    </div>

    <!-- Single relation mode -->
    <div v-if="!isMultiple" class="single-relation-mode">
      <select ref="singleSelectElement" class="form-control relation-select" placeholder="Bitte wählen...">
        <option value="">{{ translate('choosePlaceholder') }}</option>
      </select>
    </div>

    <!-- Multiple relation mode -->
    <div v-else>
      <!-- Item selection interface -->
      <div class="selection-interface mb-3">
        <div class="row">
          <div class="col-10">
            <select ref="selectElement" class="form-control relation-select" placeholder="Bitte wählen...">
              <option value="">{{ translate('choosePlaceholder') }}</option>
            </select>
          </div>
          <div class="col-2">
            <button type="button" class="btn btn-primary" @click="addSelectedItem">
              <i class="fa fa-plus"></i> {{ translate('addButton') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Selected items list -->
      <div v-if="items.length > 0" class="selected-items mt-3">
        <h6>{{ translate('assignedItems') }}</h6>
        <div class="table-responsive">
          <table class="table crelish-list">
            <thead>
            <tr>
              <th v-for="column in displayColumns" :key="column.key">{{ column.label }}</th>
              <th>{{ translate('actions') }}</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="(item, index) in items" :key="item.uuid">
              <td v-for="column in displayColumns" :key="`${item.uuid}-${column.key}`">
                {{ getItemProperty(item, column.key) }}
              </td>
              <td class="actions">
                <button v-if="index > 0" type="button" class="c-button u-small" @click="moveItemUp(index)" title="Nach oben">
                  <i class="fa fa-arrow-up"></i>
                </button>
                <button v-if="index < items.length - 1" type="button" class="c-button u-small" @click="moveItemDown(index)" title="Nach unten">
                  <i class="fa fa-arrow-down"></i>
                </button>
                <button type="button" class="c-button u-small" @click="removeItem(index)" title="Löschen">
                  <i class="fa-sharp  fa-regular fa-trash"></i>
                </button>
              </td>
            </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- No items message -->
      <div v-else class="no-items-message">
        {{ translate('noItemsSelected') }}
      </div>
    </div>

    <!-- Hidden input to store value for form submission -->
    <input type="hidden" :name="inputName" id="w3" :value="serializedValue" />
  </div>
</template>

<script>
// We'll assume jQuery is globally available in the application
// and that select2 is either loaded through a global script or will be loaded separately
// This avoids the need to bundle select2 with the component
export default {
  props: {
    // Initial value as JSON string of UUIDs for multiple or single UUID for single
    modelValue: {
      type: String,
      default: '[]'
    },
    // Field configuration
    fieldKey: {
      type: String,
      required: true
    },
    // Related content type
    contentType: {
      type: String,
      required: true
    },
    // Field label
    label: {
      type: String,
      default: ''
    },
    // Input name for form submission
    inputName: {
      type: String,
      required: true
    },
    // Whether this field is required
    required: {
      type: Boolean,
      default: false
    },
    // Columns to display in the table
    columns: {
      type: Array,
      default: () => []
    },
    // Optional translations
    translations: {
      type: Object,
      default: () => ({})
    },
    // Whether to allow multiple selections
    isMultiple: {
      type: Boolean,
      default: true
    },
    // Fields to use for filtering when calling the API
    filterFields: {
      type: Array,
      default: () => ['systitle']
    }
  },

  emits: ['update:modelValue'],

  data() {
    return {
      // Array of selected item objects for multiple mode
      items: [],
      // Single selected item for single mode
      singleItem: null,
      // All available options from API
      options: [],
      // Select2 instances
      select2: null,
      singleSelect2: null,
      // Loading state
      loading: false,
      // Default translations
      defaultTranslations: {
        choosePlaceholder: 'Bitte wählen...',
        addButton: 'Hinzufügen',
        assignedItems: 'Zugeordnete Einträge',
        actions: 'Aktionen',
        noItemsSelected: 'Keine Einträge ausgewählt',
        itemAlreadyAdded: 'Dieser Eintrag wurde bereits hinzugefügt',
        loadingOptions: 'Lade Optionen...'
      }
    };
  },

  computed: {
    // Computed display columns with fallback
    displayColumns() {
      if (this.columns && this.columns.length > 0) {
        return this.columns;
      }
      // Default column if none specified
      return [{ key: 'systitle', label: 'Titel' }];
    },
    // Serialized value for form submission
    serializedValue() {
      if (this.isMultiple) {
        // For multiple items, return JSON array of UUIDs
        return JSON.stringify(this.items.map(item => item.uuid));
      } else {
        // For single item, return just the UUID as a string
        return this.singleItem ? this.singleItem.uuid : '';
      }
    }
  },

  mounted() {
    if (this.isMultiple) {
      this.initializeMultipleSelect();
    } else {
      this.initializeSingleSelect();
    }
    this.loadInitialValue();
  },

  beforeUnmount() {
    // Clean up Select2
    if (this.isMultiple && this.select2) {
      $(this.$refs.selectElement).select2('destroy');
    } else if (!this.isMultiple && this.singleSelect2) {
      $(this.$refs.singleSelectElement).select2('destroy');
    }
  },

  methods: {
    // Initialize Select2 for multiple selection mode
    initializeMultipleSelect() {
      const vm = this;

      // Make sure jQuery and select2 are loaded
      if (typeof $ === 'undefined' || !$.fn.select2) {
        console.error('jQuery or Select2, or both, are not loaded');
        return;
      }

      // Get CSRF token if it exists
      const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';
      const csrfParam = $('meta[name="csrf-param"]').attr('content') || '_csrf';

      $(this.$refs.selectElement).select2({
        placeholder: this.translate('choosePlaceholder'),
        allowClear: true,
        ajax: {
          url: `/crelish-api/content/${this.contentType}`,
          dataType: 'json',
          delay: 250,
          data: function(params) {
            const filterData = {
              page: params.page || 1
            };
            
            // If we have a search term, create a filter parameter using the configured fields
            if (params.term) {
              // Format the filter as "field1:term&field2:term" 
              filterData.filter = vm.filterFields.map(field => `${field}:${params.term}`).join('&');
            }
            
            return filterData;
          },
          beforeSend: function(xhr) {
            // Include CSRF token in the request headers
            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
          },
          processResults: function(data, params) {
            params.page = params.page || 1;

            // Format the results for Select2
            let results = [];
            if (data.success && data.data && data.data.items) {
              results = data.data.items.map(item => ({
                id: item.uuid,
                text: item.systitle || item.title || item.name || item.uuid
              }));
            }

            return {
              results: results,
              pagination: {
                more: (params.page * 20) < (data.data?.total || 0)
              }
            };
          },
          error: function(xhr, status, error) {
            // Log the error and show user feedback
            console.error('Select2 AJAX error:', status, error);
            if (xhr.status === 401) {
              vm.$refs.selectElement.setAttribute('disabled', 'disabled');
              alert('Authentication error - please log in again');
            } else if (xhr.status === 403) {
              vm.$refs.selectElement.setAttribute('disabled', 'disabled');
              alert('Permission error - you do not have access to this resource');
            }
          },
          cache: true,
          xhrFields: {
            // This is important - it sends cookies with the request for authentication
            withCredentials: true
          }
        },
        minimumInputLength: 0
      }).on('select2:select', function(e) {
        // Clear the selection after selecting an item (we'll add it manually)
        const selectedId = e.params.data.id;
        const selectedText = e.params.data.text;

        // Reset the select after selection
        $(this).val(null).trigger('change');

        // Load the full item data and add it
        vm.fetchItemById(selectedId);
      });

      this.select2 = $(this.$refs.selectElement).data('select2');
    },

    // Initialize Select2 for single selection mode
    initializeSingleSelect() {
      const vm = this;

      // Make sure jQuery and select2 are loaded
      if (typeof $ === 'undefined' || !$.fn.select2) {
        console.error('jQuery or Select2, or both, are not loaded');
        return;
      }

      // Get CSRF token if it exists
      const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';

      $(this.$refs.singleSelectElement).select2({
        placeholder: this.translate('choosePlaceholder'),
        allowClear: !this.required,
        ajax: {
          url: `/crelish-api/content/${this.contentType}`,
          dataType: 'json',
          delay: 250,
          data: function(params) {
            const filterData = {
              page: params.page || 1
            };
            
            // If we have a search term, create a filter parameter using the configured fields
            if (params.term) {
              // Format the filter as "field1:term&field2:term" 
              filterData.filter = vm.filterFields.map(field => `${field}:${params.term}`).join('&');
            }
            
            return filterData;
          },
          beforeSend: function(xhr) {
            // Include CSRF token in the request headers
            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
          },
          processResults: function(data, params) {
            params.page = params.page || 1;

            // Format the results for Select2
            let results = [];
            if (data.success && data.data && data.data.items) {
              results = data.data.items.map(item => ({
                id: item.uuid,
                text: item.systitle || item.title || item.name || item.uuid
              }));
            }

            return {
              results: results,
              pagination: {
                more: (params.page * 20) < (data.data?.total || 0)
              }
            };
          },
          error: function(xhr, status, error) {
            // Log the error and show user feedback
            console.error('Select2 AJAX error:', status, error);
            if (xhr.status === 401) {
              vm.$refs.singleSelectElement.setAttribute('disabled', 'disabled');
              alert('Authentication error - please log in again');
            } else if (xhr.status === 403) {
              vm.$refs.singleSelectElement.setAttribute('disabled', 'disabled');
              alert('Permission error - you do not have access to this resource');
            }
          },
          cache: true,
          xhrFields: {
            // This is important - it sends cookies with the request for authentication
            withCredentials: true
          }
        },
        minimumInputLength: 0
      }).on('select2:select', function(e) {
        const selectedId = e.params.data.id;

        // Load the full item data
        vm.fetchSingleItem(selectedId);
      }).on('select2:clear', function() {
        // Clear the selected item
        vm.singleItem = null;
        vm.emitUpdate();
      });

      this.singleSelect2 = $(this.$refs.singleSelectElement).data('select2');
    },

    // Load initial value
    loadInitialValue() {
      if (this.isMultiple) {
        this.loadInitialItems();
      } else {
        this.loadInitialSingleItem();
      }
    },

    // Load initial items for multiple mode
    loadInitialItems() {
      try {
        const initialIds = JSON.parse(this.modelValue || '[]');

        if (Array.isArray(initialIds) && initialIds.length > 0) {
          this.loading = true;

          // Create a map to store items by ID temporarily
          const itemsMap = new Map();

          // Fetch each item by ID
          Promise.all(initialIds.map(id =>
              this.fetchItemById(id, false)
                  .then(item => {
                    if (item) {
                      itemsMap.set(id, item);
                    }
                    return item;
                  })
          ))
              .then(() => {
                // After all items are fetched, add them to the items array
                // in the original order from initialIds
                this.items = initialIds
                    .map(id => itemsMap.get(id))
                    .filter(item => item !== undefined);

                this.loading = false;
              })
              .catch(error => {
                console.error('Error loading initial items:', error);
                this.loading = false;
              });
        }
      } catch (e) {
        console.error('Error parsing initial value:', e);
      }
    },

    // Load initial single item
    loadInitialSingleItem() {
      let initialId = this.modelValue;

      // Handle case where the value might be stored as a JSON array with one item
      try {
        const parsed = JSON.parse(this.modelValue);
        if (Array.isArray(parsed) && parsed.length > 0) {
          initialId = parsed[0];
        }
      } catch (e) {
        // Not a JSON array, use as is
      }

      if (initialId) {
        this.loading = true;

        this.fetchSingleItem(initialId)
            .then(() => {
              this.loading = false;
            })
            .catch(error => {
              console.error('Error loading initial item:', error);
              this.loading = false;
            });
      }
    },

    // Fetch a single item by its ID for multiple mode
    fetchItemById(id, addToItems = true) {
      // Get CSRF token if it exists
      const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';

      return fetch(`/crelish-api/content/${this.contentType}/${id}`, {
        method: 'GET',
        credentials: 'include',  // Important: include cookies for authentication
        headers: {
          'X-CSRF-Token': csrfToken,
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        }
      })
          .then(response => {
            if (!response.ok) {
              if (response.status === 401) {
                console.error('Authentication error: Not authorized to access API');
                throw new Error('Authentication error - please log in');
              } else if (response.status === 403) {
                console.error('Permission error: Insufficient permissions to access API');
                throw new Error('Permission error - insufficient access rights');
              } else {
                console.error(`API error: ${response.status}`);
                throw new Error(`API error ${response.status}`);
              }
            }
            return response.json();
          })
          .then(data => {
            if (data.success && data.data) {
              if (addToItems) {
                // Check if the item is already in the list
                const exists = this.items.some(item => item.uuid === id);
                if (exists) {
                  alert(this.translate('itemAlreadyAdded'));
                } else {
                  this.items.push(data.data);
                  this.emitUpdate();
                }
              }
              return data.data;
            }
            throw new Error(data.message || 'Item not found');
          })
          .catch(error => {
            console.error('Error fetching item:', error);
            if (addToItems) {
              // Only show alert for user-initiated actions, not during initial loading
              alert(error.message || 'Error fetching data from API');
            }
            return null;
          });
    },

    // Fetch a single item for single mode
    fetchSingleItem(id) {
      // Get CSRF token if it exists
      const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';

      return fetch(`/crelish-api/content/${this.contentType}/${id}`, {
        method: 'GET',
        credentials: 'include',
        headers: {
          'X-CSRF-Token': csrfToken,
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        }
      })
          .then(response => {
            if (!response.ok) {
              throw new Error(`API error ${response.status}`);
            }
            return response.json();
          })
          .then(data => {
            if (data.success && data.data) {
              this.singleItem = data.data;

              // Update the select2 display
              const selectElement = $(this.$refs.singleSelectElement);
              const option = new Option(
                  data.data.systitle || data.data.title || data.data.name || data.data.uuid,
                  data.data.uuid,
                  true,
                  true
              );
              selectElement.append(option).trigger('change');

              this.emitUpdate();
              return data.data;
            }
            throw new Error(data.message || 'Item not found');
          })
          .catch(error => {
            console.error('Error fetching single item:', error);
            return null;
          });
    },

    // Add the selected item to the list (multiple mode)
    addSelectedItem() {
      const selectElement = $(this.$refs.selectElement);
      const selectedValue = selectElement.val();

      if (!selectedValue) return;

      this.fetchItemById(selectedValue);
    },

    // Remove an item from the list (multiple mode)
    removeItem(index) {
      this.items.splice(index, 1);
      this.emitUpdate();
    },

    // Move an item up in the list (multiple mode)
    moveItemUp(index) {
      if (index > 0) {
        const temp = this.items[index - 1];
        this.items[index - 1] = this.items[index];
        this.items[index] = temp;
        this.emitUpdate();
      }
    },

    // Move an item down in the list (multiple mode)
    moveItemDown(index) {
      if (index < this.items.length - 1) {
        const temp = this.items[index + 1];
        this.items[index + 1] = this.items[index];
        this.items[index] = temp;
        this.emitUpdate();
      }
    },

    // Get a property from an item object, handling nested properties
    getItemProperty(item, key) {
      if (!key || !item) return '';

      // Handle nested properties with dot notation
      if (key.includes('.')) {
        let value = item;
        const parts = key.split('.');

        for (const part of parts) {
          if (value && typeof value === 'object' && part in value) {
            value = value[part];
          } else {
            return '';
          }
        }

        return value;
      }

      return item[key] || '';
    },

    // Emit update event with new value
    emitUpdate() {
      this.$emit('update:modelValue', this.serializedValue);
    },

    // Get translation with fallback
    translate(key) {
      return this.translations[key] || this.defaultTranslations[key] || key;
    }
  }
};
</script>

<style scoped>
.relation-selector {
  margin-bottom: 1.5rem;
}

.relation-selector-label {
  margin-bottom: 0.5rem;
  font-weight: bold;
}

.selected-items {
  border: 1px solid #dee2e6;
  border-radius: 0.25rem;
  padding: 0.75rem;
  background-color: #f8f9fa;
}

.no-items-message {
  padding: 0.75rem;
  color: #6c757d;
  font-style: italic;
}

.required .relation-selector-label::after {
  content: "*";
  color: red;
  margin-left: 0.25rem;
}

.actions {
  white-space: nowrap;
}

.actions button {
  margin-right: 0.25rem;
}

.actions button:last-child {
  margin-right: 0;
}

.single-relation-mode {
  margin-bottom: 1rem;
}
</style>