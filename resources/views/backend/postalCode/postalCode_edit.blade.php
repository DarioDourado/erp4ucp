@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <h4 class="card-title">Editar Código Postal</h4>
                        <br><br>

                        <form method="post" action="{{ route('postalCode.update') }}" id="myForm">
                            @csrf
                        <input type="hidden" name="id" value="{{ $postalCode->id }}">
                            <div class="row mb-3">
                                <label for="postalCode" class="col-sm-2 col-form-label">Código Postal</label>
                                <div class="form-group col-sm-10">
                                    <input id="postalCode" name="postalCode" class="form-control" value="{{ $postalCode->postalCode }}" type="text" autofocus>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="location" class="col-sm-2 col-form-label">Localidade</label>
                                <div class="form-group col-sm-10">
                                    <input id="location" name="location" class="form-control" value="{{ $postalCode->location }}" type="text">
                                </div>
                            </div>

                            <input type="submit" class="btn btn-info waves-effect waves-light" value="Atualizar CP">
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
                postalCode: {
                    required: true
                },
                location: {
                    required: true
                }
            },
            messages: {
                postalCode: {
                    required: 'O CP é obrigatório.'
                },
                location: {
                    required: 'A Localidade é obrigatória.'
                }
            },
            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element, errorClass, validClass) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element, errorClass, validClass) {
                $(element).removeClass('is-invalid');
            }
        });
    });
</script>
@endpush

@endsection

    