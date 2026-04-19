@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <h4 class="card-title">Adicionar Fornecedor</h4>
                        <br><br>

                        <form method="post" action="{{ route('supplier.store') }}" id="myForm">
                            @csrf

                            <div class="row mb-3">
                                <label for="code" class="col-sm-2 col-form-label">Código</label>
                                <div class="form-group col-sm-10">
                                    <input id="code" name="code" class="form-control" type="number" autofocus>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="name" class="col-sm-2 col-form-label">Nome</label>
                                <div class="form-group col-sm-10">
                                    <input id="name" name="name" class="form-control" type="text">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="nif" class="col-sm-2 col-form-label">NIF</label>
                                <div class="form-group col-sm-10">
                                    <input id="nif" name="nif" class="form-control" type="text">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="address1" class="col-sm-2 col-form-label">Morada 1</label>
                                <div class="form-group col-sm-10">
                                    <input id="address1" name="address1" class="form-control" type="text">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="address2" class="col-sm-2 col-form-label">Morada 2</label>
                                <div class="form-group col-sm-10">
                                    <input id="address2" name="address2" class="form-control" type="text">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="town" class="col-sm-2 col-form-label">Localidade</label>
                                <div class="form-group col-sm-10">
                                    <input id="town" name="town" class="form-control" type="text">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="postalCode" class="col-sm-2 col-form-label">Código Postal</label>
                                <div class="form-group col-sm-10">
                                    <select id="postalCode" name="postalCode" class="form-control select2">
                                        <option value="">-- Selecionar CP --</option>
                                        @foreach($postalCodes as $pc)
                                            <option value="{{ $pc->postalCode }}">{{ $pc->postalCode }} – {{ $pc->location }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <input type="submit" class="btn btn-info waves-effect waves-light" value="Adicionar Fornecedor">
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
        $('.select2').select2();

        $('#myForm').validate({
            rules: {
                code: { required: true },
                name: { required: true }
            },
            messages: {
                code: { required: 'O Código é obrigatório.' },
                name: { required: 'O Nome é obrigatório.' }
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
