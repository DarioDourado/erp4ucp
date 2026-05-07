export function initPurchaseOrderForm(rootSelector = '#purchaseOrderFormPage') {
    const root = document.querySelector(rootSelector);

    if (!root || typeof window.$ === 'undefined') {
        return;
    }

    const $ = window.$;
    const products = JSON.parse(root.dataset.products || '[]');
    const initialLines = JSON.parse(root.dataset.initialLines || '[]');
    const productIndex = {};
    let lineIndex = 0;

    products.forEach(function (product) {
        productIndex[product.code] = product;
    });

    $('.select2').select2({
        placeholder: function () {
            return $(this).data('placeholder') || 'Selecione...';
        },
        allowClear: true,
        width: '100%'
    });

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

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

    function formatInputNumber(value, decimals) {
        return parseNumber(value).toFixed(decimals);
    }

    function normalizeFamilyKey(value) {
        return String(value ?? '')
            .trim()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .toLocaleLowerCase('pt-PT');
    }

    function extractFamilyCode(value) {
        const normalized = normalizeFamilyKey(value);

        if (!normalized) {
            return '';
        }

        const firstPart = normalized.split('-')[0].trim();
        const firstToken = firstPart.split(' ')[0].trim();

        return firstToken;
    }

    function familyMatches(productFamilyValue, selectedFamilyValue) {
        const productKey = normalizeFamilyKey(productFamilyValue);
        const selectedKey = normalizeFamilyKey(selectedFamilyValue);

        if (!productKey || !selectedKey) {
            return false;
        }

        if (productKey === selectedKey) {
            return true;
        }

        const productCode = extractFamilyCode(productFamilyValue);
        const selectedCode = extractFamilyCode(selectedFamilyValue);

        if (productCode && selectedCode && productCode === selectedCode) {
            return true;
        }

        // Fallback for text variations like "01 - Lacteos" vs "01 Lacteos".
        return productKey.includes(selectedKey) || selectedKey.includes(productKey);
    }

    function updateSupplierName() {
        const supplierName = $('#supplierCode option:selected').data('name') || '';
        $('#supplierName').val(supplierName);
    }

    function updateStockValue() {
        const productCode = $('#productPicker').val();
        const product = productIndex[productCode];
        const stockQuantity = product ? parseNumber(product.stockQuantity) : 0;

        $('#stockValue').val(formatInputNumber(stockQuantity, 3));
    }

    function focusAndSelect($element) {
        if (!$element || !$element.length) {
            return;
        }

        window.setTimeout(function () {
            $element.trigger('focus');
            if (typeof $element[0].select === 'function') {
                $element[0].select();
            }
        }, 0);
    }

    function openProductPicker() {
        const $productPicker = $('#productPicker');

        if ($productPicker.prop('disabled')) {
            return;
        }

        $productPicker.select2('open');
    }

    function headerIsReady() {
        return $.trim($('#pONumber').val()) !== ''
            && $.trim($('#pODate').val()) !== ''
            && $.trim($('#supplierCode').val()) !== '';
    }

    function updateHeaderState() {
        const isReady = headerIsReady();
        const hasProduct = $.trim($('#productPicker').val()) !== '';
        const hasFamily = $.trim($('#familyPicker').val()) !== '';
        const hasProductOptions = $('#productPicker option').length > 1;

        $('#btnAddLine').prop('disabled', !(isReady && hasProduct));
        $('#productPicker').prop('disabled', !hasFamily || !hasProductOptions);

        if (isReady) {
            $('#headerReadyBadge')
                .removeClass('bg-warning text-dark')
                .addClass('bg-success')
                .text('Cabeçalho válido');
            $('#headerInfoAlert').removeClass('alert-info').addClass('alert-success')
                .text('Cabeçalho preenchido. Já pode adicionar linhas à encomenda.');
        } else {
            $('#headerReadyBadge')
                .removeClass('bg-success')
                .addClass('bg-warning text-dark')
                .text('Preencha o cabeçalho para adicionar linha');
            $('#headerInfoAlert').removeClass('alert-success').addClass('alert-info')
                .text('Pode selecionar família e artigo, mas só pode adicionar linhas depois de preencher P.O. Nº, Data e Fornecedor.');
        }
    }

    function populateProducts(selectedCode = '') {
        const family = $('#familyPicker').val();
        const filteredProducts = products.filter(function (product) {
            const productFamilyValue = product.family ?? product.familyCode ?? product.productFamily;
            return familyMatches(productFamilyValue, family);
        });

        const $productPicker = $('#productPicker');
        let options = '<option value=""></option>';

        filteredProducts.forEach(function (product) {
            const isSelected = selectedCode && product.code === selectedCode ? ' selected' : '';
            options += '<option value="' + escapeHtml(product.code) + '"' + isSelected + '>'
                + escapeHtml(product.code + ' - ' + product.description)
                + '</option>';
        });

        $productPicker.html(options);
        $productPicker.prop('disabled', !family || filteredProducts.length === 0);

        if (!selectedCode) {
            $productPicker.val('').trigger('change');
        } else {
            $productPicker.val(selectedCode).trigger('change');
        }
    }

    function updateLineNumbers() {
        $('#orderLinesTable tbody tr.order-line').each(function (index) {
            $(this).find('.line-number').text(index + 1);
        });
    }

    function toggleEmptyLineRow() {
        const hasLines = $('#orderLinesTable tbody tr.order-line').length > 0;
        $('#emptyLineRow').toggle(!hasLines);
    }

    function renderTaxSummary(summaryMap) {
        const $tbody = $('#taxSummaryTable tbody');
        $tbody.empty();

        const rows = Object.values(summaryMap).sort(function (a, b) {
            return String(a.code).localeCompare(String(b.code), 'pt');
        });
        let totalTaxAmount = 0;

        if (!rows.length) {
            $tbody.append(`
                <tr id="emptyTaxSummaryRow">
                    <td colspan="3" class="text-center text-muted py-3">
                        Sem linhas para calcular IVA.
                    </td>
                </tr>
            `);
            return;
        }

        rows.forEach(function (row) {
            totalTaxAmount += parseNumber(row.amount);
            $tbody.append(`
                <tr>
                    <td>${escapeHtml(row.code || '-')}</td>
                    <td>${formatMoney(row.rate)}%</td>
                    <td class="text-end">${formatMoney(row.amount)}</td>
                </tr>
            `);
        });

        $tbody.append(`
            <tr class="vat-summary-total-row">
                <td colspan="2">Total IVA</td>
                <td class="text-end">${formatMoney(totalTaxAmount)}</td>
            </tr>
        `);
    }

    function updateRowTotal($row) {
        const quantity = parseNumber($row.find('.line-quantity').val());
        const unitPrice = parseNumber($row.find('.line-unit-price').val());
        const lineTotal = quantity * unitPrice;

        $row.attr('data-line-total', lineTotal.toFixed(2));
        $row.find('.line-total-cell').text(formatMoney(lineTotal));
    }

    function updateTotals() {
        let totalNet = 0;
        let totalTax = 0;
        const taxSummary = {};

        $('#orderLinesTable tbody tr.order-line').each(function () {
            const $row = $(this);
            updateRowTotal($row);

            const lineTotal = parseNumber($row.attr('data-line-total'));
            const taxCode = String($row.data('tax-code') ?? '');
            const taxRate = parseNumber($row.data('tax-rate'));
            const taxAmount = lineTotal * (taxRate / 100);

            totalNet += lineTotal;
            totalTax += taxAmount;

            if (!taxSummary[taxCode]) {
                taxSummary[taxCode] = {
                    code: taxCode,
                    rate: taxRate,
                    amount: 0
                };
            }

            taxSummary[taxCode].amount += taxAmount;
        });

        const financialDiscount = parseNumber($('#financialDiscount').val());
        const totalGross = Math.max((totalNet + totalTax) - financialDiscount, 0);

        $('#totalNetDisplay').text(formatMoney(totalNet));
        $('#totalTaxDisplay').text(formatMoney(totalTax));
        $('#totalGrossDisplay').text(formatMoney(totalGross));

        renderTaxSummary(taxSummary);
        toggleEmptyLineRow();
        updateLineNumbers();
    }

    function addLine(product, quantity = 1, unitPrice = 0) {
        if (!product) {
            return;
        }

        const currentIndex = lineIndex++;
        const rowHtml = `
            <tr class="order-line"
                data-tax-code="${escapeHtml(product.taxRateCode)}"
                data-tax-rate="${escapeHtml(product.taxRate)}">
                <td class="line-number text-center"></td>
                <td>
                    <div class="line-product-inline">
                        <span class="line-product-code">${escapeHtml(product.code)}</span>
                        <span class="line-product-description">${escapeHtml(product.description || '')}</span>
                    </div>
                    <input type="hidden" name="lines[${currentIndex}][productCode]" value="${escapeHtml(product.code)}">
                </td>
                <td>${escapeHtml(product.unit || '')}</td>
                <td>
                    <input type="text" class="form-control form-control-sm text-end bg-light" value="${formatInputNumber(product.stockQuantity || 0, 3)}" readonly>
                </td>
                <td>
                    <input type="number"
                           step="0.001"
                           min="0.001"
                           name="lines[${currentIndex}][quantity]"
                           class="form-control form-control-sm text-end line-quantity"
                           value="${formatInputNumber(quantity, 3)}">
                </td>
                <td>
                    <input type="number"
                           step="0.01"
                           min="0"
                           name="lines[${currentIndex}][unitPrice]"
                           class="form-control form-control-sm text-end line-unit-price"
                           value="${formatInputNumber(unitPrice, 2)}">
                </td>
                <td class="text-end line-tax-cell">
                    <span class="fw-semibold">${escapeHtml(product.taxRateCode || '')}</span>
                    <span class="text-muted small ms-1">${formatMoney(product.taxRate)}%</span>
                </td>
                <td class="text-end line-total-cell">0,00</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-line">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            </tr>
        `;

        $('#orderLinesTable tbody').append(rowHtml);
        const $newRow = $('#orderLinesTable tbody tr.order-line').last();
        updateTotals();

        focusAndSelect($newRow.find('.line-quantity'));

        return $newRow;
    }

    $('#supplierCode').on('change', function () {
        updateSupplierName();
        updateHeaderState();
        $(this).removeClass('is-invalid');
        $(this).next('.select2-container').find('.select2-selection').removeClass('is-invalid');
    });

    $('#pONumber, #pODate').on('input change', function () {
        updateHeaderState();
        $(this).removeClass('is-invalid');
    });

    $('#familyPicker').on('change', function () {
        populateProducts();
        updateStockValue();
        updateHeaderState();
    });

    $('#productPicker').on('change', function () {
        updateStockValue();
        updateHeaderState();
    });

    $('#btnAddLine').on('click', function () {
        if (!headerIsReady()) {
            return;
        }

        const productCode = $('#productPicker').val();
        const product = productIndex[productCode];

        if (!product) {
            return;
        }

        addLine(product, 1, 0);
        $('#productPicker').val('').trigger('change');
        updateStockValue();
        updateHeaderState();
    });

    $('#orderLinesTable').on('input', '.line-quantity, .line-unit-price', function () {
        updateTotals();
    });

    $('#orderLinesTable').on('click', '.btn-remove-line', function () {
        const $row = $(this).closest('tr');

        Swal.fire({
            title: 'Tem a certeza?',
            text: 'Vai remover esta linha da encomenda.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Sim, remover!',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                $row.remove();
                updateTotals();
            }
        });
    });

    $('#financialDiscount').on('input', function () {
        updateTotals();
        $(this).removeClass('is-invalid');
    });

    $('#myForm').on('keydown', 'input, select', function (event) {
        if (event.key !== 'Enter' || $(event.target).is('textarea')) {
            return;
        }

        event.preventDefault();

        const $target = $(event.target);

        if ($target.hasClass('line-quantity')) {
            focusAndSelect($target.closest('tr').find('.line-unit-price'));
            return;
        }

        if ($target.hasClass('line-unit-price')) {
            openProductPicker();
            return;
        }

        if ($target.attr('id') === 'pONumber') {
            focusAndSelect($('#pODate'));
            return;
        }

        if ($target.attr('id') === 'pODate') {
            $('#supplierCode').select2('open');
        }
    });

    $('#myForm').validate({
        ignore: [],
        rules: {
            pONumber: { required: true, number: true },
            pODate: { required: true },
            supplierCode: { required: true },
            financialDiscount: { number: true, min: 0 }
        },
        messages: {
            pONumber: {
                required: 'Introduza o n.º da encomenda.',
                number: 'O n.º da encomenda tem de ser numérico.'
            },
            pODate: {
                required: 'Introduza a data da encomenda.'
            },
            supplierCode: {
                required: 'Selecione o fornecedor.'
            },
            financialDiscount: {
                number: 'O desconto financeiro tem de ser numérico.',
                min: 'O desconto financeiro não pode ser negativo.'
            }
        },
        errorElement: 'span',
        errorPlacement: function (error, element) {
            error.addClass('invalid-feedback');

            if (element.hasClass('select2-hidden-accessible')) {
                element.next('.select2-container').after(error);
            } else {
                element.closest('.form-group').append(error);
            }
        },
        highlight: function (element) {
            $(element).addClass('is-invalid');

            if ($(element).hasClass('select2-hidden-accessible')) {
                $(element).next('.select2-container')
                    .find('.select2-selection')
                    .addClass('is-invalid');
            }
        },
        unhighlight: function (element) {
            $(element).removeClass('is-invalid');

            if ($(element).hasClass('select2-hidden-accessible')) {
                $(element).next('.select2-container')
                    .find('.select2-selection')
                    .removeClass('is-invalid');
            }
        },
        submitHandler: function (form) {
            if (!$('#orderLinesTable tbody tr.order-line').length) {
                toastr.warning('Adicione pelo menos uma linha à encomenda.');
                return false;
            }

            form.submit();
        }
    });

    updateSupplierName();
    updateStockValue();
    updateHeaderState();
    updateTotals();

    initialLines.forEach(function (line) {
        const product = productIndex[line.productCode];

        if (product) {
            addLine(product, line.quantity, line.unitPrice);
        }
    });

    updateTotals();
}
