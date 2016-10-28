$(document).ready(function() {

  $('.o-panel--nav-top').perfectScrollbar();
  $('.c-alerts__alert .c-button--close').on('click',function(e){ e.preventDefault(); $(this).closest('.c-alerts__alert').hide(); });
});