$(document).ready(function () {

  var psL = new PerfectScrollbar('#cr-left-pane', {
    wheelSpeed: 2
  });
  var psR = new PerfectScrollbar('#cr-right-pane', {
    wheelSpeed: 2
  });

  if($('.filter-top').length > 0) {
    var psT = new PerfectScrollbar('.filter-top', {
      suppressScrollY: true
    });
  }

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

  $(document).on("click", ".toggle-sidenav", function () {
    $('#cr-left-pane').toggleClass('open');
    $('.menu-btn-4').toggleClass('active');
  });

  /*
  var gSizes = sessionStorage.getItem('split-sizes');

  if (gSizes) {
    gSizes = JSON.parse(gSizes)
  } else {
    gSizes = [12, 88] // default sizes
  }

  Split(['#cr-left-pane', '#cr-right-pane'], {
    sizes: gSizes,
    minSize: [220, 440],
    gutterSize: 3,
    onDragEnd: function (sizes) {
      sessionStorage.setItem('split-sizes', JSON.stringify(sizes))
    },
  });*/
});
