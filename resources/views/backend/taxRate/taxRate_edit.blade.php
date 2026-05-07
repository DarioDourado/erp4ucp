@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <h4 class="card-title">Editar Taxa de IVA</h4>
                        <br><br>

                        <form method="post" action="{{ route('taxRate.update') }}" id="myForm">
                            @csrf
                            <input type="hidden" name="id" value="{{ $taxRate->id }}">

                            <div class="row mb-3">
                                <label for="taxRateCode" class="col-sm-2 col-form-label">Código de IVA</label>
                                <div class="form-group col-sm-10">
                                    <input id="taxRateCode" name="taxRateCode" class="form-control" type="number" value="{{ $taxRate->taxRateCode }}" autofocus>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="descriptionTaxRate" class="col-sm-2 col-form-label">Descrição</label>
                                <div class="form-group col-sm-10">
                                    <input id="descriptionTaxRate" name="descriptionTaxRate" class="form-control" type="text" value="{{ $taxRate->descriptionTaxRate }}">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="taxRate" class="col-sm-2 col-form-label">Tax Rate %</label>
                                <div class="form-group col-sm-10">
                                    <input id="taxRate" name="taxRate" class="form-control" type="number" step="0.01" value="{{ $taxRate->taxRate }}">
                                </div>
                            </div>

                            <input type="submit" class="btn btn-info waves-effect waves-light" value="Atualizar Taxa de IVA">
                        </form>

                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

@push('scripts')
<script type="text/javascript">
    $(document).ready(function () {
        $('#myForm').validate({
            rules: {
                taxRateCode:        { required: true },
                descriptionTaxRate: { required: true },
                taxRate:            { required: true }
            },
            messages: {
                taxRateCode:        { required: 'O Código de IVA é obrigatório.' },
                descriptionTaxRate: { required: 'A Descrição é obrigatória.' },
                taxRate:            { required: 'A Taxa de IVA (%) é obrigatória.' }
            },
            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element) {
                $(element).removeClass('is-invalid');
            }
        });
    });
</script>
@endpush

@endsection
