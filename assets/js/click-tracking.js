/**
 * Crelish Click Tracking
 *
 * Reliable click tracking using Beacon API with fetch fallback.
 * More reliable than HTML ping attribute.
 */
(function() {
  'use strict';

  /**
   * Track a click event
   * @param {string} url - The tracking URL
   */
  function trackClick(url) {
    // Try Beacon API first (most reliable for navigation)
    if (navigator.sendBeacon) {
      try {
        navigator.sendBeacon(url);
        return;
      } catch (e) {
        console.warn('Beacon API failed, falling back to fetch:', e);
      }
    }

    // Fallback to fetch with keepalive
    if (window.fetch) {
      try {
        fetch(url, {
          method: 'POST',
          keepalive: true, // Ensures request completes even if page unloads
          mode: 'no-cors'
        }).catch(function() {
          // Silently fail - tracking shouldn't break user experience
        });
        return;
      } catch (e) {
        console.warn('Fetch failed, falling back to image:', e);
      }
    }

    // Last resort: Image pixel (works everywhere)
    try {
      new Image().src = url;
    } catch (e) {
      // If even this fails, give up silently
    }
  }

  /**
   * Initialize click tracking on page load
   */
  function init() {
    // Find all links with data-track-click attribute
    var links = document.querySelectorAll('a[data-track-click]');

    links.forEach(function(link) {
      link.addEventListener('click', function(e) {
        var trackUrl = link.getAttribute('data-track-click');

        if (trackUrl) {
          trackClick(trackUrl);
        }
      });
    });
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose trackClick globally for manual tracking
  window.CrelishClickTracking = {
    track: trackClick
  };
})();