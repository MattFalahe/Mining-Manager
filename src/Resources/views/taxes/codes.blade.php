@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.tax_codes'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')


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


<div class="tax-codes">
    
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.manage_tax_codes') }}</h3>
                    <div class="card-tools">
                        <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#generateCodeModal">
                            <i class="fas fa-plus"></i> {{ trans('mining-manager::taxes.generate_codes') }}
                        </button>
                    </div>
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
                                <td><code>{{ $code->code }}</code></td>
                                <td>{{ $code->character->name ?? 'Unknown' }}</td>
                                <td>{{ \Carbon\Carbon::parse($code->month)->format('F Y') }}</td>
                                <td>{{ number_format($code->amount, 0) }} ISK</td>
                                <td>
                                    @if($code->used)
                                        <span class="badge badge-success">{{ trans('mining-manager::taxes.used') }}</span>
                                    @else
                                        <span class="badge badge-warning">{{ trans('mining-manager::taxes.unused') }}</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-info" onclick="copyCode('{{ $code->code }}')">
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
    // Implementation
    $('#generateCodeModal').modal('hide');
    toastr.success('{{ trans("mining-manager::taxes.codes_generated") }}');
}
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

@endsection
