@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.wallet_verification'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=2">
@include('mining-manager::taxes.partials.datatables-styles')
@endpush

@section('full')
@include('mining-manager::partials.toastr')
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
                    <button class="btn btn-info" onclick="openManualEntry()">
                        <i class="fas fa-plus"></i> {{ trans('mining-manager::taxes.manual_entry') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Held credit, only worth showing when there is some --}}
    @if(($heldCredits ?? collect())->isNotEmpty())
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-piggy-bank mr-1"></i> {{ trans('mining-manager::taxes.held_credit') }}
                    </h3>
                </div>
                <div class="card-body">
                    <p class="text-muted small">{{ trans('mining-manager::taxes.held_credit_intro') }}</p>
                    <table class="table table-dark table-sm mb-0">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::taxes.character') }}</th>
                                <th class="text-right">{{ trans('mining-manager::taxes.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($heldCredits as $credit)
                            <tr>
                                <td>{{ $credit->character->name ?? "Character #{$credit->character_id}" }}</td>
                                <td class="text-right">{{ number_format($credit->remaining, 0) }} ISK</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
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
                            @php
                                $blocker = $transaction->blocker ?? null;
                                $blockerLabel = $blocker ? trans('mining-manager::taxes.blocker_' . $blocker) : null;
                                $blockerHelp = $blocker ? trans('mining-manager::taxes.blocker_' . $blocker . '_help') : null;
                                $blockerClass = match($blocker) {
                                    'no_tax_code' => 'badge-info',
                                    'tax_code_not_recognised' => 'badge-warning',
                                    'code_not_applied' => 'badge-primary',
                                    'before_cutover' => 'badge-secondary',
                                    default => 'badge-info',
                                };
                            @endphp
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
                                    @elseif($blockerLabel)
                                        <span class="badge {{ $blockerClass }}" title="{{ $blockerHelp }}">{{ $blockerLabel }}</span>
                                    @elseif($transaction->mismatch)
                                        <span class="badge badge-warning">{{ trans('mining-manager::taxes.mismatch') }}</span>
                                    @else
                                        <span class="badge badge-info">{{ trans('mining-manager::taxes.pending') }}</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    @if(!$transaction->verified && (($isDirector ?? false) || ($isAdmin ?? false)))
                                    <button class="btn btn-sm btn-primary mr-1"
                                            onclick="openAssign(this)"
                                            data-transaction-id="{{ $transaction->id }}"
                                            data-payer-id="{{ $transaction->first_party_id }}"
                                            data-payer-name="{{ $transaction->character_name }}"
                                            data-amount="{{ abs($transaction->amount) }}"
                                            title="{{ trans('mining-manager::taxes.assign_to_invoice') }}">
                                        <i class="fas fa-link"></i>
                                    </button>
                                    @if($blocker === 'code_not_applied')
                                    <button class="btn btn-sm btn-success mr-1" onclick="verifyTransaction({{ $transaction->id }})" title="{{ trans('mining-manager::taxes.verify') }}">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    @endif
                                    <button class="btn btn-sm btn-danger" onclick="dismissTransaction({{ $transaction->id }})" title="{{ trans('mining-manager::taxes.dismiss') }}">
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
// Which invoices each paying character is allowed to settle. Worked out server
// side because matching a player's alts needs the SeAT account link.
// Cast so an empty map serialises as {} rather than [], keeping the lookup
// below an object access in every case.
var payerInvoices = {!! json_encode((object) ($payerInvoiceMap ?? [])) !!};

function mmError(xhr, fallback) {
    var message = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : fallback;
    toastr.error(message);
}

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
    toastr.info('Rescanning the corporation wallet...');
    $.ajax({
        url: '{{ route("mining-manager.taxes.wallet.verify") }}',
        type: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            action: 'sync',
            days: 30
        },
        success: function(response) {
            toastr.success(response.message || 'Wallet rescanned');
            setTimeout(function() { location.reload(); }, 1200);
        },
        error: function(xhr) {
            mmError(xhr, 'Rescan failed');
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
            setTimeout(function() { location.reload(); }, 1200);
        },
        error: function(xhr) {
            mmError(xhr, 'Auto-match failed');
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
            setTimeout(function() { location.reload(); }, 1200);
        },
        error: function(xhr) {
            mmError(xhr, 'Verification failed');
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
            setTimeout(function() { location.reload(); }, 1200);
        },
        error: function(xhr) {
            mmError(xhr, 'Dismiss failed');
        }
    });
}

{{-- Assign a codeless payment to the invoice it was meant to settle --}}
function openAssign(button) {
    var $btn = $(button);
    var payerId = String($btn.data('payer-id'));
    var amount = parseFloat($btn.data('amount')) || 0;

    $('#apTransactionId').val($btn.data('transaction-id'));
    $('#apPayerName').text($btn.data('payer-name') || ('Character #' + payerId));
    $('#apAmount').text(amount.toLocaleString() + ' ISK');

    var allowed = payerInvoices[payerId] || [];
    var $select = $('#apTaxId');
    var matches = 0;

    $select.find('option').each(function() {
        var $option = $(this);
        var taxId = parseInt($option.val(), 10);

        if (!taxId) return;

        // Invoices belonging to someone else are hidden rather than removed,
        // so the list rebuilds correctly when a different row is opened.
        var belongs = allowed.indexOf(taxId) !== -1;
        $option.prop('hidden', !belongs).prop('disabled', !belongs);
        if (belongs) matches++;
    });

    $select.val('');
    $('#apNoInvoices').toggle(matches === 0);
    $('#apSubmit').prop('disabled', matches === 0);
    $('#apCascade').prop('checked', true);
    $('#apNotes').val('');

    $('#assignPaymentModal').appendTo('body').modal('show');
}

function submitAssign() {
    var taxId = $('#apTaxId').val();

    if (!taxId) {
        toastr.error('Pick the invoice this payment settles');
        return;
    }

    $('#apSubmit').prop('disabled', true);

    $.ajax({
        url: '{{ route("mining-manager.taxes.wallet.assign") }}',
        type: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            transaction_id: $('#apTransactionId').val(),
            tax_id: taxId,
            cascade: $('#apCascade').is(':checked') ? 1 : 0,
            notes: $('#apNotes').val()
        },
        success: function(response) {
            $('#assignPaymentModal').modal('hide');
            toastr.success(response.message || 'Payment assigned');
            setTimeout(function() { location.reload(); }, 1500);
        },
        error: function(xhr) {
            $('#apSubmit').prop('disabled', false);
            mmError(xhr, 'Could not assign this payment');
        }
    });
}

function openManualEntry() {
    $('#manualEntryModal').appendTo('body').modal('show');
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
            setTimeout(function() { location.reload(); }, 1200);
        },
        error: function(xhr) {
            mmError(xhr, 'Failed to record payment');
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
            setTimeout(function() { location.reload(); }, 1200);
        },
        error: function(xhr) {
            mmError(xhr, 'Failed to create manual entry');
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

{{-- Assign a wallet payment to an invoice (director/admin only) --}}
@if(($isDirector ?? false) || ($isAdmin ?? false))
<div class="modal fade" id="assignPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-link mr-2"></i>{{ trans('mining-manager::taxes.assign_payment_title') }}</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    <i class="fas fa-info-circle mr-1"></i>
                    {{ trans('mining-manager::taxes.assign_payment_intro') }}
                </p>

                <input type="hidden" id="apTransactionId">

                <div class="row mb-3">
                    <div class="col-md-6">
                        <small class="text-muted d-block">{{ trans('mining-manager::taxes.payment_from') }}</small>
                        <strong id="apPayerName">-</strong>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">{{ trans('mining-manager::taxes.payment_amount') }}</small>
                        <strong id="apAmount">-</strong>
                    </div>
                </div>

                <div class="form-group">
                    <label>{{ trans('mining-manager::taxes.invoices_for_payer') }}</label>
                    <select class="form-control" id="apTaxId">
                        <option value="">&mdash; {{ trans('mining-manager::taxes.select_invoice') }} &mdash;</option>
                        @foreach(($unpaidTaxes ?? collect()) as $tax)
                            @php
                                $charName = $tax->character->name ?? "Character #{$tax->character_id}";
                                $period = $tax->period_start
                                    ? $tax->period_start->format('M d') . ' - ' . $tax->period_end->format('M d, Y')
                                    : ($tax->month ? $tax->month->format('M Y') : 'Unknown');
                                $remaining = (float) $tax->amount_owed - (float) ($tax->amount_paid ?? 0);
                                $statusBadge = $tax->status === 'overdue' ? '(overdue)' : ($tax->status === 'partial' ? '(partial)' : '');
                            @endphp
                            <option value="{{ $tax->id }}" data-remaining="{{ $remaining }}">
                                {{ $charName }} &mdash; {{ $period }} &mdash; {{ number_format($remaining, 0) }} ISK outstanding {{ $statusBadge }}
                            </option>
                        @endforeach
                    </select>
                    <div id="apNoInvoices" class="text-warning small mt-2" style="display: none;">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        {{ trans('mining-manager::taxes.match_failed_no_open_invoice') }}
                    </div>
                </div>

                <div class="form-group">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="apCascade" checked>
                        <label class="custom-control-label" for="apCascade">
                            {{ trans('mining-manager::taxes.assign_cascade_label') }}
                        </label>
                    </div>
                    <small class="text-muted">{{ trans('mining-manager::taxes.assign_cascade_help') }}</small>
                </div>

                <div class="form-group">
                    <label>{{ trans('mining-manager::taxes.notes') }}</label>
                    <textarea class="form-control" id="apNotes" rows="2" placeholder="e.g. confirmed in corp chat"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('mining-manager::taxes.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="apSubmit" onclick="submitAssign()">
                    <i class="fas fa-link mr-1"></i> {{ trans('mining-manager::taxes.assign_to_invoice') }}
                </button>
            </div>
        </div>
    </div>
</div>
@endif

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
                                    <option value="">&mdash; {{ trans('mining-manager::taxes.select_invoice') }} &mdash;</option>
                                    @forelse(($unpaidTaxes ?? collect()) as $tax)
                                        @php
                                            $charName = $tax->character->name ?? "Character #{$tax->character_id}";
                                            $period = $tax->period_start ? $tax->period_start->format('M d') . ' - ' . $tax->period_end->format('M d, Y') : ($tax->month ? $tax->month->format('M Y') : 'Unknown');
                                            $remaining = (float)$tax->amount_owed - (float)($tax->amount_paid ?? 0);
                                            $statusBadge = $tax->status === 'overdue' ? '(overdue)' : ($tax->status === 'partial' ? '(partial)' : '');
                                        @endphp
                                        <option value="{{ $tax->id }}"
                                                data-amount="{{ $tax->amount_owed }}"
                                                data-remaining="{{ $remaining }}">
                                            {{ $statusBadge }} {{ $charName }} &mdash; {{ $period }} &mdash; {{ number_format($remaining, 0) }} ISK remaining
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
                                    <option value="">&mdash; {{ trans('mining-manager::taxes.select_character') }} &mdash;</option>
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
@push('javascript')
<script>
// Switch submit button based on active tab
$(document).on('shown.bs.tab', '#manualEntryModal a[data-toggle="tab"]', function (e) {
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
@endpush
@endif
@endsection
