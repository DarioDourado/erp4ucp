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

                            <form method="post" action="{{ route('article.store') }}" id="myForm" enctype="multipart/form-data">
                                @csrf

                                <div class="row mb-3">
                                    <label for="code" class="col-sm-2 col-form-label">Código</label>
                                    <div class="form-group col-sm-10">
                                        <input id="code" name="code" class="form-control" type="text"
                                            value="{{ old('code') }}" autofocus>
                                        @error('code')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="description" class="col-sm-2 col-form-label">Descrição</label>
                                    <div class="form-group col-sm-10">
                                        <input id="description" name="description" class="form-control" type="text"
                                            value="{{ old('description') }}">
                                        @error('description')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="unitMeasure_id" class="col-sm-2 col-form-label">Unidade de Medida</label>
                                    <div class="form-group col-sm-10">
                                        <select id="unitMeasure_id" name="unitMeasure_id" class="form-control select2">
                                            <option value="">Selecionar ...</option>
                                            @foreach($unitMeasures as $unit)
                                                <option value="{{ $unit->id }}" {{ old('unitMeasure_id') == $unit->id ? 'selected' : '' }}>
                                                    {{ $unit->unity }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('unitMeasure_id')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="family_id" class="col-sm-2 col-form-label">Família</label>
                                    <div class="form-group col-sm-10">
                                        <select id="family_id" name="family_id" class="form-control select2">
                                            <option value="">Selecionar ...</option>
                                            @foreach($families as $family)
                                                <option value="{{ $family->id }}" {{ old('family_id') == $family->id ? 'selected' : '' }}>
                                                    {{ $family->family }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('family_id')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="taxRate_id" class="col-sm-2 col-form-label">Taxa de IVA</label>
                                    <div class="form-group col-sm-10">
                                        <select id="taxRate_id" name="taxRate_id" class="form-control select2">
                                            <option value="">Selecionar ...</option>
                                            @foreach($taxRates as $taxRate)
                                                <option value="{{ $taxRate->id }}" {{ old('taxRate_id') == $taxRate->id ? 'selected' : '' }}>
                                                    {{ $taxRate->descriptionTaxRate }} ({{ $taxRate->taxRate }}%)
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('taxRate_id')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <label for="image" class="col-sm-2 col-form-label">Imagem</label>
                                    <div class="form-group col-sm-10">
                                        <input id="image" name="image" class="form-control" type="file" accept="image/*">
                                        @error('image')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
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

    @push('scripts')
        <script type="text/javascript">
            $(document).ready(function () {
                $('.select2').select2();
            });
        </script>
    @endpush

@endsection
