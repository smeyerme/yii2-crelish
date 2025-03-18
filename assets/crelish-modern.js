/**
 * Crelish Modern UI Enhancement
 * Functions to dynamically enhance UI elements with modern styling
 */

(function($) {
    'use strict';

    /**
     * Apply modern styling to tables
     */
    function applyModernTableStyling() {
        // Add modern styling class to tables that need enhancement
        $('.table').not('.no-modern').addClass('table-modern');
        
        // Convert status text to badges
        $('.table-modern td:contains("online"), .table-modern td:contains("offline"), .table-modern td:contains("draft"), .table-modern td:contains("archived")').each(function() {
            var text = $(this).text().trim().toLowerCase();
            if (text === 'online' || text === 'offline' || text === 'draft' || text === 'archived') {
                $(this).html('<span class="status-badge ' + text + '">' + text + '</span>');
            }
        });
        
        // Convert file type text to badges
        $('.table-modern td:contains("image"), .table-modern td:contains("pdf"), .table-modern td:contains("audio"), .table-modern td:contains("video"), .table-modern td:contains("archive")').each(function() {
            var text = $(this).text().trim().toLowerCase();
            if (text === 'image' || text === 'pdf' || text === 'audio' || text === 'video' || text === 'archive') {
                $(this).html('<span class="file-type-tag ' + text + '">' + text + '</span>');
            }
        });
        
        // Enhance action buttons
        $('.table-modern .btn-primary').addClass('btn-primary-modern');
        $('.table-modern .btn-danger').addClass('btn-danger-modern');
        $('.table-modern .btn-warning').addClass('btn-warning-modern');
        $('.table-modern .btn-info').addClass('btn-info-modern');
        $('.table-modern .btn-success').addClass('btn-success-modern');
    }
    
    /**
     * Apply modern styling to pagination
     */
    function applyModernPaginationStyling() {
        // Add modern styling class to pagination elements
        $('.pagination').not('.no-modern').addClass('pagination-modern');
        
        // Enhance summary text
        $('.summary').not('.no-modern').addClass('summary-modern');
    }
    
    /**
     * Apply modern styling to card elements
     */
    function applyModernCardStyling() {
        // For default cards
        $('.card, .panel').not('.no-modern').addClass('card-modern');
        
        // Create placeholder icons for cards without images
        $('.card-modern .card-placeholder').each(function() {
            if ($(this).children().length === 0) {
                var icon = $('<i>').addClass('card-icon fa fa-file');
                $(this).append(icon);
            }
        });
    }
    
    /**
     * Apply modern grid layout
     */
    function applyModernGridStyling() {
        // Add modern styling class to grid containers
        $('.grid-view, .list-view').not('.no-modern').find('.row').addClass('grid-modern');
    }
    
    /**
     * Initialize all modern styling
     */
    function initModernStyling() {
        applyModernTableStyling();
        applyModernPaginationStyling();
        applyModernCardStyling();
        applyModernGridStyling();
        
        // Re-apply on AJAX complete for dynamic content
        $(document).ajaxComplete(function() {
            setTimeout(function() {
                applyModernTableStyling();
                applyModernPaginationStyling();
                applyModernCardStyling();
                applyModernGridStyling();
            }, 100);
        });
    }
    
    // Run when document is ready
    $(document).ready(function() {
        initModernStyling();
    });
    
    // Expose functions to global scope if needed
    window.CrelishModern = {
        initModernStyling: initModernStyling,
        applyModernTableStyling: applyModernTableStyling,
        applyModernPaginationStyling: applyModernPaginationStyling,
        applyModernCardStyling: applyModernCardStyling,
        applyModernGridStyling: applyModernGridStyling
    };
    
})(jQuery); 