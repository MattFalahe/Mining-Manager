@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.wallet_verification'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper taxes-wallet-page">

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


<div class="wallet-verification">
    
    {{-- Summary Stats --}}
    <div class="row">
        <div class="col-md-3">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>{{ $stats['pending'] ?? 0 }}</h3>
                    <p>{{ trans('mining-manager::taxes.pending_verification') }}</p>
                </div>
                <div class="icon"><i class="fas fa-clock"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>{{ $stats['verified'] ?? 0 }}</h3>
                    <p>{{ trans('mining-manager::taxes.verified_today') }}</p>
                </div>
                <div class="icon"><i class="fas fa-check"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="small-box bg-warning">
                <div class="inner">
                    <h3>{{ $stats['mismatched'] ?? 0 }}</h3>
                    <p>{{ trans('mining-manager::taxes.mismatched') }}</p>
                </div>
                <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="small-box bg-primary">
                <div class="inner">
                    <h3>{{ number_format($stats['total_amount'] ?? 0, 0) }}</h3>
                    <p>{{ trans('mining-manager::taxes.total_verified_isk') }}</p>
                </div>
                <div class="icon"><i class="fas fa-coins"></i></div>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.verification_actions') }}</h3>
                </div>
                <div class="card-body">
                    <button class="btn btn-primary" onclick="syncWalletJournal()">
                        <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::taxes.sync_wallet') }}
                    </button>
                    <button class="btn btn-success" onclick="autoMatch()">
                        <i class="fas fa-magic"></i> {{ trans('mining-manager::taxes.auto_match') }}
                    </button>
                    <button class="btn btn-info" data-toggle="modal" data-target="#manualEntryModal">
                        <i class="fas fa-plus"></i> {{ trans('mining-manager::taxes.manual_entry') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Transactions Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.wallet_transactions') }}</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-dark table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::taxes.date') }}</th>
                                <th>{{ trans('mining-manager::taxes.from_character') }}</th>
                                <th class="text-right">{{ trans('mining-manager::taxes.amount') }}</th>
                                <th>{{ trans('mining-manager::taxes.description') }}</th>
                                <th>{{ trans('mining-manager::taxes.matched_tax') }}</th>
                                <th>{{ trans('mining-manager::taxes.status') }}</th>
                                <th class="text-center">{{ trans('mining-manager::taxes.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($transactions ?? [] as $transaction)
                            <tr>
                                <td>{{ $transaction->date->format('Y-m-d H:i') }}</td>
                                <td>{{ $transaction->character_name }}</td>
                                <td class="text-right">{{ number_format($transaction->amount, 0) }} ISK</td>
                                <td><small>{{ $transaction->description }}</small></td>
                                <td>
                                    @if($transaction->matched_tax_id)
                                        <a href="{{ route('mining-manager.taxes.details', $transaction->matched_tax_id) }}">
                                            {{ trans('mining-manager::taxes.view_tax') }}
                                        </a>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if($transaction->verified)
                                        <span class="badge badge-success">{{ trans('mining-manager::taxes.verified') }}</span>
                                    @elseif($transaction->mismatch)
                                        <span class="badge badge-warning">{{ trans('mining-manager::taxes.mismatch') }}</span>
                                    @else
                                        <span class="badge badge-info">{{ trans('mining-manager::taxes.pending') }}</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if(!$transaction->verified)
                                    <button class="btn btn-sm btn-success" onclick="verifyTransaction({{ $transaction->id }})">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted">{{ trans('mining-manager::taxes.no_transactions') }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- Manual Entry Modal --}}
<div class="modal fade" id="manualEntryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('mining-manager::taxes.manual_payment_entry') }}</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <form id="manualEntryForm">
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.character') }}</label>
                        <select class="form-control" name="character_id" required>
                            <option value="">{{ trans('mining-manager::taxes.select_character') }}</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.amount') }}</label>
                        <input type="number" class="form-control" name="amount" required>
                    </div>
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.payment_date') }}</label>
                        <input type="date" class="form-control" name="date" value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.notes') }}</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('mining-manager::taxes.cancel') }}</button>
                <button type="button" class="btn btn-primary" onclick="submitManualEntry()">{{ trans('mining-manager::taxes.submit') }}</button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
function syncWalletJournal() {
    toastr.info('{{ trans("mining-manager::taxes.syncing_wallet") }}');
    // Implementation
}

function autoMatch() {
    toastr.info('{{ trans("mining-manager::taxes.auto_matching") }}');
    // Implementation
}

function verifyTransaction(id) {
    toastr.success('{{ trans("mining-manager::taxes.transaction_verified") }}');
    // Implementation
}

function submitManualEntry() {
    $('#manualEntryModal').modal('hide');
    toastr.success('{{ trans("mining-manager::taxes.payment_recorded") }}');
}
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
