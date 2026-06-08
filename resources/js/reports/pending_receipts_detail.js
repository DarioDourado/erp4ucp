document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.$ === 'undefined') {
        return;
    }

    const $ = window.$;

    if ($('#datatable').length) {
        $('#datatable').DataTable({
            responsive: true,
            paging: true,
            searching: true,
            ordering: true,
            info: true,
            lengthChange: true,
            autoWidth: false
        });
    }
});
