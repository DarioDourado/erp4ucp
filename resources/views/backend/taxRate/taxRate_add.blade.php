@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <h4 class="card-title">Add Tax Rate</h4>
                        <br><br>

                        <form method="post" action="{{ route('taxRate.store') }}" id="myForm">
                            @csrf

                            <div class="row mb-3">
                                <label for="taxRateCode" class="col-sm-2 col-form-label">Tax Rate Code</label>
                                <div class="form-group col-sm-10">
                                    <input id="taxRateCode" name="taxRateCode" class="form-control" type="number" autofocus>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="descriptionTaxRate" class="col-sm-2 col-form-label">Description</label>
                                <div class="form-group col-sm-10">
                                    <input id="descriptionTaxRate" name="descriptionTaxRate" class="form-control" type="text">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="taxRate" class="col-sm-2 col-form-label">Tax Rate %</label>
                                <div class="form-group col-sm-10">
                                    <input id="taxRate" name="taxRate" class="form-control" type="number" step="0.01">
                                </div>
                            </div>

                            <input type="submit" class="btn btn-info waves-effect waves-light" value="Add Tax Rate">
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
                taxRateCode:        { required: 'Please Enter Tax Rate Code.' },
                descriptionTaxRate: { required: 'Please Enter Tax Rate Description.' },
                taxRate:            { required: 'Please Enter Tax Rate %.' }
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
