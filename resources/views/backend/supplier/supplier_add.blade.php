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
                                        <input id="code" name="code" class="form-control" type="number"
                                            value="{{ old('code') }}" autofocus>
                                        @error('code')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="name" class="col-sm-2 col-form-label">Nome</label>
                                    <div class="form-group col-sm-10">
                                        <input id="name" name="name" class="form-control" type="text"
                                            value="{{ old('name') }}">
                                        @error('name')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="nif" class="col-sm-2 col-form-label">NIF</label>
                                    <div class="form-group col-sm-10">
                                        <input id="nif" name="nif" class="form-control" type="text"
                                            value="{{ old('nif') }}">
                                        @error('nif')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="address1" class="col-sm-2 col-form-label">Morada 1</label>
                                    <div class="form-group col-sm-10">
                                        <input id="address1" name="address1" class="form-control" type="text"
                                            value="{{ old('address1') }}">
                                        @error('address1')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="address2" class="col-sm-2 col-form-label">Morada 2</label>
                                    <div class="form-group col-sm-10">
                                        <input id="address2" name="address2" class="form-control" type="text"
                                            value="{{ old('address2') }}">
                                        @error('address2')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="town" class="col-sm-2 col-form-label">Localidade</label>
                                    <div class="form-group col-sm-10">
                                        <input id="town" name="town" class="form-control @error('town') is-invalid @enderror"
                                            type="text" value="{{ old('town') }}">
                                        @error('town')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="codePostal" class="col-sm-2 col-form-label">Código Postal</label>
                                    <div class="form-group col-sm-10">
                                        <select id="codePostal" name="codePostal"
                                            class="form-control select2 @error('codePostal') is-invalid @enderror">
                                            <option value="">Selecionar ...</option>
                                            @foreach($postalCodes as $supp)
                                                <option value="{{ $supp->postalCode }}"
                                                    data-location="{{ $supp->location }}"
                                                    {{ old('postalCode') == $supp->postalCode ? 'selected' : '' }}>
                                                    {{ $supp->postalCode }} – {{ $supp->location }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <input type="submit" class="btn btn-info waves-effect waves-light"
                                    value="Adicionar Fornecedor">
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

                $('#codePostal').on('change', function () {
                    $(this).valid();
                });

                $('#myForm').validate({
                    ignore: [],
                    rules: {
                        code: { required: true },
                        name: { required: true },
                        postalCode: { required: true },
                        address1: { required: true },
                        codePostal: { required: true },
                        nif: { required: true }
                    },
                    messages: {
                        code: { required: 'Por Favor introduza o código do fornecedor.' },
                        name: { required: 'Por Favor introduza o nome do fornecedor.' },
                        postalCode: { required: 'Por Favor introduza o código postal do fornecedor.' },
                        address1: { required: 'Por Favor introduza o endereço 1' },
                        codePostal: { required: 'Por Favor introduza o código postal.' },
                        nif: { required: 'Por Favor introduza o NIF' }
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
                        if (element.hasClass('select2-hidden-accessible')) {
                            $(element.next('select2-hidden-accessible').find('.select2-selection')).addClass('is-invalid');
                        } else {
                            element.closest('.form-group').append(error);
                        }
                    },
                    unhighlight: function (element) {
                        $(element).removeClass('is-invalid');
                       if (element.hasClass('select2-hidden-accessible')) {
                            $(element.next('select2-hidden-accessible').find('.select2-selection')).addClass('is-invalid');
                        } else {
                            element.closest('.form-group').append(error);
                        }
                    }
                });
            });
        </script>
    @endpush

@endsection