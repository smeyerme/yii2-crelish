/**
 * Relation Selector - jQuery implementation
 * This component replaces the Vue-based implementation with jQuery and Select2
 */
(function($) {
  'use strict';

  // Initialize when DOM is ready
  $(document).ready(function() {
    // Initialize all relation selector containers
    $('.relation-selector-container').each(function() {
      initializeRelationSelector($(this));
    });
  });

  /**
   * Initialize a single relation selector
   * @param {JQuery} container - The container element
   */
  function initializeRelationSelector(container) {
    // Get configuration from data attributes
    const config = {
      fieldKey: container.data('field-key'),
      contentType: container.data('content-type'),
      value: container.data('value'),
      inputName: container.data('input-name'),
      label: container.data('label') || '',
      required: container.data('required') === 'true',
      isMultiple: container.data('multiple') === 'true',
      columns: container.data('columns') || [{ key: 'systitle', label: 'Titel' }],
      filterFields: container.data('filter-fields') || ['systitle']
    };

    // Get translations
    const translations = window.relationSelectorTranslations || {};
    
    // Create the HTML structure
    createHtmlStructure(container, config, translations);
    
    // Initialize Select2 and other behaviors
    if (config.isMultiple) {
      initializeMultipleMode(container, config, translations);
    } else {
      initializeSingleMode(container, config, translations);
    }
  }

  /**
   * Create the HTML structure for the component
   * @param {JQuery} container - The container element
   * @param {Object} config - Configuration object
   * @param {Object} translations - Translations object
   */
  function createHtmlStructure(container, config, translations) {
    let html = `
      <div class="relation-selector ${config.required ? 'required' : ''}">
        ${config.label ? `<div class="relation-selector-label form-label has-star">${config.label}</div>` : ''}
    `;

    // Add single or multiple mode HTML
    if (!config.isMultiple) {
      html += `
        <div class="single-relation-mode">
          <select class="single-relation-select form-control" name="${config.inputName}-select">
            <option value="">${translations.choosePlaceholder || 'Bitte wählen...'}</option>
          </select>
        </div>
      `;
    } else {
      html += `
        <div class="multiple-relation-mode">
          <div class="selection-interface mb-3">
            <div class="row">
              <div class="col-10">
                <select class="multiple-relation-select form-control" name="${config.inputName}-select">
                  <option value="">${translations.choosePlaceholder || 'Bitte wählen...'}</option>
                </select>
              </div>
              <div class="col-2">
                <button type="button" class="btn btn-primary add-button">
                  <i class="fa fa-plus"></i> ${translations.addButton || 'Hinzufügen'}
                </button>
              </div>
            </div>
          </div>
          
          <div class="selected-items-container mt-3" style="display: none;">
            <h6>${translations.assignedItems || 'Zugeordnete Einträge'}</h6>
            <div class="table-responsive">
              <table class="table crelish-list">
                <thead>
                  <tr>
                    ${config.columns.map(column => `<th>${column.label}</th>`).join('')}
                    <th>${translations.actions || 'Aktionen'}</th>
                  </tr>
                </thead>
                <tbody class="selected-items-list"></tbody>
              </table>
            </div>
          </div>
          
          <div class="no-items-message">
            ${translations.noItemsSelected || 'Keine Einträge ausgewählt'}
          </div>
        </div>
      `;
    }

    // Add hidden input to store the actual value
    html += `
        <input type="hidden" name="${config.inputName}" class="relation-value-input" value="${config.value || ''}">
      </div>
    `;

    // Set the HTML
    container.html(html);
  }

  /**
   * Initialize the component in single selection mode
   * @param {JQuery} container - The container element
   * @param {Object} config - Configuration object
   * @param {Object} translations - Translations object
   */
  function initializeSingleMode(container, config, translations) {
    const selectElement = container.find('.single-relation-select');
    const hiddenInput = container.find('.relation-value-input');
    
    // Initialize Select2 with remote data
    selectElement.select2({
      theme: 'bootstrap',
      allowClear: !config.required,
      placeholder: translations.choosePlaceholder || 'Bitte wählen...',
      ajax: {
        url: `/crelish-api/content/${config.contentType}`,
        dataType: 'json',
        delay: 350,
        data: function(params) {
          const filterData = { page: params.page || 1 };
          
          // Add filter if we have a search term
          if (params.term) {
            filterData.filter = config.filterFields
              .map(field => `${field}:${params.term}`)
              .join('&');
          }
          
          return filterData;
        },
        processResults: function(data, params) {
          params.page = params.page || 1;
          
          let results = [];
          if (data.success && data.data && data.data.items) {
            results = data.data.items.map(item => ({
              id: item.uuid,
              text: item.systitle || item.title || item.name || item.uuid,
              data: item
            }));
          }
          
          return {
            results: results,
            pagination: {
              more: (params.page * 20) < (data.data?.total || 0)
            }
          };
        },
        cache: true
      },
      minimumInputLength: 0
    });
    
    // Handle selection change
    selectElement.on('select2:select', function(e) {
      const selectedId = e.params.data.id;
      hiddenInput.val(selectedId);
      
      // Trigger change event for form validation
      hiddenInput.trigger('change');
    });
    
    // Handle clearing
    selectElement.on('select2:clear', function() {
      hiddenInput.val('');
      hiddenInput.trigger('change');
    });
    
    // Load initial value if any
    if (config.value && config.value !== '[]' && config.value !== '{}') {
      let initialId = config.value;
      
      // Try to parse as JSON if it looks like JSON
      if (config.value.startsWith('[') || config.value.startsWith('{')) {
        try {
          const parsed = JSON.parse(config.value);
          if (Array.isArray(parsed) && parsed.length > 0) {
            initialId = parsed[0];
          } else if (parsed && typeof parsed === 'object' && parsed.uuid) {
            initialId = parsed.uuid;
          }
        } catch (e) {
          // Not valid JSON, use as is
          console.warn('Failed to parse initial value as JSON', e);
        }
      }
      
      // Only fetch if we have a valid UUID
      if (initialId && typeof initialId === 'string' && initialId.trim() !== '') {
        $.ajax({
          url: `/crelish-api/content/${config.contentType}/${initialId}`,
          method: 'GET',
          success: function(response) {
            if (response.success && response.data) {
              const item = response.data;
              const option = new Option(
                item.systitle || item.title || item.name || item.uuid,
                item.uuid,
                true,
                true
              );
              selectElement.append(option).trigger('change');
              hiddenInput.val(item.uuid);
            }
          },
          error: function(xhr, status, error) {
            console.error('Error loading initial item:', error);
          }
        });
      }
    }
  }

  /**
   * Initialize the component in multiple selection mode
   * @param {JQuery} container - The container element 
   * @param {Object} config - Configuration object
   * @param {Object} translations - Translations object
   */
  function initializeMultipleMode(container, config, translations) {
    const selectElement = container.find('.multiple-relation-select');
    const hiddenInput = container.find('.relation-value-input');
    const addButton = container.find('.add-button');
    const selectedItemsList = container.find('.selected-items-list');
    const selectedItemsContainer = container.find('.selected-items-container');
    const noItemsMessage = container.find('.no-items-message');
    
    // Array to store selected items
    let selectedItems = [];
    
    // Initialize Select2 with remote data
    selectElement.select2({
      theme: 'bootstrap',
      allowClear: true,
      placeholder: translations.choosePlaceholder || 'Bitte wählen...',
      ajax: {
        url: `/crelish-api/content/${config.contentType}`,
        dataType: 'json',
        delay: 350,
        data: function(params) {
          const filterData = { page: params.page || 1 };
          
          // Add filter if we have a search term
          if (params.term) {
            filterData.filter = config.filterFields
              .map(field => `${field}:${params.term}`)
              .join('&');
          }
          
          return filterData;
        },
        processResults: function(data, params) {
          params.page = params.page || 1;
          
          let results = [];
          if (data.success && data.data && data.data.items) {
            results = data.data.items.map(item => ({
              id: item.uuid,
              text: item.systitle || item.title || item.name || item.uuid,
              data: item
            }));
          }
          
          return {
            results: results,
            pagination: {
              more: (params.page * 20) < (data.data?.total || 0)
            }
          };
        },
        cache: true
      },
      minimumInputLength: 0
    });
    
    // Handle add button click
    addButton.on('click', function() {
      const selectedOption = selectElement.select2('data')[0];
      if (!selectedOption) return;
      
      // Check if the item is already in the list
      const exists = selectedItems.some(item => item.uuid === selectedOption.id);
      if (exists) {
        alert(translations.itemAlreadyAdded || 'Dieser Eintrag wurde bereits hinzugefügt');
        return;
      }
      
      // Add the item
      if (selectedOption.data) {
        // We already have the full data from Select2
        addItemToList(selectedOption.data);
      } else {
        // Fetch the full data
        $.ajax({
          url: `/crelish-api/content/${config.contentType}/${selectedOption.id}`,
          method: 'GET',
          success: function(response) {
            if (response.success && response.data) {
              addItemToList(response.data);
            }
          },
          error: function(xhr, status, error) {
            console.error('Error fetching item:', error);
            alert('Error fetching data from API');
          }
        });
      }
      
      // Clear the selection
      selectElement.val(null).trigger('change');
    });
    
    // Function to add an item to the list
    function addItemToList(item) {
      // Add to our array
      selectedItems.push(item);
      
      // Create table row
      const row = $('<tr>').attr('data-id', item.uuid);
      
      // Add data columns
      config.columns.forEach(column => {
        const value = getNestedProperty(item, column.key) || '';
        row.append($('<td>').text(value));
      });
      
      // Add action buttons
      const actionsCell = $('<td class="actions">');
      
      // Add up/down buttons
      actionsCell.append(
        $('<button type="button" class="c-button u-small move-up" title="Nach oben">')
          .html('<i class="fa fa-arrow-up"></i>')
          .css('display', 'none') // Initially hidden, will show/hide as needed
      );
      
      actionsCell.append(
        $('<button type="button" class="c-button u-small move-down" title="Nach unten">')
          .html('<i class="fa fa-arrow-down"></i>')
          .css('display', 'none') // Initially hidden, will show/hide as needed
      );
      
      // Add delete button
      actionsCell.append(
        $('<button type="button" class="c-button u-small remove-item" title="Löschen">')
          .html('<i class="fa-sharp fa-regular fa-trash"></i>')
      );
      
      row.append(actionsCell);
      
      // Add to the table
      selectedItemsList.append(row);
      
      // Show the table if it's the first item
      if (selectedItems.length === 1) {
        selectedItemsContainer.show();
        noItemsMessage.hide();
      }
      
      // Update up/down buttons for all rows
      updateMoveButtons();
      
      // Update hidden input
      updateHiddenInput();
    }
    
    // Function to remove an item from the list
    function removeItem(uuid) {
      // Remove from our array
      selectedItems = selectedItems.filter(item => item.uuid !== uuid);
      
      // Remove from the table
      selectedItemsList.find(`tr[data-id="${uuid}"]`).remove();
      
      // Hide the table if there are no items
      if (selectedItems.length === 0) {
        selectedItemsContainer.hide();
        noItemsMessage.show();
      }
      
      // Update up/down buttons for all rows
      updateMoveButtons();
      
      // Update hidden input
      updateHiddenInput();
    }
    
    // Function to move an item up in the list
    function moveItemUp(uuid) {
      const index = selectedItems.findIndex(item => item.uuid === uuid);
      if (index > 0) {
        // Swap in the array
        const temp = selectedItems[index - 1];
        selectedItems[index - 1] = selectedItems[index];
        selectedItems[index] = temp;
        
        // Swap in the DOM
        const row = selectedItemsList.find(`tr[data-id="${uuid}"]`);
        row.insertBefore(row.prev());
        
        // Update up/down buttons
        updateMoveButtons();
        
        // Update hidden input
        updateHiddenInput();
      }
    }
    
    // Function to move an item down in the list
    function moveItemDown(uuid) {
      const index = selectedItems.findIndex(item => item.uuid === uuid);
      if (index < selectedItems.length - 1) {
        // Swap in the array
        const temp = selectedItems[index + 1];
        selectedItems[index + 1] = selectedItems[index];
        selectedItems[index] = temp;
        
        // Swap in the DOM
        const row = selectedItemsList.find(`tr[data-id="${uuid}"]`);
        row.insertAfter(row.next());
        
        // Update up/down buttons
        updateMoveButtons();
        
        // Update hidden input
        updateHiddenInput();
      }
    }
    
    // Function to update the move buttons visibility
    function updateMoveButtons() {
      const rows = selectedItemsList.find('tr');
      
      // Hide all buttons first
      rows.find('.move-up, .move-down').hide();
      
      // If there's only one item, no need for move buttons
      if (rows.length <= 1) return;
      
      // Show move up button for all except the first
      rows.slice(1).find('.move-up').show();
      
      // Show move down button for all except the last
      rows.slice(0, -1).find('.move-down').show();
    }
    
    // Function to update the hidden input value
    function updateHiddenInput() {
      // Get just the UUIDs for storage
      const uuids = selectedItems.map(item => item.uuid);
      hiddenInput.val(JSON.stringify(uuids));
      
      // Trigger change event for form validation
      hiddenInput.trigger('change');
    }
    
    // Handle click events for the buttons
    selectedItemsList.on('click', '.remove-item', function() {
      const uuid = $(this).closest('tr').data('id');
      removeItem(uuid);
    });
    
    selectedItemsList.on('click', '.move-up', function() {
      const uuid = $(this).closest('tr').data('id');
      moveItemUp(uuid);
    });
    
    selectedItemsList.on('click', '.move-down', function() {
      const uuid = $(this).closest('tr').data('id');
      moveItemDown(uuid);
    });
    
    // Load initial items if any
    if (config.value && config.value !== '[]' && config.value !== '{}') {
      let initialIds = [];
      
      // Try to parse as JSON
      try {
        const parsed = JSON.parse(config.value);
        if (Array.isArray(parsed)) {
          initialIds = parsed.filter(id => id && typeof id === 'string' && id.trim() !== '');
        } else if (parsed && typeof parsed === 'object' && parsed.uuid) {
          initialIds = [parsed.uuid];
        }
      } catch (e) {
        // Not valid JSON, try as a single value
        if (config.value && typeof config.value === 'string' && config.value.trim() !== '') {
          initialIds = [config.value];
        }
      }
      
      // Fetch each item
      if (initialIds.length > 0) {
        // Show loading indicator
        noItemsMessage.text(translations.loadingOptions || 'Lade Optionen...');
        
        // Create a counter to track when all items are loaded
        let loadedCount = 0;
        
        initialIds.forEach(id => {
          $.ajax({
            url: `/crelish-api/content/${config.contentType}/${id}`,
            method: 'GET',
            success: function(response) {
              if (response.success && response.data) {
                addItemToList(response.data);
              }
              
              // Update counter
              loadedCount++;
              
              // If we're done loading, reset the message
              if (loadedCount === initialIds.length) {
                noItemsMessage.text(translations.noItemsSelected || 'Keine Einträge ausgewählt');
              }
            },
            error: function(xhr, status, error) {
              console.error('Error loading initial item:', error);
              
              // Update counter
              loadedCount++;
              
              // If we're done loading, reset the message
              if (loadedCount === initialIds.length) {
                noItemsMessage.text(translations.noItemsSelected || 'Keine Einträge ausgewählt');
              }
            }
          });
        });
      }
    }
  }
  
  /**
   * Helper function to get a nested property from an object
   * @param {Object} obj - The object to get the property from
   * @param {string} path - The path to the property, can use dot notation
   * @returns {*} - The value of the property, or empty string if not found
   */
  function getNestedProperty(obj, path) {
    if (!obj || !path) return '';
    
    // Handle nested properties with dot notation
    if (path.includes('.')) {
      let value = obj;
      const parts = path.split('.');
      
      for (const part of parts) {
        if (value && typeof value === 'object' && part in value) {
          value = value[part];
        } else {
          return '';
        }
      }
      
      return value;
    }
    
    return obj[path] || '';
  }

})(jQuery);