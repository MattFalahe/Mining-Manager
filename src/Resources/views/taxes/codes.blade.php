@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.tax_codes'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v={{ time() }}">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard taxes-codes-page">

@include('mining-manager::taxes.partials.tab-navigation')

<div class="tax-codes">

    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.manage_tax_codes') }}</h3>
                    @if($isAdmin ?? false)
                    <div class="card-tools">
                        <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#generateCodeModal">
                            <i class="fas fa-plus"></i> {{ trans('mining-manager::taxes.generate_codes') }}
                        </button>
                    </div>
                    @endif
                </div>
                <div class="card-body p-0">
                    <table class="table table-dark table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::taxes.code') }}</th>
                                <th>{{ trans('mining-manager::taxes.character') }}</th>
                                <th>{{ trans('mining-manager::taxes.month') }}</th>
                                <th>{{ trans('mining-manager::taxes.amount') }}</th>
                                <th>{{ trans('mining-manager::taxes.status') }}</th>
                                <th class="text-center">{{ trans('mining-manager::taxes.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($taxCodes ?? [] as $code)
                            <tr>
                                <td><code>{{ $taxCodePrefix }}{{ $code->code }}</code></td>
                                <td>{{ $code->character_info['name'] ?? $code->character->name ?? 'Unknown' }}</td>
                                <td>{{ $code->miningTax ? \Carbon\Carbon::parse($code->miningTax->month)->format('F Y') : '-' }}</td>
                                <td>{{ $code->miningTax ? number_format($code->miningTax->amount_owed, 0) . ' ISK' : '-' }}</td>
                                <td>
                                    @if($code->status === 'used')
                                        <span class="badge badge-success">{{ trans('mining-manager::taxes.used') }}</span>
                                    @elseif($code->status === 'expired' || $code->isExpired())
                                        <span class="badge badge-danger">{{ trans('mining-manager::taxes.expired') }}</span>
                                    @else
                                        <span class="badge badge-warning">{{ trans('mining-manager::taxes.active') }}</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-info" onclick="copyCode('{{ $taxCodePrefix }}{{ $code->code }}')">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted">{{ trans('mining-manager::taxes.no_codes') }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="modal fade" id="generateCodeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('mining-manager::taxes.generate_codes') }}</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <form id="generateCodeForm">
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.month') }}</label>
                        <input type="month" class="form-control" name="month" required>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="allMembers" name="all_members">
                        <label class="form-check-label" for="allMembers">{{ trans('mining-manager::taxes.all_unpaid_members') }}</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('mining-manager::taxes.cancel') }}</button>
                <button type="button" class="btn btn-primary" onclick="generateCodes()">{{ trans('mining-manager::taxes.generate') }}</button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
function copyCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        toastr.success('{{ trans("mining-manager::taxes.code_copied") }}');
    });
}

function generateCodes() {
    var formData = $('#generateCodeForm').serializeArray();
    formData.push({ name: '_token', value: '{{ csrf_token() }}' });

    var btn = $('#generateCodeModal .btn-primary');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{ trans("mining-manager::taxes.generating") }}');

    $.ajax({
        url: '{{ route("mining-manager.taxes.codes.generate") }}',
        method: 'POST',
        data: $.param(formData),
        success: function(response) {
            $('#generateCodeModal').modal('hide');
            if (response.status === 'success') {
                toastr.success(response.message);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                toastr.warning(response.message);
            }
        },
        error: function(xhr) {
            $('#generateCodeModal').modal('hide');
            toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_occurred") }}');
        },
        complete: function() {
            btn.prop('disabled', false).html('{{ trans("mining-manager::taxes.generate") }}');
        }
    });
}
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
