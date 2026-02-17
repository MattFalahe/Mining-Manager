@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.tax_contracts'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper taxes-contracts-page">

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/tax') && !Request::is('*/tax/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.index') }}">
                <i class="fas fa-chart-pie"></i> {{ trans('mining-manager::menu.tax_overview') }}
            </a>
        </li>
        @can('mining-manager.tax.calculate')
        <li class="{{ Request::is('*/tax/calculate') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.calculate') }}">
                <i class="fas fa-calculator"></i> {{ trans('mining-manager::menu.calculate_taxes') }}
            </a>
        </li>
        @endcan
        <li class="{{ Request::is('*/tax/my-taxes') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.my-taxes') }}">
                <i class="fas fa-receipt"></i> {{ trans('mining-manager::menu.my_taxes') }}
            </a>
        </li>
        <li class="{{ Request::is('*/tax/codes') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.codes') }}">
                <i class="fas fa-barcode"></i> {{ trans('mining-manager::menu.tax_codes') }}
            </a>
        </li>
        @can('mining-manager.tax.generate_invoices')
        <li class="{{ Request::is('*/tax/contracts') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.contracts') }}">
                <i class="fas fa-file-contract"></i> {{ trans('mining-manager::menu.tax_contracts') }}
            </a>
        </li>
        @endcan
        @can('mining-manager.tax.verify_payments')
        <li class="{{ Request::is('*/tax/wallet') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.wallet') }}">
                <i class="fas fa-wallet"></i> {{ trans('mining-manager::menu.wallet_verification') }}
            </a>
        </li>
        @endcan
    </ul>
    <div class="tab-content">


<div class="tax-contracts">

    {{-- ESI Integration Status --}}
    <div class="row">
        <div class="col-12">
            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i>
                <strong>Note:</strong> In-game contract creation via ESI is not yet implemented.
                Invoices are tracked internally for record-keeping.
                Use <strong>wallet transfers with tax codes</strong> for payment verification.
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.manage_contracts') }}</h3>
                    <div class="card-tools">
                        <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#generateContractModal">
                            <i class="fas fa-plus"></i> {{ trans('mining-manager::taxes.generate_contracts') }}
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-dark table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::taxes.contract_id') }}</th>
                                <th>{{ trans('mining-manager::taxes.character') }}</th>
                                <th>{{ trans('mining-manager::taxes.amount') }}</th>
                                <th>{{ trans('mining-manager::taxes.status') }}</th>
                                <th>{{ trans('mining-manager::taxes.created') }}</th>
                                <th>{{ trans('mining-manager::taxes.expires') }}</th>
                                <th class="text-center">{{ trans('mining-manager::taxes.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($invoices ?? [] as $contract)
                            <tr>
                                <td>{{ $contract->contract_id }}</td>
                                <td>{{ $contract->character->name ?? 'Unknown' }}</td>
                                <td>{{ number_format($contract->amount, 0) }} ISK</td>
                                <td>
                                    @switch($contract->status)
                                        @case('pending')
                                            <span class="badge badge-warning">{{ trans('mining-manager::taxes.pending') }}</span>
                                            @break
                                        @case('sent')
                                            <span class="badge badge-info">{{ trans('mining-manager::taxes.sent') }}</span>
                                            @break
                                        @case('accepted')
                                            <span class="badge badge-success">{{ trans('mining-manager::taxes.completed') }}</span>
                                            @break
                                        @default
                                            <span class="badge badge-secondary">{{ $contract->status }}</span>
                                    @endswitch
                                </td>
                                <td>{{ $contract->created_at ? $contract->created_at->format('Y-m-d') : '-' }}</td>
                                <td>{{ $contract->expires_at ? $contract->expires_at->format('Y-m-d') : '-' }}</td>
                                <td class="text-center">
                                    <a href="#" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted">{{ trans('mining-manager::taxes.no_contracts') }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="modal fade" id="generateContractModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('mining-manager::taxes.generate_contracts') }}</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <p>{{ trans('mining-manager::taxes.generate_contracts_help') }}</p>
                <form id="generateContractForm">
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.month') }}</label>
                        <input type="month" class="form-control" name="month" required>
                    </div>
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.expiry_days') }}</label>
                        <input type="number" class="form-control" name="expiry_days" value="7" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('mining-manager::taxes.cancel') }}</button>
                <button type="button" class="btn btn-success" onclick="generateContracts()">{{ trans('mining-manager::taxes.generate') }}</button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
function generateContracts() {
    var formData = $('#generateContractForm').serializeArray();
    formData.push({ name: '_token', value: '{{ csrf_token() }}' });

    var btn = $('#generateContractModal .btn-success');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{ trans("mining-manager::taxes.generating") }}');

    $.ajax({
        url: '{{ route("mining-manager.taxes.contracts.generate") }}',
        method: 'POST',
        data: $.param(formData),
        success: function(response) {
            $('#generateContractModal').modal('hide');
            if (response.status === 'success') {
                toastr.success(response.message);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                toastr.warning(response.message);
            }
        },
        error: function(xhr) {
            $('#generateContractModal').modal('hide');
            toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_occurred") }}');
        },
        complete: function() {
            btn.prop('disabled', false).html('{{ trans("mining-manager::taxes.generate") }}');
        }
    });
}
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
