$(document).ready(function () {

    $('.o-panel--nav-top').perfectScrollbar();
    $('.c-alerts__alert .c-button--close').on('click', function (e) {
        e.preventDefault();
        $(this).closest('.c-alerts__alert').hide();
    });

    $('.btn-cancel-ok').on('click', function (e) {
        e.preventDefault();
        window.location.href = $(this).data('href');
    });

    $('td input[type="checkbox"]').on("click", function (e) {
        e.stopPropagation();
        return true;
    })
});