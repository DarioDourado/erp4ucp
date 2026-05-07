document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.$ === 'undefined') {
        return;
    }

    const $ = window.$;
    const filterElement = document.getElementById('purchaseOrderSatisfactionFilter');

    if ($('#datatable').length) {
        $.fn.dataTable.ext.search.push(function (settings, _data, dataIndex) {
            if (settings.nTable !== $('#datatable').get(0)) {
                return true;
            }

            if (!filterElement || filterElement.value === 'all') {
                return true;
            }

            const rowNode = settings.aoData[dataIndex] ? settings.aoData[dataIndex].nTr : null;
            const satisfactionFilter = rowNode ? rowNode.getAttribute('data-satisfaction-filter') : 'all';

            return satisfactionFilter === filterElement.value;
        });

        const table = $('#datatable').DataTable({
            responsive: true,
            paging: true,
            searching: true,
            ordering: true,
            info: true,
            lengthChange: true,
            autoWidth: false
        });

        if (filterElement) {
            filterElement.addEventListener('change', () => {
                table.draw();
            });
        }
    }
});
