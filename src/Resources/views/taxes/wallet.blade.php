@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.wallet_verification'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
@include('mining-manager::taxes.partials.datatables-styles')
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard taxes-wallet-page">

@include('mining-manager::taxes.partials.tab-navigation')


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

    {{-- Actions (director/admin only) --}}
    @if(($isDirector ?? false) || ($isAdmin ?? false))
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
    @endif

    {{-- Transactions Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.wallet_transactions') }}</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-dark table-striped" id="walletTable">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::taxes.date') }}</th>
                                <th>{{ trans('mining-manager::taxes.from_character') }}</th>
                                <th class="text-right">{{ trans('mining-manager::taxes.amount') }}</th>
                                <th>{{ trans('mining-manager::taxes.description') }}</th>
                                <th>{{ trans('mining-manager::taxes.reason') }}</th>
                                <th>{{ trans('mining-manager::taxes.matched_tax') }}</th>
                                <th>{{ trans('mining-manager::taxes.status') }}</th>
                                <th class="text-center">{{ trans('mining-manager::taxes.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($transactions ?? [] as $transaction)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($transaction->date)->format('Y-m-d H:i') }}</td>
                                <td>{{ $transaction->character_name }}</td>
                                <td class="text-right">{{ number_format($transaction->amount, 0) }} ISK</td>
                                <td><small>{{ $transaction->description }}</small></td>
                                <td>
                                    @if($transaction->reason)
                                        <code>{{ $transaction->reason }}</code>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
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
                                    @if(!$transaction->verified && (($isDirector ?? false) || ($isAdmin ?? false)))
                                    <button class="btn btn-sm btn-success mr-1" onclick="verifyTransaction({{ $transaction->id }})" title="Verify payment">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="dismissTransaction({{ $transaction->id }})" title="Dismiss / Ignore">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted">{{ trans('mining-manager::taxes.no_transactions') }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/jquery.dataTables.min.js') }}"></script>
<script>
$(document).ready(function() {
    if ($('#walletTable tbody tr').length > 0 && !$('#walletTable tbody tr td[colspan]').length) {
    $('#walletTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'desc']],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "No entries found",
            infoFiltered: "(filtered from _MAX_ total entries)",
            paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
        },
        columnDefs: [
            { orderable: false, targets: [7] }
        ]
    });
    }
});

function syncWalletJournal() {
    toastr.info('Syncing wallet journal...');
    $.ajax({
        url: '{{ route("mining-manager.taxes.wallet.verify") }}',
        type: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            action: 'sync'
        },
        success: function(response) {
            toastr.success(response.message || 'Wallet synced');
            location.reload();
        },
        error: function(xhr) {
            toastr.error(xhr.responseJSON?.message || 'Sync failed');
        }
    });
}

function autoMatch() {
    toastr.info('Auto-matching transactions...');
    $.ajax({
        url: '{{ route("mining-manager.taxes.wallet.verify") }}',
        type: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            action: 'auto_match',
            days: 30
        },
        success: function(response) {
            toastr.success(response.message || 'Auto-match complete');
            location.reload();
        },
        error: function(xhr) {
            toastr.error(xhr.responseJSON?.message || 'Auto-match failed');
        }
    });
}

function verifyTransaction(id) {
    if (!confirm('Verify this payment?')) return;
    $.ajax({
        url: '{{ route("mining-manager.taxes.wallet.verify") }}',
        type: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            transaction_ids: [id]
        },
        success: function(response) {
            toastr.success(response.message || 'Payment verified');
            location.reload();
        },
        error: function(xhr) {
            toastr.error(xhr.responseJSON?.message || 'Verification failed');
        }
    });
}

function dismissTransaction(id) {
    if (!confirm('Dismiss this transaction? It will be marked as ignored and hidden from pending.')) return;
    $.ajax({
        url: '{{ route("mining-manager.taxes.wallet.dismiss") }}',
        type: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            transaction_id: id
        },
        success: function(response) {
            toastr.success(response.message || 'Transaction dismissed');
            location.reload();
        },
        error: function(xhr) {
            toastr.error(xhr.responseJSON?.message || 'Dismiss failed');
        }
    });
}

function submitRecordPayment() {
    var taxId = $('#rpTaxId').val();
    var amount = $('#rpAmount').val();
    var date = $('#rpDate').val();
    var notes = $('#rpNotes').val();

    if (!taxId) {
        toastr.error('Please select an invoice');
        return;
    }
    if (!amount || parseFloat(amount) <= 0) {
        toastr.error('Please enter a valid amount');
        return;
    }

    $.ajax({
        url: '{{ route("mining-manager.taxes.mark-paid") }}',
        type: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            tax_id: taxId,
            amount_paid: amount,
            payment_date: date,
            notes: notes
        },
        success: function(response) {
            $('#manualEntryModal').modal('hide');
            toastr.success(response.message || 'Payment recorded');
            location.reload();
        },
        error: function(xhr) {
            toastr.error(xhr.responseJSON?.message || 'Failed to record payment');
        }
    });
}

function submitManualEntry() {
    var characterId = $('#meCharacterId').val();
    var amount = $('#meAmount').val();
    var periodStart = $('#mePeriodStart').val();
    var periodEnd = $('#mePeriodEnd').val();
    var notes = $('#meNotes').val();

    if (!characterId) {
        toastr.error('Please select a character');
        return;
    }
    if (!amount || parseFloat(amount) <= 0) {
        toastr.error('Please enter a valid amount');
        return;
    }
    if (!periodStart || !periodEnd) {
        toastr.error('Please enter period dates');
        return;
    }

    $.ajax({
        url: '{{ route("mining-manager.taxes.manual-entry") }}',
        type: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            character_id: characterId,
            amount: amount,
            period_start: periodStart,
            period_end: periodEnd,
            notes: notes
        },
        success: function(response) {
            $('#manualEntryModal').modal('hide');
            toastr.success(response.message || 'Manual entry created');
            location.reload();
        },
        error: function(xhr) {
            toastr.error(xhr.responseJSON?.message || 'Failed to create manual entry');
        }
    });
}

// Auto-fill amount when invoice is selected
$(document).on('change', '#rpTaxId', function() {
    var selected = $(this).find(':selected');
    var amount = selected.data('amount');
    var remaining = selected.data('remaining');
    if (remaining !== undefined) {
        $('#rpAmount').val(parseFloat(remaining).toFixed(0));
    } else if (amount !== undefined) {
        $('#rpAmount').val(parseFloat(amount).toFixed(0));
    }
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}

{{-- Manual Payment Entry Modal (director/admin only) --}}
@if(($isDirector ?? false) || ($isAdmin ?? false))
<div class="modal fade" id="manualEntryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus mr-2"></i>{{ trans('mining-manager::taxes.manual_payment_entry') }}</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                {{-- Tab navigation --}}
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-toggle="tab" href="#tabRecordPayment" role="tab">
                            <i class="fas fa-check-circle mr-1"></i> {{ trans('mining-manager::taxes.record_payment') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#tabManualEntry" role="tab">
                            <i class="fas fa-edit mr-1"></i> {{ trans('mining-manager::taxes.manual_entry_tab') }}
                        </a>
                    </li>
                </ul>

                <div class="tab-content mt-3">
                    {{-- Tab 1: Record Payment for existing invoice --}}
                    <div class="tab-pane fade show active" id="tabRecordPayment" role="tabpanel">
                        <p class="text-muted small mb-3">
                            <i class="fas fa-info-circle mr-1"></i>
                            Record a payment against an existing unpaid invoice. Supports partial payments.
                        </p>
                        <form id="recordPaymentForm">
                            <div class="form-group">
                                <label>{{ trans('mining-manager::taxes.select_invoice') }}</label>
                                <select class="form-control" id="rpTaxId" required>
                                    <option value="">— {{ trans('mining-manager::taxes.select_invoice') }} —</option>
                                    @forelse(($unpaidTaxes ?? collect()) as $tax)
                                        @php
                                            $charName = $tax->character->name ?? "Character #{$tax->character_id}";
                                            $period = $tax->period_start ? $tax->period_start->format('M d') . ' - ' . $tax->period_end->format('M d, Y') : ($tax->month ? $tax->month->format('M Y') : 'Unknown');
                                            $remaining = (float)$tax->amount_owed - (float)($tax->amount_paid ?? 0);
                                            $statusBadge = $tax->status === 'overdue' ? '⚠️' : ($tax->status === 'partial' ? '◐' : '');
                                        @endphp
                                        <option value="{{ $tax->id }}"
                                                data-amount="{{ $tax->amount_owed }}"
                                                data-remaining="{{ $remaining }}">
                                            {{ $statusBadge }} {{ $charName }} — {{ $period }} — {{ number_format($remaining, 0) }} ISK remaining
                                        </option>
                                    @empty
                                        <option value="" disabled>{{ trans('mining-manager::taxes.no_unpaid_invoices') }}</option>
                                    @endforelse
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>{{ trans('mining-manager::taxes.amount') }} (ISK)</label>
                                        <input type="number" class="form-control" id="rpAmount" min="1" required>
                                        <small class="text-muted">{{ trans('mining-manager::taxes.partial_payment_note') }}</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>{{ trans('mining-manager::taxes.payment_date') }}</label>
                                        <input type="date" class="form-control" id="rpDate" value="{{ now()->format('Y-m-d') }}" required>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>{{ trans('mining-manager::taxes.notes') }}</label>
                                <textarea class="form-control" id="rpNotes" rows="2" placeholder="e.g. Direct ISK transfer, partial payment..."></textarea>
                            </div>
                        </form>
                    </div>

                    {{-- Tab 2: Manual Entry (ad-hoc / mid-period) --}}
                    <div class="tab-pane fade" id="tabManualEntry" role="tabpanel">
                        <p class="text-muted small mb-3">
                            <i class="fas fa-info-circle mr-1"></i>
                            {{ trans('mining-manager::taxes.manual_entry_description') }}
                        </p>
                        <form id="manualEntryForm">
                            <div class="form-group">
                                <label>{{ trans('mining-manager::taxes.character') }}</label>
                                <select class="form-control" id="meCharacterId" required>
                                    <option value="">— {{ trans('mining-manager::taxes.select_character') }} —</option>
                                    @foreach(($corpCharacterIds ?? collect()) as $member)
                                        <option value="{{ $member->character_id }}">
                                            {{ $member->name }} ({{ $member->character_id }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>{{ trans('mining-manager::taxes.amount_isk') }}</label>
                                <input type="number" class="form-control" id="meAmount" min="1" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>{{ trans('mining-manager::taxes.period_start') }}</label>
                                        <input type="date" class="form-control" id="mePeriodStart" value="{{ now()->startOfMonth()->format('Y-m-d') }}" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>{{ trans('mining-manager::taxes.period_end') }}</label>
                                        <input type="date" class="form-control" id="mePeriodEnd" value="{{ now()->format('Y-m-d') }}" required>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>{{ trans('mining-manager::taxes.notes') }}</label>
                                <textarea class="form-control" id="meNotes" rows="2" placeholder="e.g. Character leaving corp, mid-period settlement..."></textarea>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('mining-manager::taxes.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="btnSubmitPayment" onclick="submitRecordPayment()">
                    <i class="fas fa-check mr-1"></i> {{ trans('mining-manager::taxes.record_payment') }}
                </button>
            </div>
        </div>
    </div>
</div>
<script>
// Switch submit button based on active tab
$('#manualEntryModal a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
    var target = $(e.target).attr('href');
    var btn = $('#btnSubmitPayment');
    if (target === '#tabManualEntry') {
        btn.html('<i class="fas fa-plus mr-1"></i> {{ trans("mining-manager::taxes.manual_entry_tab") }}');
        btn.attr('onclick', 'submitManualEntry()');
    } else {
        btn.html('<i class="fas fa-check mr-1"></i> {{ trans("mining-manager::taxes.record_payment") }}');
        btn.attr('onclick', 'submitRecordPayment()');
    }
});
</script>
@endif
@endsection
