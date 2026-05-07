@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <h4 class="card-title">Adicionar Unidade de Medida</h4>
                        <br><br>

                        <form method="post" action="{{ route('unitMeasure.store') }}" id="myForm">
                            @csrf

                            <div class="row mb-3">
                                <label for="unit" class="col-sm-2 col-form-label">Unidade</label>
                                <div class="form-group col-sm-10">
                                    <input id="unit" name="unit" class="form-control" type="text" autofocus>
                                </div>
                            </div>

                            <input type="submit" class="btn btn-info waves-effect waves-light" value="Adicionar Unidade">
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
                unit: { required: true }
            },
            messages: {
                unit: { required: 'A Unidade é obrigatória.' }
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
