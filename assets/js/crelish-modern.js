/**
 * Crelish Modern UI Enhancement
 * Functions to dynamically enhance UI elements with modern styling
 */

(function($) {
    'use strict';

    /**
     * Apply modern styling to header bar
     */
    function applyModernHeaderStyling() {
        // Enhance the title in the header bar
        if ($('.navbar--controller').length) {
            // Check if there's a title in the controller
            if ($('.navbar--title').length === 0) {
                // Get the page title from the document title
                let pageTitle = document.title.split('|').pop().trim();
                
                // Always insert at the beginning of the left group
                // This works whether toggle button is visible or not
                $('.navbar--controller .group-content-filter').prepend(
                    '<h1 class="navbar--title">' + pageTitle + '</h1>'
                );
            }
            
            // Enhance buttons with icons if they don't have them
            $('.navbar--controller .c-button').each(function() {
                const $btn = $(this);
                const text = $btn.text().trim().toLowerCase();
                
                // Skip buttons that already have icons
                if ($btn.find('i, span.fui-arrow-left').length) return;
                
                // Add appropriate icons based on button text
                if (text.includes('save')) {
                    $btn.prepend('<i class="fa-solid fa-save"></i> ');
                } else if (text.includes('delete')) {
                    $btn.prepend('<i class="fa-solid fa-trash"></i> ');
                } else if (text.includes('back')) {
                    $btn.prepend('<i class="fa-solid fa-arrow-left"></i> ');
                } else if (text.includes('create') || text.includes('new') || text.includes('add')) {
                    $btn.prepend('<i class="fa-solid fa-plus"></i> ');
                } else if (text.includes('edit') || text.includes('update')) {
                    $btn.prepend('<i class="fa-solid fa-edit"></i> ');
                } else if (text.includes('view')) {
                    $btn.prepend('<i class="fa-solid fa-eye"></i> ');
                }
            });
        }
    }

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
     * Apply modern styling to sidebar
     */
    function applyModernSidebarStyling() {
        // Add text spans to navbar items for better structure
        $('#cr-left-pane .navbar-item a').each(function() {
            const $link = $(this);
            
            // Skip if already processed
            if ($link.find('span.nav-text').length > 0) return;
            
            // Get the icon and text
            const $icon = $link.find('i').first();
            const text = $link.text().trim();
            
            if ($icon.length && text) {
                // Clear the link content
                $link.empty();
                
                // Re-add the icon
                $link.append($icon);
                
                // Add the text in a span
                $link.append('<span class="nav-text">' + text + '</span>');
            }
        });
        
        // Fix the avatar element if needed
        if ($('#cr-left-pane .c-avatar').length && !$('#cr-left-pane .c-avatar').hasClass('modern-avatar')) {
            $('#cr-left-pane .c-avatar').addClass('modern-avatar');
        }
    }
    
    /**
     * Handle sidebar toggle functionality
     */
    function initSidebarToggle() {
        // Check if we're on mobile
        const isMobile = $(window).width() < 992;
        
        // Handle toggle click
        $('.toggle-sidenav').on('click', function() {
            if (isMobile) {
                $('body').toggleClass('sidebar-open');
            } else {
                $('body').toggleClass('sidebar-collapsed');
            }
        });
        
        // Close sidebar when clicking outside on mobile
        $(document).on('click', function(e) {
            if ($('body').hasClass('sidebar-open') && 
                !$(e.target).closest('#cr-left-pane').length && 
                !$(e.target).closest('.toggle-sidenav').length) {
                $('body').removeClass('sidebar-open');
            }
        });
        
        // Handle window resize
        $(window).on('resize', function() {
            const wasDesktop = !isMobile;
            const isNowMobile = $(window).width() < 992;
            
            // If we switched between desktop/mobile mode
            if (wasDesktop !== isNowMobile) {
                // Reset classes
                $('body').removeClass('sidebar-open sidebar-collapsed');
            }
        });
    }
    
    /**
     * Initialize all modern styling
     */
    function initModernStyling() {
        applyModernHeaderStyling();
        applyModernTableStyling();
        applyModernPaginationStyling();
        applyModernCardStyling();
        applyModernGridStyling();
        applyModernSidebarStyling();
        initSidebarToggle();
        
        // Re-apply on AJAX complete for dynamic content
        $(document).ajaxComplete(function() {
            setTimeout(function() {
                applyModernHeaderStyling();
                applyModernTableStyling();
                applyModernPaginationStyling();
                applyModernCardStyling();
                applyModernGridStyling();
                applyModernSidebarStyling();
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
        applyModernHeaderStyling: applyModernHeaderStyling,
        applyModernTableStyling: applyModernTableStyling,
        applyModernPaginationStyling: applyModernPaginationStyling,
        applyModernCardStyling: applyModernCardStyling,
        applyModernGridStyling: applyModernGridStyling,
        applyModernSidebarStyling: applyModernSidebarStyling,
        initSidebarToggle: initSidebarToggle
    };
    
})(jQuery); 