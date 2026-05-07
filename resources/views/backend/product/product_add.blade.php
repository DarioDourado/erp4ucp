@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <h4 class="card-title">Adicionar Artigo</h4>
                        <br><br>

                        <form method="post" action="{{ route('product.store') }}" id="myForm" enctype="multipart/form-data" novalidate>
                            @csrf

                            <!-- Código -->
                            <div class="row mb-3">
                                <label for="code" class="col-sm-2 col-form-label">Código</label>
                                <div class="form-group col-sm-10">
                                    <input id="code"
                                           name="code"
                                           class="form-control @error('code') is-invalid @enderror"
                                           type="text"
                                           value="{{ old('code') }}"
                                           autofocus>

                                    @error('code')
                                        <span class="invalid-feedback d-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Descrição -->
                            <div class="row mb-3">
                                <label for="description" class="col-sm-2 col-form-label">Descrição</label>
                                <div class="form-group col-sm-10">
                                    <input id="description"
                                           name="description"
                                           class="form-control @error('description') is-invalid @enderror"
                                           type="text"
                                           value="{{ old('description') }}">

                                    @error('description')
                                        <span class="invalid-feedback d-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Imagem -->
                            <div class="row mb-3">
                                <label for="image" class="col-sm-2 col-form-label">Imagem do Artigo</label>
                                <div class="form-group col-sm-10">
                                    <div class="d-flex align-items-center gap-2">
                                        <input name="image"
                                               class="form-control @error('image') is-invalid @enderror"
                                               type="file"
                                               id="image"
                                               accept=".jpg,.jpeg,.png,.webp,image/*">

                                        <button type="button" id="clearImage" class="btn btn-outline-secondary btn-sm">
                                            Limpar
                                        </button>
                                    </div>

                                    @error('image')
                                        <span class="invalid-feedback d-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Preview -->
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label"></label>
                                <div class="form-group col-sm-10">
                                    <img id="showImage"
                                         class="rounded"
                                         src="{{ url('upload/no_image.jpg') }}"
                                         style="width:150px; height:150px; object-fit:cover;">
                                </div>
                            </div>

                            <!-- Família + Unidade + Tax -->
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label"></label>

                                <div class="form-group col-sm-10">
                                    <div class="row align-items-start">

                                        <!-- Família -->
                                        <div class="col-md-4">
                                            <label for="product_family" class="form-label">Família</label>
                                            <select id="product_family"
                                                    name="product_family"
                                                    class="form-select select2 @error('product_family') is-invalid @enderror"
                                                    data-placeholder="Selecione...">
                                                <option></option>
                                                @foreach($families as $f)
                                                    <option value="{{ $f->family }}"
                                                        {{ old('product_family') == $f->family ? 'selected' : '' }}>
                                                        {{ $f->family }}
                                                    </option>
                                                @endforeach
                                            </select>

                                            @error('product_family')
                                                <span class="invalid-feedback d-block">{{ $message }}</span>
                                            @enderror
                                        </div>

                                        <!-- Unidade -->
                                        <div class="col-md-4">
                                            <label for="product_unit" class="form-label">Unidade</label>
                                            <select id="product_unit"
                                                    name="product_unit"
                                                    class="form-select select2 @error('product_unit') is-invalid @enderror"
                                                    data-placeholder="Selecione...">
                                                <option></option>
                                                @foreach($units as $u)
                                                    <option value="{{ $u->unit }}"
                                                        {{ old('product_unit') == $u->unit ? 'selected' : '' }}>
                                                        {{ $u->unit }}
                                                    </option>
                                                @endforeach
                                            </select>

                                            @error('product_unit')
                                                <span class="invalid-feedback d-block">{{ $message }}</span>
                                            @enderror
                                        </div>

                                        <!-- Tax -->
                                        <div class="col-md-4">
                                            <label for="product_taxRateCode" class="form-label">Tax Rate</label>
                                            <select id="product_taxRateCode"
                                                    name="taxRateCode_Product"
                                                    class="form-select @error('taxRateCode_Product') is-invalid @enderror"
                                                    data-placeholder="Selecione...">
                                                <option></option>
                                                @foreach($taxRates as $t)
                                                    <option value="{{ $t->taxRateCode }}"
                                                            data-description="{{ $t->descriptionTaxRate }}"
                                                            data-rate="{{ $t->taxRate }}"
                                                            {{ old('taxRateCode_Product') == $t->taxRateCode ? 'selected' : '' }}>
                                                        {{ $t->taxRateCode }}
                                                    </option>
                                                @endforeach
                                            </select>

                                            @error('taxRateCode_Product')
                                                <span class="invalid-feedback d-block">{{ $message }}</span>
                                            @enderror

                                            <small id="lbTaxDescription" class="text-muted d-block mt-1"></small>
                                        </div>

                                    </div>
                                </div>
                            </div>

                            <input type="submit" class="btn btn-info waves-effect waves-light" value="Adicionar Artigo">

                        </form>

                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

@endsection


@push('styles')
<style>
    .select2-container {
        width: 100% !important;
    }

    .select2-container .select2-selection--single {
        height: calc(1.5em + .94rem + 2px) !important;
        padding: .47rem .75rem !important;
        border: 1px solid #ced4da !important;
    }

    .select2-container .select2-selection__rendered {
        line-height: 1.5 !important;
        padding-left: 0 !important;
        padding-right: 20px !important;
        font-size: .875rem;
    }

    .select2-container .select2-selection__arrow {
        height: 100% !important;
    }

    .select2-container .select2-selection.is-invalid {
        border-color: #dc3545 !important;
    }

    /* Dropdown do IVA em 3 colunas */
    .tax-option-row {
        display: grid;
        grid-template-columns: 90px 1fr 90px;
        gap: 10px;
        align-items: center;
        width: 100%;
    }

    .tax-option-code {
        font-weight: 600;
        white-space: nowrap;
    }

    .tax-option-desc {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .tax-option-rate {
        text-align: right;
        white-space: nowrap;
    }
</style>
@endpush


@push('scripts')
<script>
$(document).ready(function () {

    const defaultImage = "{{ url('upload/no_image.jpg') }}";

    // Select2 para Família e Unidade
    $('.select2').select2({
        placeholder: function () {
            return $(this).data('placeholder') || 'Selecione...';
        },
        allowClear: true,
        width: '100%'
    });

    // Formatação das opções da Taxa IVA
    function formatTaxOption(option) {
        if (!option.id) {
            return option.text;
        }

        let code = option.id || '';
        let description = $(option.element).data('description') || '';
        let rate = $(option.element).data('rate') || '';

        return $(`
            <div class="tax-option-row">
                <span class="tax-option-code">${code}</span>
                <span class="tax-option-desc">${description}</span>
                <span class="tax-option-rate">${rate}%</span>
            </div>
        `);
    }

    // No campo selecionado mostra só o código
    function formatTaxSelection(option) {
        if (!option.id) {
            return option.text;
        }

        return option.id;
    }

    // Select2 para Tax Rate
    $('#product_taxRateCode').select2({
        placeholder: $('#product_taxRateCode').data('placeholder') || 'Selecione...',
        allowClear: true,
        width: '100%',
        templateResult: formatTaxOption,
        templateSelection: formatTaxSelection,
        escapeMarkup: function (markup) {
            return markup;
        },
        matcher: function(params, data) {
            if ($.trim(params.term) === '') {
                return data;
            }

            if (!data.id) {
                return null;
            }

            let term = params.term.toLowerCase();
            let code = (data.id || '').toLowerCase();
            let description = ($(data.element).data('description') || '').toString().toLowerCase();
            let rate = ($(data.element).data('rate') || '').toString().toLowerCase();

            if (
                code.indexOf(term) > -1 ||
                description.indexOf(term) > -1 ||
                rate.indexOf(term) > -1
            ) {
                return data;
            }

            return null;
        }
    });

    // Preview imagem
    $('#image').change(function (e) {
        let file = e.target.files[0];

        if (file) {
            let reader = new FileReader();
            reader.onload = function (event) {
                $('#showImage').attr('src', event.target.result);
            };
            reader.readAsDataURL(file);
        } else {
            $('#showImage').attr('src', defaultImage);
        }
    });

    // Limpar imagem
    $('#clearImage').click(function () {
        $('#image').val('');
        $('#showImage').attr('src', defaultImage);
    });

    // Atualizar descrição Tax
    function updateTaxDescription() {
        let selected = $('#product_taxRateCode').find('option:selected');
        let desc = selected.data('description') || '';
        let rate = selected.data('rate') || '';

        if (desc || rate) {
            $('#lbTaxDescription').text(desc + ' - ' + rate + '%');
        } else {
            $('#lbTaxDescription').text('');
        }
    }

    $('#product_taxRateCode').on('change', updateTaxDescription);

    $('#product_taxRateCode').on('select2:clear', function () {
        $('#lbTaxDescription').text('');
    });

    updateTaxDescription();

    // Limpar estado inválido ao mexer nos selects
    $('#product_family, #product_unit, #product_taxRateCode').on('change', function () {
        $(this).removeClass('is-invalid');

        if ($(this).hasClass('select2-hidden-accessible')) {
            $(this).next('.select2-container')
                   .find('.select2-selection')
                   .removeClass('is-invalid');
        }
    });

    // Validação
    $('#myForm').validate({
        ignore: [],
        rules: {
            code: { required: true },
            description: { required: true },
            product_family: { required: true },
            product_unit: { required: true },
            taxRateCode_Product: { required: true }
        },
        messages: {
            code: { required: 'Introduza o código' },
            description: { required: 'Introduza a descrição' },
            product_family: { required: 'Selecione a família' },
            product_unit: { required: 'Selecione a unidade' },
            taxRateCode_Product: { required: 'Selecione o IVA' }
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
        }
    });

});
</script>
@endpush
