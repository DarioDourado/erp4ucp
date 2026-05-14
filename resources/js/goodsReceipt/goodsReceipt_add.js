document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.$ === 'undefined') {
        return;
    }

    const $ = window.$;

    $('.select2').select2({
        placeholder: function () {
            return $(this).data('placeholder') || 'Selecione...';
        },
        allowClear: true,
        width: '100%'
    });

    function parseNumber(value) {
        if (typeof value === 'string') {
            value = value.replace(',', '.');
        }

        const parsed = parseFloat(value);
        return isNaN(parsed) ? 0 : parsed;
    }

    function formatMoney(value) {
        return new Intl.NumberFormat('pt-PT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(parseNumber(value));
    }

    function updateSupplierName() {
        const supplierName = $('#supplierCode option:selected').data('name') || '';
        $('#supplierName').val(supplierName);
    }

    function updateSelectPOButton() {
        const supplierCode = $.trim($('#supplierCode').val());
        const supplierGuideNumber = $.trim($('#supplierGuideNumber').val());
        const baseUrl = $('#btnSelectPO').attr('href') || '';

        if (!baseUrl.length) {
            return;
        }

        const cleanUrl = baseUrl.split('?')[0];
        const query = new URLSearchParams();

        query.set('supplierCode', supplierCode || '0');

        if (supplierGuideNumber.length) {
            query.set('supplierGuideNumber', supplierGuideNumber);
        }

        $('#btnSelectPO').attr('href', cleanUrl + '?' + query.toString());
    }

    function updateRowTotal($row) {
        const qty = parseNumber($row.find('.line-receive').val());
        const unitPrice = parseNumber($row.data('unit-price'));
        const lineTotal = qty * unitPrice;

        $row.find('.line-total-cell').text(formatMoney(lineTotal));

        return lineTotal;
    }

    function updateTotals() {
        let totalNet = 0;
        let totalTax = 0;
        const taxSummary = {};

        $('#goodsReceiptLinesTable tbody tr.receipt-line').each(function () {
            const $row = $(this);
            const pending = parseNumber($row.find('.line-receive').attr('max'));
            let receive = parseNumber($row.find('.line-receive').val());

            if (receive < 0) {
                receive = 0;
            }

            if (receive > pending) {
                receive = pending;
                $row.find('.line-receive').val(receive.toFixed(3));
            }

            const lineNet = updateRowTotal($row);
            const taxRate = parseNumber($row.data('tax-rate'));
            const taxCode = String($row.data('tax-code') || '');
            const lineTax = lineNet * (taxRate / 100);

            totalNet += lineNet;
            totalTax += lineTax;

            if (!taxSummary[taxCode]) {
                taxSummary[taxCode] = { taxRate: taxRate, taxAmount: 0 };
            }

            taxSummary[taxCode].taxAmount += lineTax;
        });

        $('#totalNetDisplay').text(formatMoney(totalNet));
        $('#totalTaxDisplay').text(formatMoney(totalTax));
        $('#totalGrossDisplay').text(formatMoney(totalNet + totalTax));

        const $taxBody = $('#taxSummaryTable tbody');
        $taxBody.empty();

        const taxRows = Object.keys(taxSummary).sort();

        if (!taxRows.length) {
            $taxBody.append(`
                <tr id="emptyTaxSummaryRow">
                    <td colspan="3" class="text-center text-muted py-3">
                        Sem linhas para calcular IVA.
                    </td>
                </tr>
            `);
            return;
        }

        taxRows.forEach(function (taxCode) {
            const row = taxSummary[taxCode];

            $taxBody.append(`
                <tr>
                    <td>${taxCode}</td>
                    <td>${formatMoney(row.taxRate)}%</td>
                    <td class="text-end">${formatMoney(row.taxAmount)}</td>
                </tr>
            `);
        });
    }

    $('#supplierCode').on('change', function () {
        updateSupplierName();
        updateSelectPOButton();
    });

    $('#supplierGuideNumber').on('input', function () {
        updateSelectPOButton();
    });

    $('#goodsReceiptLinesTable').on('input', '.line-receive', function () {
        updateTotals();
    });

    $('#goodsReceiptForm').on('submit', function (event) {
        if (!$('#purchaseOrderId').val()) {
            event.preventDefault();
            toastr.warning('Selecione uma encomenda para continuar.');
            return false;
        }

        const hasAnyReceive = $('#goodsReceiptLinesTable tbody .line-receive').toArray().some(function (input) {
            return parseNumber($(input).val()) > 0;
        });

        if (!hasAnyReceive) {
            event.preventDefault();
            toastr.warning('Indique pelo menos uma quantidade a receber superior a zero.');
            return false;
        }

        return true;
    });

    updateSupplierName();
    updateSelectPOButton();
    updateTotals();
});
