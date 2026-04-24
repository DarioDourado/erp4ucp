@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <h4 class="card-title">Editar Família de Produto</h4>
                        <br><br>

                        <form method="post" action="{{ route('family.update') }}" id="myForm">
                            @csrf
                            <input type="hidden" name="id" value="{{ $family->id }}">

                            <div class="row mb-3">
                                <label for="family" class="col-sm-2 col-form-label">Família</label>
                                <div class="form-group col-sm-10">
                                    <input id="family" name="family" class="form-control" type="text" value="{{ $family->family }}" autofocus>
                                </div>
                            </div>

                            <input type="submit" class="btn btn-info waves-effect waves-light" value="Atualizar Família">
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
                family: { required: true }
            },
            messages: {
                family: { required: 'A Família é obrigatória.' }
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
