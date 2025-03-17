<template>
  <div class="elements-editor">
    <div class="alert alert-success" v-if="successMessage" role="alert">
      {{ successMessage }}
    </div>
    <div class="alert alert-danger" v-if="errorMessage" role="alert">
      {{ errorMessage }}
    </div>

    <!-- Basic Element Information -->
    <div class="card mb-4">
      <div class="card-header">
        <h5 class="mb-0">Basic Information</h5>
      </div>
      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-6">
            <label for="elementKey" class="form-label">Element Key</label>
            <input 
              type="text" 
              class="form-control" 
              id="elementKey" 
              v-model="element.key" 
              :disabled="!isNew"
              placeholder="e.g. my_element_type"
            >
            <small class="form-text text-muted">
              Lowercase letters, numbers, and underscores only. Cannot be changed after creation.
            </small>
          </div>
          <div class="col-md-6">
            <label for="elementLabel" class="form-label">Element Label</label>
            <input 
              type="text" 
              class="form-control" 
              id="elementLabel" 
              v-model="element.label" 
              placeholder="e.g. My Element Type"
            >
          </div>
        </div>
        <div class="row mb-3">
          <div class="col-md-4">
            <label for="elementStorage" class="form-label">Storage Type</label>
            <select class="form-select" id="elementStorage" v-model="element.storage">
              <option value="db">Database</option>
              <option value="json">JSON File</option>
            </select>
          </div>
          <div class="col-md-4">
            <label for="elementCategory" class="form-label">Category</label>
            <input 
              type="text" 
              class="form-control" 
              id="elementCategory" 
              v-model="element.category" 
              placeholder="e.g. Content"
            >
          </div>
          <div class="col-md-4">
            <label for="elementSelectable" class="form-label">Selectable</label>
            <div class="form-check form-switch mt-2">
              <input 
                class="form-check-input" 
                type="checkbox" 
                id="elementSelectable" 
                v-model="element.selectable"
              >
              <label class="form-check-label" for="elementSelectable">
                Allow selection in content pickers
              </label>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabs Management -->
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Tabs</h5>
        <button type="button" class="btn btn-sm btn-primary" @click="addTab">
          Add Tab
        </button>
      </div>
      <div class="card-body">
        <div v-if="element.tabs.length === 0" class="text-center py-4">
          <p class="text-muted">No tabs defined. Click "Add Tab" to create one.</p>
        </div>
        
        <div v-for="(tab, tabIndex) in element.tabs" :key="tabIndex" class="tab-item mb-4 border p-3 rounded">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Tab {{ tabIndex + 1 }}</h6>
            <button type="button" class="btn btn-sm btn-danger" @click="removeTab(tabIndex)">
              Remove
            </button>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-6">
              <label :for="'tabLabel' + tabIndex" class="form-label">Label</label>
              <input 
                type="text" 
                class="form-control" 
                :id="'tabLabel' + tabIndex" 
                v-model="tab.label" 
                placeholder="Tab Label"
              >
            </div>
            <div class="col-md-6">
              <label :for="'tabKey' + tabIndex" class="form-label">Key</label>
              <input 
                type="text" 
                class="form-control" 
                :id="'tabKey' + tabIndex" 
                v-model="tab.key" 
                placeholder="tab_key"
              >
            </div>
          </div>
          
          <div class="form-check mb-3">
            <input 
              class="form-check-input" 
              type="checkbox" 
              :id="'tabVisible' + tabIndex" 
              v-model="tab.visible"
            >
            <label class="form-check-label" :for="'tabVisible' + tabIndex">
              Visible
            </label>
          </div>
          
          <!-- Groups within Tab -->
          <div class="groups-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="mb-0">Groups</h6>
              <button type="button" class="btn btn-sm btn-outline-primary" @click="addGroup(tabIndex)">
                Add Group
              </button>
            </div>
            
            <div v-if="!tab.groups || tab.groups.length === 0" class="text-center py-3">
              <p class="text-muted">No groups defined. Click "Add Group" to create one.</p>
            </div>
            
            <div v-for="(group, groupIndex) in tab.groups" :key="groupIndex" class="group-item mb-3 border-start ps-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">Group {{ groupIndex + 1 }}</h6>
                <button type="button" class="btn btn-sm btn-outline-danger" @click="removeGroup(tabIndex, groupIndex)">
                  Remove
                </button>
              </div>
              
              <div class="row mb-3">
                <div class="col-md-6">
                  <label :for="'groupLabel' + tabIndex + '-' + groupIndex" class="form-label">Label</label>
                  <input 
                    type="text" 
                    class="form-control" 
                    :id="'groupLabel' + tabIndex + '-' + groupIndex" 
                    v-model="group.label" 
                    placeholder="Group Label"
                  >
                </div>
                <div class="col-md-6">
                  <label :for="'groupKey' + tabIndex + '-' + groupIndex" class="form-label">Key</label>
                  <input 
                    type="text" 
                    class="form-control" 
                    :id="'groupKey' + tabIndex + '-' + groupIndex" 
                    v-model="group.key" 
                    placeholder="group_key"
                  >
                </div>
              </div>
              
              <!-- Field Selection for Group -->
              <div class="mb-3">
                <label :for="'groupFields' + tabIndex + '-' + groupIndex" class="form-label">Fields</label>
                <select 
                  class="form-select" 
                  :id="'groupFields' + tabIndex + '-' + groupIndex" 
                  v-model="group.fields" 
                  multiple
                >
                  <option v-for="field in fieldKeys" :key="field" :value="field">
                    {{ field }}
                  </option>
                </select>
                <small class="form-text text-muted">
                  Hold Ctrl/Cmd to select multiple fields
                </small>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Fields Management -->
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Fields</h5>
        <button type="button" class="btn btn-sm btn-primary" @click="addField">
          Add Field
        </button>
      </div>
      <div class="card-body">
        <div v-if="element.fields.length === 0" class="text-center py-4">
          <p class="text-muted">No fields defined. Click "Add Field" to create one.</p>
        </div>
        
        <div v-for="(field, fieldIndex) in element.fields" :key="fieldIndex" class="field-item mb-4 border p-3 rounded">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Field {{ fieldIndex + 1 }}</h6>
            <button type="button" class="btn btn-sm btn-danger" @click="removeField(fieldIndex)">
              Remove
            </button>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-6">
              <label :for="'fieldLabel' + fieldIndex" class="form-label">Label</label>
              <input 
                type="text" 
                class="form-control" 
                :id="'fieldLabel' + fieldIndex" 
                v-model="field.label" 
                placeholder="Field Label"
              >
            </div>
            <div class="col-md-6">
              <label :for="'fieldKey' + fieldIndex" class="form-label">Key</label>
              <input 
                type="text" 
                class="form-control" 
                :id="'fieldKey' + fieldIndex" 
                v-model="field.key" 
                placeholder="field_key"
              >
            </div>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-6">
              <label :for="'fieldType' + fieldIndex" class="form-label">Type</label>
              <select 
                class="form-select" 
                :id="'fieldType' + fieldIndex" 
                v-model="field.type"
              >
                <option v-for="(label, type) in fieldTypes" :key="type" :value="type">
                  {{ label }}
                </option>
              </select>
            </div>
            <div class="col-md-6">
              <label :for="'fieldTransform' + fieldIndex" class="form-label">Transform</label>
              <select 
                class="form-select" 
                :id="'fieldTransform' + fieldIndex" 
                v-model="field.transform"
              >
                <option value="">None</option>
                <option value="json">JSON</option>
                <option value="datetime">DateTime</option>
                <option value="date">Date</option>
                <option value="state">State</option>
              </select>
            </div>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-4">
              <div class="form-check">
                <input 
                  class="form-check-input" 
                  type="checkbox" 
                  :id="'fieldVisibleInGrid' + fieldIndex" 
                  v-model="field.visibleInGrid"
                >
                <label class="form-check-label" :for="'fieldVisibleInGrid' + fieldIndex">
                  Visible in Grid
                </label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check">
                <input 
                  class="form-check-input" 
                  type="checkbox" 
                  :id="'fieldSortable' + fieldIndex" 
                  v-model="field.sortable"
                >
                <label class="form-check-label" :for="'fieldSortable' + fieldIndex">
                  Sortable
                </label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check">
                <input 
                  class="form-check-input" 
                  type="checkbox" 
                  :id="'fieldTranslatable' + fieldIndex" 
                  v-model="field.translatable"
                >
                <label class="form-check-label" :for="'fieldTranslatable' + fieldIndex">
                  Translatable
                </label>
              </div>
            </div>
          </div>
          
          <!-- Field Rules -->
          <div class="field-rules mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <label class="form-label mb-0">Validation Rules</label>
              <button type="button" class="btn btn-sm btn-outline-primary" @click="addRule(fieldIndex)">
                Add Rule
              </button>
            </div>
            
            <div v-if="!field.rules || field.rules.length === 0" class="text-center py-2">
              <small class="text-muted">No rules defined. Click "Add Rule" to create one.</small>
            </div>
            
            <div v-for="(rule, ruleIndex) in field.rules" :key="ruleIndex" class="rule-item d-flex align-items-center mb-2">
              <select 
                class="form-select me-2" 
                v-model="rule[0]"
                @change="ensureRuleStructure(fieldIndex, ruleIndex)"
              >
                <option value="required">Required</option>
                <option value="string">String</option>
                <option value="integer">Integer</option>
                <option value="email">Email</option>
                <option value="url">URL</option>
                <option value="safe">Safe</option>
              </select>
              
              <div v-if="rule[0] === 'string'" class="d-flex align-items-center me-2">
                <span class="me-2">Max:</span>
                <input 
                  type="number" 
                  class="form-control" 
                  :value="rule[1]?.max || 255"
                  @input="updateRuleMax(fieldIndex, ruleIndex, $event)"
                  style="width: 100px;"
                >
              </div>
              
              <button type="button" class="btn btn-sm btn-outline-danger" @click="removeRule(fieldIndex, ruleIndex)">
                Remove
              </button>
            </div>
          </div>
          
          <!-- Field Options for dropDownList -->
          <div v-if="field.type === 'dropDownList'" class="field-options mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <label class="form-label mb-0">Dropdown Options</label>
              <button type="button" class="btn btn-sm btn-outline-primary" @click="addOption(fieldIndex)">
                Add Option
              </button>
            </div>
            
            <div v-if="!field.items || Object.keys(field.items).length === 0" class="text-center py-2">
              <small class="text-muted">No options defined. Click "Add Option" to create one.</small>
            </div>
            
            <div v-for="(label, value) in field.items" :key="value" class="option-item d-flex align-items-center mb-2">
              <input 
                type="text" 
                class="form-control me-2" 
                v-model="optionValues[fieldIndex][value]" 
                placeholder="Value"
                style="width: 150px;"
              >
              <input 
                type="text" 
                class="form-control me-2" 
                v-model="field.items[optionValues[fieldIndex][value]]" 
                placeholder="Label"
              >
              <button type="button" class="btn btn-sm btn-outline-danger" @click="removeOption(fieldIndex, value)">
                Remove
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Save Button -->
    <div class="d-flex justify-content-end mb-4">
      <button type="button" class="btn btn-primary" @click="saveElement">
        Save Element
      </button>
    </div>
  </div>
</template>

<script>
export default {
  name: 'ElementsEditor',
  props: {
    initialElementData: {
      type: Object,
      default: null
    },
    elementKey: {
      type: String,
      default: ''
    }
  },
  data() {
    return {
      element: {
        key: '',
        label: '',
        storage: 'db',
        category: 'Content',
        selectable: true,
        tabs: [],
        fields: [],
        sortDefault: { systitle: 'SORT_ASC' }
      },
      isNew: true,
      successMessage: '',
      errorMessage: '',
      fieldTypes: {},
      optionValues: {}
    };
  },
  computed: {
    fieldKeys() {
      return this.element.fields.map(field => field.key);
    }
  },
  created() {
    // Initialize with default or provided data
    if (this.initialElementData) {
      this.element = this.initialElementData;
      this.isNew = false;
    } else if (this.elementKey) {
      this.loadElement(this.elementKey);
    } else {
      this.isNew = true;
      // Add default tab and group
      this.addTab();
    }
    
    // Load field types
    this.loadFieldTypes();
    
    // Initialize option values tracking
    this.initOptionValues();
    
    // Initialize rule structures
    this.initRuleStructures();
  },
  methods: {
    loadElement(key) {
      fetch(`/elements/get?key=${key}`)
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.element = data.data;
            this.isNew = false;
            this.initOptionValues();
            this.initRuleStructures();
          } else {
            this.errorMessage = data.message || 'Failed to load element';
          }
        })
        .catch(error => {
          console.error('Error loading element:', error);
          this.errorMessage = 'An error occurred while loading the element';
        });
    },
    
    loadFieldTypes() {
      fetch('/crelish/elements/field-types')
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.fieldTypes = data.data;
          }
        })
        .catch(error => {
          console.error('Error loading field types:', error);
        });
    },
    
    initOptionValues() {
      this.optionValues = {};
      this.element.fields.forEach((field, index) => {
        if (field.items) {
          this.optionValues[index] = {};
          Object.keys(field.items).forEach(key => {
            this.optionValues[index][key] = key;
          });
        }
      });
    },
    
    initRuleStructures() {
      // Ensure all string rules have the proper structure
      this.element.fields.forEach((field, fieldIndex) => {
        if (field.rules && Array.isArray(field.rules)) {
          field.rules.forEach((rule, ruleIndex) => {
            this.ensureRuleStructure(fieldIndex, ruleIndex);
          });
        }
      });
    },
    
    addTab() {
      const newTab = {
        label: 'New Tab',
        key: 'new_tab_' + (this.element.tabs.length + 1),
        visible: true,
        groups: []
      };
      
      this.element.tabs.push(newTab);
      this.addGroup(this.element.tabs.length - 1);
    },
    
    removeTab(index) {
      this.element.tabs.splice(index, 1);
    },
    
    addGroup(tabIndex) {
      if (!this.element.tabs[tabIndex].groups) {
        this.element.tabs[tabIndex].groups = [];
      }
      
      const newGroup = {
        label: 'New Group',
        key: 'new_group_' + (this.element.tabs[tabIndex].groups.length + 1),
        fields: []
      };
      
      this.element.tabs[tabIndex].groups.push(newGroup);
    },
    
    removeGroup(tabIndex, groupIndex) {
      this.element.tabs[tabIndex].groups.splice(groupIndex, 1);
    },
    
    addField() {
      const newField = {
        label: 'New Field',
        key: 'new_field_' + (this.element.fields.length + 1),
        type: 'textInput',
        visibleInGrid: false,
        sortable: false,
        translatable: false,
        rules: []
      };
      
      this.element.fields.push(newField);
      
      // Initialize option values for this field
      const fieldIndex = this.element.fields.length - 1;
      this.optionValues[fieldIndex] = {};
    },
    
    removeField(index) {
      this.element.fields.splice(index, 1);
      
      // Update option values
      delete this.optionValues[index];
      
      // Reindex option values
      const newOptionValues = {};
      Object.keys(this.optionValues).forEach(key => {
        const numKey = parseInt(key);
        if (numKey > index) {
          newOptionValues[numKey - 1] = this.optionValues[key];
        } else if (numKey < index) {
          newOptionValues[numKey] = this.optionValues[key];
        }
      });
      
      this.optionValues = newOptionValues;
    },
    
    addRule(fieldIndex) {
      if (!this.element.fields[fieldIndex].rules) {
        this.element.fields[fieldIndex].rules = [];
      }
      
      // Default to required rule
      const newRule = ['required'];
      
      this.element.fields[fieldIndex].rules.push(newRule);
    },
    
    removeRule(fieldIndex, ruleIndex) {
      this.element.fields[fieldIndex].rules.splice(ruleIndex, 1);
    },
    
    ensureRuleStructure(fieldIndex, ruleIndex) {
      const rule = this.element.fields[fieldIndex].rules[ruleIndex];
      
      // Initialize the second element of the rule array based on rule type
      if (rule[0] === 'string' && (!rule[1] || typeof rule[1] !== 'object')) {
        // Vue 3 way - directly set the property
        this.element.fields[fieldIndex].rules[ruleIndex][1] = { max: 255 };
      } else if (rule[0] !== 'string' && rule.length > 1) {
        // Remove the second element if not needed
        this.element.fields[fieldIndex].rules[ruleIndex].splice(1, 1);
      }
    },
    
    updateRuleMax(fieldIndex, ruleIndex, event) {
      const value = parseInt(event.target.value) || 255;
      
      // Ensure rule[1] exists
      if (!this.element.fields[fieldIndex].rules[ruleIndex][1]) {
        this.element.fields[fieldIndex].rules[ruleIndex][1] = {};
      }
      
      // Update the max value
      this.element.fields[fieldIndex].rules[ruleIndex][1].max = value;
    },
    
    addOption(fieldIndex) {
      if (!this.element.fields[fieldIndex].items) {
        this.element.fields[fieldIndex].items = {};
      }
      
      if (!this.optionValues[fieldIndex]) {
        this.optionValues[fieldIndex] = {};
      }
      
      const newValue = 'option_' + (Object.keys(this.element.fields[fieldIndex].items).length + 1);
      const newLabel = 'Option ' + (Object.keys(this.element.fields[fieldIndex].items).length + 1);
      
      this.element.fields[fieldIndex].items[newValue] = newLabel;
      this.optionValues[fieldIndex][newValue] = newValue;
    },
    
    removeOption(fieldIndex, value) {
      if (this.element.fields[fieldIndex].items && this.element.fields[fieldIndex].items[value]) {
        const items = { ...this.element.fields[fieldIndex].items };
        delete items[value];
        this.element.fields[fieldIndex].items = items;
        
        // Also remove from option values
        delete this.optionValues[fieldIndex][value];
      }
    },
    
    saveElement() {
      // Clear messages
      this.successMessage = '';
      this.errorMessage = '';
      
      // Validate element
      if (!this.element.key) {
        this.errorMessage = 'Element key is required';
        return;
      }
      
      if (!this.element.label) {
        this.errorMessage = 'Element label is required';
        return;
      }
      
      // Update option values before saving
      this.element.fields.forEach((field, index) => {
        if (field.items && this.optionValues[index]) {
          const newItems = {};
          Object.keys(field.items).forEach(oldKey => {
            const newKey = this.optionValues[index][oldKey] || oldKey;
            newItems[newKey] = field.items[oldKey];
          });
          field.items = newItems;
        }
      });
      
      // Send to server
      fetch('/elements/save', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': this.getCsrfToken()
        },
        body: JSON.stringify(this.element)
      })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.successMessage = data.message;
            
            // If this was a new element, update the URL
            if (this.isNew && data.key) {
              this.isNew = false;
              // Update URL without reloading the page
              window.history.pushState({}, '', `/elements/edit?element=${data.key}.json`);
            }
          } else {
            this.errorMessage = data.message || 'Failed to save element';
          }
        })
        .catch(error => {
          console.error('Error saving element:', error);
          this.errorMessage = 'An error occurred while saving the element';
        });
    },
    
    getCsrfToken() {
      // Get CSRF token from meta tag
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      return csrfToken || '';
    }
  }
};
</script>

<style scoped>
.elements-editor {
  padding: 20px;
}

.field-item, .tab-item {
  background-color: #f8f9fa;
}

.group-item {
  border-left: 3px solid #dee2e6;
}

.rule-item, .option-item {
  background-color: #fff;
  padding: 8px;
  border-radius: 4px;
}
</style> 