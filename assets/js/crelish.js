$(document).ready(function () {

  var psL = new PerfectScrollbar('#cr-left-pane');
  var psR = new PerfectScrollbar('#cr-right-pane');

  $('.c-alerts__alert .c-button--close').on('click', function (e) {
    e.preventDefault();
    $(this).closest('.c-alerts__alert').hide();
  });

  $('.btn-cancel-proceed').on('click', function (e) {
    e.preventDefault();
    window.location.href = $(this).data('href');
  });

  $('td input[type="checkbox"]').on("click", function (e) {
    e.stopPropagation();
    return true;
  });

  $(document).on("click", ".open-back-modal", function () {
    var targetUrl = $(this).data('href');
    $(".btn-cancel-proceed").attr("data-href", targetUrl);
  });

  /*
  Split(['#cr-left-pane', '#cr-right-pane'], {
    sizes: [12, 88],
    minSize: [220, 440],
    gutterSize: 5
  })
  */
});
