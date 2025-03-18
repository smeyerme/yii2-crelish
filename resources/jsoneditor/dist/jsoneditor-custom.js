/**
 * Custom JSONEditor implementation for Crelish CMS
 * This script initializes and configures JSONEditor instances for the Crelish CMS
 */
(function() {
  // Map to store editor instances by their ID
  const editorInstances = {};
  
  // Register custom formats for JSON Schema validation
  const registerCustomFormats = function() {
    // First check if JSONEditor exists
    if (typeof JSONEditor === 'undefined') {
      console.warn('JSONEditor not found when registering custom formats');
      return;
    }
    
    try {
      // Ensure defaults and options exist
      JSONEditor.defaults = JSONEditor.defaults || {};
      JSONEditor.defaults.options = JSONEditor.defaults.options || {};
      
      // Create Ajv instance if it exists globally
      if (typeof Ajv !== 'undefined') {
        const ajv = new Ajv({ allErrors: true, verbose: true });
        
        // Register HTML format
        ajv.addFormat('html', {
          type: 'string',
          validate: function(value) {
            // Always return true to accept any string as HTML
            // This is just to avoid the "unknown format" error
            return true;
          }
        });
        
        // Register custom formats in JSONEditor
        JSONEditor.defaults.options.ajv = ajv;
      } else {
        // If Ajv is not available, handle formats directly
        JSONEditor.defaults.resolvers = JSONEditor.defaults.resolvers || {};
        
        // Add a resolver for HTML format
        const originalResolver = JSONEditor.defaults.resolvers.schema;
        JSONEditor.defaults.resolvers.schema = function(schema) {
          // Process format property before passing to original resolver
          if (schema && schema.format === 'html') {
            schema.format = undefined;
            schema.options = schema.options || {};
            schema.options.wysiwyg = true;
          }
          
          // Then call the original resolver if it exists
          return originalResolver ? originalResolver(schema) : schema;
        };
      }
    } catch (err) {
      console.warn('Error registering custom formats:', err);
    }
  };
  
  // Initialize all JSONEditor instances on the page
  document.addEventListener('DOMContentLoaded', function() {
    // Check if JSONEditor is available
    if (typeof JSONEditor === 'undefined') {
      console.error('JSONEditor library not found. Please make sure it is properly loaded.');
      
      // Try to add a script tag to load JSONEditor from CDN if not available
      const cdnScript = document.createElement('script');
      cdnScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/jsoneditor/9.10.2/jsoneditor.min.js';
      cdnScript.onload = function() {
        // Now that the script is loaded, register formats and initialize
        if (typeof JSONEditor !== 'undefined') {
          registerCustomFormats();
          initializeAllEditors();
        }
      };
      document.head.appendChild(cdnScript);
      
      // Also add the CSS if not present
      if (!document.querySelector('link[href*="jsoneditor.min.css"]')) {
        const cdnCss = document.createElement('link');
        cdnCss.rel = 'stylesheet';
        cdnCss.href = 'https://cdnjs.cloudflare.com/ajax/libs/jsoneditor/9.10.2/jsoneditor.min.css';
        document.head.appendChild(cdnCss);
      }
      
      return;
    }
    
    // Register custom formats
    registerCustomFormats();
    
    // Initialize all editors
    initializeAllEditors();
  });
  
  /**
   * Initialize all JSON editor instances on the page
   */
  function initializeAllEditors() {
    // Find all JSON editor containers
    const containers = document.querySelectorAll('.json-editor-container');
    
    containers.forEach(function(container) {
      initializeEditor(container);
    });
    
    // Add language switcher support
    const languageSelect = document.getElementById('language-select');
    if (languageSelect) {
      languageSelect.addEventListener('change', function() {
        // Show/hide fields based on selected language
        const selectedLang = this.value;
        const langFields = document.querySelectorAll('[data-language]');
        
        langFields.forEach(function(field) {
          const fieldLang = field.getAttribute('data-language');
          const formGroup = field.closest('.form-group');
          
          if (formGroup) {
            if (fieldLang === selectedLang) {
              formGroup.style.display = 'block';
            } else {
              formGroup.style.display = 'none';
            }
          }
        });
        
        // Update editor visibility after language change
        updateEditorsForLanguage(selectedLang);
      });
      
      // Trigger initial language filter
      languageSelect.dispatchEvent(new Event('change'));
    }
  }
  
  /**
   * Update visibility of editors based on selected language
   * @param {string} selectedLang The selected language
   */
  function updateEditorsForLanguage(selectedLang) {
    // Loop through all editors
    Object.keys(editorInstances).forEach(function(editorId) {
      const container = document.getElementById(editorId);
      if (container) {
        const containerLang = container.getAttribute('data-language');
        
        // Only show editors for the selected language
        if (containerLang && containerLang !== selectedLang) {
          // Hide editor for non-selected languages
          container.style.display = 'none';
        } else {
          // Show editor for selected language
          container.style.display = 'block';
          
          // Refresh editor to ensure proper rendering
          const editor = editorInstances[editorId];
          if (editor) {
            try {
              editor.refresh();
            } catch (e) {
              // Ignore refresh errors
            }
          }
        }
      }
    });
  }
  
  /**
   * Initialize a JSON editor on a container element
   * @param {HTMLElement} container The container element
   */
  function initializeEditor(container) {
    // Get the hidden input and editor ID
    const editorId = container.id;
    const hiddenInput = document.getElementById('hidden-' + editorId);
    
    if (!hiddenInput) {
      console.error('Hidden input not found for JSON editor', editorId);
      return;
    }
    
    try {
      // Get initial data from the hidden input
      let initialData = {};
      try {
        const inputValue = hiddenInput.value.trim();
        if (inputValue && inputValue !== 'null') {
          initialData = JSON.parse(inputValue);
        }
      } catch (err) {
        console.warn('Invalid initial JSON data, using empty object', err);
      }
      
      // If initialData is null, convert to appropriate empty structure
      if (initialData === null) {
        initialData = {};
      }
      
      // Get schema from data attribute if available
      let schema = null;
      if (container.hasAttribute('data-schema')) {
        try {
          schema = JSON.parse(container.getAttribute('data-schema'));
        } catch (err) {
          console.warn('Invalid schema, not using schema for validation', err);
        }
      }
      
      // Process schema to handle special formats
      if (schema) {
        processSchemaFormats(schema);
      }
      
      // Editor options
      const options = {
        mode: 'tree',
        modes: ['tree', 'code', 'form', 'text', 'view'],
        onChangeJSON: function(json) {
          try {
            // Update the hidden input with JSON data
            hiddenInput.value = JSON.stringify(json);
          } catch (err) {
            console.error('Error updating JSON value', err);
          }
        },
        onValidationError: function(errors) {
          if (errors.length) {
            console.warn('JSON validation errors:', errors);
          }
        },
        // Custom editor for HTML content fields
        onCreateMenu: function(items, node) {
          // Check if this node has format: "html" in the original schema
          if (isHTMLField(node, schema)) {
            // Add custom HTML edit option
            items.push({
              text: 'Edit HTML',
              title: 'Edit HTML content in a larger editor',
              className: 'jsoneditor-edit-html',
              click: function() {
                editHTMLContent(node);
              }
            });
          }
          return items;
        },
        onRenderValue: function(element, value, path) {
          // Enhance rendering of HTML content
          if (value && typeof value === 'string' && isHTMLFieldByPath(path, schema)) {
            enhanceHTMLField(element, value);
            return true;
          }
          return false;
        }
      };
      
      // Add schema if provided
      if (schema) {
        options.schema = schema;
      }
      
      // Create editor instance
      const editor = new JSONEditor(container, options);
      
      // Set initial data or empty object/array based on schema
      if (Object.keys(initialData).length > 0 || Array.isArray(initialData)) {
        editor.set(initialData);
      } else if (schema && schema.type === 'array') {
        editor.set([]);
      } else {
        editor.set({});
      }
      
      // Store editor instance for later reference
      editorInstances[editorId] = editor;
      
      // Also store on DOM element for access by other components
      container._jsonEditor = editor;
      
      // Try to set the initial mode
      const initialMode = container.getAttribute('data-mode');
      if (initialMode && editor.setMode) {
        editor.setMode(initialMode);
      }
      
      // Add custom CSS class to editor for styling
      container.classList.add('jsoneditor-initialized');
      
      // Handle language-specific visibility
      const containerLang = container.getAttribute('data-language');
      if (containerLang) {
        const currentLang = document.getElementById('language-select') ? 
          document.getElementById('language-select').value : null;
          
        if (currentLang && containerLang !== currentLang) {
          container.style.display = 'none';
        }
      }
    } catch (err) {
      console.error('Error initializing JSON editor', err);
      container.innerHTML = '<div class="alert alert-danger">Error initializing JSON editor: ' + err.message + '</div>';
    }
  }
  
  /**
   * Process schema formats to handle any special cases
   * @param {Object} schema The JSON Schema object
   */
  function processSchemaFormats(schema) {
    if (!schema) return;
    
    // Process current schema node
    if (schema.format === 'html') {
      // Use a custom editor for HTML fields
      schema.format = undefined; // Remove format to avoid validation errors
      schema.options = schema.options || {};
      schema.options.wysiwyg = true; // Flag for potential WYSIWYG handling
    }
    
    // Recursively process properties
    if (schema.properties) {
      Object.keys(schema.properties).forEach(function(key) {
        if (schema.properties[key]) {
          processSchemaFormats(schema.properties[key]);
        }
      });
    }
    
    // Recursively process array items
    if (schema.items) {
      processSchemaFormats(schema.items);
    }
    
    // Recursively process oneOf, anyOf, allOf
    ['oneOf', 'anyOf', 'allOf'].forEach(function(key) {
      if (schema[key] && Array.isArray(schema[key])) {
        schema[key].forEach(function(subSchema) {
          if (subSchema) {
            processSchemaFormats(subSchema);
          }
        });
      }
    });
  }
  
  /**
   * Check if the current node represents an HTML field based on schema
   * @param {Object} node JSONEditor node object
   * @param {Object} schema JSON Schema object
   * @return {Boolean} True if this is an HTML field
   */
  function isHTMLField(node, schema) {
    if (!node || !schema) return false;
    
    // Get path to this node
    const path = node.path;
    return isHTMLFieldByPath(path, schema);
  }
  
  /**
   * Check if a field at the given path is an HTML field
   * @param {Array} path Path array to the field
   * @param {Object} schema JSON Schema object
   * @return {Boolean} True if this is an HTML field
   */
  function isHTMLFieldByPath(path, schema) {
    if (!path || !path.length || !schema) return false;
    
    // Special check for content fields (common for HTML)
    const fieldName = path[path.length - 1];
    if (typeof fieldName === 'string' && fieldName === 'content') {
      return true;
    }
    
    // Navigate the schema to find the field definition
    let schemaNode = schema;
    
    try {
      for (let i = 0; i < path.length; i++) {
        const key = path[i];
        
        if (schemaNode.type === 'array' && schemaNode.items) {
          if (typeof key === 'number') {
            // Array index - use items schema
            schemaNode = schemaNode.items;
            continue;
          }
        }
        
        if (schemaNode.properties && schemaNode.properties[key]) {
          schemaNode = schemaNode.properties[key];
        } else {
          return false; // Path doesn't match schema
        }
      }
      
      // Check if the field uses html format or is flagged with wysiwyg option
      return (
        (schemaNode.format === 'html') || 
        (schemaNode.options && schemaNode.options.wysiwyg === true)
      );
    } catch (err) {
      return false;
    }
  }
  
  /**
   * Enhance HTML content rendering in the editor
   * @param {HTMLElement} element The DOM element to enhance
   * @param {String} value The HTML content value
   */
  function enhanceHTMLField(element, value) {
    // Mark the parent element as HTML content
    const parent = element.parentNode;
    if (parent) {
      parent.classList.add('html-content-field');
    }
    
    // Create a preview of the HTML
    const preview = document.createElement('div');
    preview.className = 'html-content-preview';
    preview.innerHTML = value;
    
    // Add it after the value element
    element.parentNode.appendChild(preview);
  }
  
  /**
   * Open a modal dialog to edit HTML content
   * @param {Object} node JSONEditor node
   */
  function editHTMLContent(node) {
    // Get the current value
    const value = node.value || '';
    
    // Create modal backdrop
    const backdrop = document.createElement('div');
    backdrop.className = 'jsoneditor-modal-backdrop';
    backdrop.style.position = 'fixed';
    backdrop.style.top = '0';
    backdrop.style.left = '0';
    backdrop.style.width = '100%';
    backdrop.style.height = '100%';
    backdrop.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
    backdrop.style.zIndex = '9999';
    
    // Create modal dialog
    const modal = document.createElement('div');
    modal.className = 'jsoneditor-modal';
    modal.style.position = 'fixed';
    modal.style.top = '50%';
    modal.style.left = '50%';
    modal.style.transform = 'translate(-50%, -50%)';
    modal.style.width = '80%';
    modal.style.maxWidth = '800px';
    modal.style.backgroundColor = '#fff';
    modal.style.borderRadius = '4px';
    modal.style.boxShadow = '0 4px 8px rgba(0, 0, 0, 0.1)';
    modal.style.padding = '20px';
    modal.style.zIndex = '10000';
    
    // Create modal header
    const header = document.createElement('div');
    header.style.marginBottom = '15px';
    
    const title = document.createElement('h3');
    title.textContent = 'Edit HTML Content';
    title.style.margin = '0 0 10px 0';
    
    // Create textarea for editing
    const textarea = document.createElement('textarea');
    textarea.className = 'content-field-textarea';
    textarea.value = value;
    textarea.style.width = '100%';
    textarea.style.minHeight = '300px';
    
    // Create buttons container
    const buttons = document.createElement('div');
    buttons.style.marginTop = '15px';
    buttons.style.textAlign = 'right';
    
    // Cancel button
    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Cancel';
    cancelBtn.style.marginRight = '10px';
    cancelBtn.style.padding = '6px 12px';
    cancelBtn.style.backgroundColor = '#f5f5f5';
    cancelBtn.style.border = '1px solid #ddd';
    cancelBtn.style.borderRadius = '3px';
    cancelBtn.style.cursor = 'pointer';
    
    // Save button
    const saveBtn = document.createElement('button');
    saveBtn.textContent = 'Save';
    saveBtn.style.padding = '6px 12px';
    saveBtn.style.backgroundColor = '#337ab7';
    saveBtn.style.color = '#fff';
    saveBtn.style.border = '1px solid #2e6da4';
    saveBtn.style.borderRadius = '3px';
    saveBtn.style.cursor = 'pointer';
    
    // Assemble the modal
    header.appendChild(title);
    buttons.appendChild(cancelBtn);
    buttons.appendChild(saveBtn);
    
    modal.appendChild(header);
    modal.appendChild(textarea);
    modal.appendChild(buttons);
    
    backdrop.appendChild(modal);
    document.body.appendChild(backdrop);
    
    // Focus the textarea
    textarea.focus();
    
    // Close button event
    cancelBtn.addEventListener('click', function() {
      document.body.removeChild(backdrop);
    });
    
    // Save button event
    saveBtn.addEventListener('click', function() {
      // Update the node value
      node.update(textarea.value);
      
      // Remove the modal
      document.body.removeChild(backdrop);
    });
    
    // Close on backdrop click
    backdrop.addEventListener('click', function(e) {
      if (e.target === backdrop) {
        document.body.removeChild(backdrop);
      }
    });
  }
})(); 