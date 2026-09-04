@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.account_balances'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=2">
@include('mining-manager::taxes.partials.datatables-styles')
@endpush

@section('full')
@include('mining-manager::partials.toastr')
<div class="mining-manager-wrapper mining-dashboard taxes-balances-page">

@include('mining-manager::taxes.partials.tab-navigation')

<div class="account-balances">

    {{-- Summary. Members see one number that is theirs; directors see the
         corporation-wide position. --}}
    <div class="row">
        <div class="col-md-4">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>{{ number_format($stats['total_held'], 0) }}</h3>
                    <p>
                        {{ $canSeeAll
                            ? trans('mining-manager::taxes.held_credit_total')
                            : trans('mining-manager::taxes.balance_available') }}
                        (ISK)
                    </p>
                </div>
                <div class="icon"><i class="fas fa-piggy-bank"></i></div>
            </div>
        </div>
        @if($canSeeAll)
        <div class="col-md-4">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>{{ $stats['holders'] }}</h3>
                    <p>{{ trans('mining-manager::taxes.balance_holders') }}</p>
                </div>
                <div class="icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
        @endif
        <div class="col-md-4">
            <div class="small-box bg-primary">
                <div class="inner">
                    <h3>{{ number_format($stats['total_drawn'], 0) }}</h3>
                    <p>{{ trans('mining-manager::taxes.balance_drawn_total') }} (ISK)</p>
                </div>
                <div class="icon"><i class="fas fa-arrow-right"></i></div>
            </div>
        </div>
        @if(($pendingRefundTotal ?? 0) > 0)
        {{-- The only figure here that is a promise rather than a fact: agreed
             to be returned, not yet seen leaving the wallet. --}}
        <div class="col-md-4">
            <div class="small-box bg-warning">
                <div class="inner">
                    <h3>{{ number_format($pendingRefundTotal, 0) }}</h3>
                    <p>{{ trans('mining-manager::taxes.refunds_outstanding') }} (ISK)</p>
                </div>
                <div class="icon"><i class="fas fa-undo"></i></div>
            </div>
        </div>
        @endif
    </div>

    {{-- How money gets here in the first place. Only worth explaining while
         upfront payments are switched on, otherwise the only route is
         overpaying an invoice. --}}
    <div class="row">
        <div class="col-12">
            <div class="callout callout-info">
                <p class="mb-0">{{ trans('mining-manager::taxes.balance_explained') }}</p>
                @if($upfrontKeyword)
                <p class="mb-0 mt-2">
                    {!! trans('mining-manager::taxes.balance_upfront_hint', ['keyword' => '<code>' . e($upfrontKeyword) . '</code>']) !!}
                </p>
                @endif
            </div>
        </div>
    </div>

    {{-- Balances --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-wallet mr-1"></i> {{ trans('mining-manager::taxes.account_balances') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    @if($credits->isEmpty())
                        <p class="text-muted p-3 mb-0">
                            {{ $canSeeAll
                                ? trans('mining-manager::taxes.held_credit_none')
                                : trans('mining-manager::taxes.balance_none_personal') }}
                        </p>
                    @else
                    <table class="table table-dark table-striped mb-0" id="balancesTable">
                        <thead>
                            <tr>
                                @if($canSeeAll)
                                <th>{{ trans('mining-manager::taxes.character') }}</th>
                                @endif
                                <th>{{ trans('mining-manager::taxes.date') }}</th>
                                <th class="text-right">{{ trans('mining-manager::taxes.balance_payment_total') }}</th>
                                <th class="text-right">{{ trans('mining-manager::taxes.balance_banked') }}</th>
                                <th class="text-right">{{ trans('mining-manager::taxes.balance_remaining') }}</th>
                                <th>{{ trans('mining-manager::taxes.balance_covered') }}</th>
                                @if($canSeeAll)
                                <th class="text-right"></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($credits as $credit)
                            @php
                                $spent = $drawdowns[$credit->id] ?? collect();
                                $given = ($refunds ?? collect())[$credit->id] ?? collect();
                            @endphp
                            <tr class="{{ (float) $credit->remaining <= 0 ? 'text-muted' : '' }}">
                                @if($canSeeAll)
                                <td>{{ $credit->character->name ?? "Character #{$credit->character_id}" }}</td>
                                @endif
                                <td>{{ $credit->created_at ? $credit->created_at->format('Y-m-d H:i') : '-' }}</td>
                                <td class="text-right">
                                    {{ number_format($credit->payment_total, 0) }}
                                    {{-- The split is the answer to "I sent 1.2b, where did it
                                         go?". Without it the row only shows the leftover and
                                         looks like the payment was smaller than it was. --}}
                                    @if($credit->settled_on_arrival > 0)
                                    <div class="small text-muted">
                                        {{ trans('mining-manager::taxes.balance_settled_on_arrival', [
                                            'amount' => number_format($credit->settled_on_arrival, 0),
                                        ]) }}
                                    </div>
                                    @endif
                                </td>
                                <td class="text-right">{{ number_format($credit->amount, 0) }}</td>
                                <td class="text-right">
                                    @if((float) $credit->remaining > 0)
                                        <strong class="text-success">{{ number_format($credit->remaining, 0) }}</strong>
                                    @else
                                        <span class="badge badge-secondary">{{ trans('mining-manager::taxes.balance_fully_used') }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($spent->isEmpty())
                                        <span class="text-muted">{{ trans('mining-manager::taxes.balance_not_used_yet') }}</span>
                                    @else
                                        @foreach($spent as $use)
                                        <div>
                                            <a href="{{ route('mining-manager.taxes.details', $use->mining_tax_id) }}">
                                                {{ $use->tax && $use->tax->period_start
                                                    ? $use->tax->period_start->format('M Y')
                                                    : trans('mining-manager::taxes.view_tax') }}
                                            </a>
                                            <span class="text-muted">
                                                &mdash; {{ number_format($use->amount, 0) }} ISK
                                                on {{ $use->allocated_at ? $use->allocated_at->format('Y-m-d') : '' }}
                                            </span>
                                        </div>
                                        @endforeach
                                    @endif

                                    @foreach($given as $refund)
                                    <div class="mt-1">
                                        <i class="fas fa-undo text-warning mr-1"></i>
                                        <span class="text-muted">
                                            {{ trans('mining-manager::taxes.refund_balance') }}
                                            &mdash; {{ number_format($refund->amount, 0) }} ISK
                                            on {{ $refund->created_at ? $refund->created_at->format('Y-m-d') : '' }}
                                        </span>
                                        @if($refund->status === \MiningManager\Models\PaymentRefund::STATUS_PENDING)
                                            <span class="badge badge-warning" title="{{ trans('mining-manager::taxes.refund_pending_note') }}">
                                                {{ trans('mining-manager::taxes.refund_pending') }}
                                            </span>
                                            @if($canSeeAll)
                                            <button type="button" class="btn btn-link btn-sm p-0 ml-1 align-baseline"
                                                    onclick="openMarkSent({{ $refund->id }}, {{ (float) $refund->amount }}, '{{ addslashes($credit->character->name ?? "Character #{$credit->character_id}") }}')">
                                                {{ trans('mining-manager::taxes.refund_mark_sent') }}
                                            </button>
                                            @endif
                                        @elseif($refund->wasConfirmedByHand())
                                            {{-- Deliberately not the same badge as a matched one. This
                                                 is a director saying the money went out; the other is
                                                 the wallet showing it. --}}
                                            <span class="badge badge-info" title="{{ trans('mining-manager::taxes.refund_by_hand_note') }}">
                                                {{ trans('mining-manager::taxes.refund_by_hand') }}
                                            </span>
                                            @if($canSeeAll)
                                            <button type="button" class="btn btn-link btn-sm p-0 ml-1 align-baseline text-muted"
                                                    onclick="reopenRefund({{ $refund->id }})">
                                                {{ trans('mining-manager::taxes.refund_reopen') }}
                                            </button>
                                            @endif
                                        @else
                                            <span class="badge badge-success" title="{{ trans('mining-manager::taxes.refund_confirmed_note') }}">
                                                {{ trans('mining-manager::taxes.refund_confirmed') }}
                                            </span>
                                        @endif
                                        <div class="small text-muted ml-4">{{ $refund->reason }}</div>
                                        @if($refund->confirmation_note)
                                        <div class="small text-muted ml-4">
                                            <i class="fas fa-user-check mr-1"></i>{{ $refund->confirmation_note }}
                                        </div>
                                        @endif
                                    </div>
                                    @endforeach
                                </td>
                                @if($canSeeAll)
                                <td class="text-right">
                                    @if((float) $credit->remaining > 0)
                                    <button type="button" class="btn btn-sm btn-outline-warning"
                                            onclick="openRefund({{ $credit->id }}, '{{ addslashes($credit->character->name ?? "Character #{$credit->character_id}") }}', {{ (float) $credit->remaining }})">
                                        <i class="fas fa-undo"></i> {{ trans('mining-manager::taxes.refund_balance') }}
                                    </button>
                                    @endif
                                </td>
                                @endif
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>

@if($canSeeAll)
{{-- Refund modal. Directors only: handing money back is not a member's call. --}}
<div class="modal fade" id="refundModal" tabindex="-1">
    <div class="modal-dialog" role="document">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-undo mr-2"></i>{{ trans('mining-manager::taxes.refund_title') }}</h5>
                <button type="button" class="close text-light" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">{{ trans('mining-manager::taxes.refund_intro') }}</p>

                {{-- The keyword is the whole reason this can tell itself apart
                     from an SRP payout, so it goes above everything else and in
                     a size somebody will actually copy. --}}
                <div class="alert alert-info py-2 px-3">
                    <div class="small mb-1">{{ trans('mining-manager::taxes.refund_keyword_instruction') }}</div>
                    <code id="rfKeyword" style="font-size: 1.1rem;">{{ $refundKeyword }}</code>
                    <button type="button" class="btn btn-sm btn-outline-info ml-2" onclick="copyRefundKeyword()">
                        <i class="fas fa-copy"></i> {{ trans('mining-manager::taxes.copy') }}
                    </button>
                    <div class="small mt-1">{{ trans('mining-manager::taxes.refund_keyword_why') }}</div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <small class="text-muted d-block">{{ trans('mining-manager::taxes.refund_holder') }}</small>
                        <strong id="rfHolder">-</strong>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">{{ trans('mining-manager::taxes.refund_available') }}</small>
                        <strong id="rfAvailable">-</strong>
                    </div>
                </div>

                <input type="hidden" id="rfCreditId">

                <div class="form-group">
                    <label for="rfAmount">{{ trans('mining-manager::taxes.refund_amount') }}</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" id="rfAmount"
                           placeholder="{{ trans('mining-manager::taxes.refund_amount_help') }}">
                    <small class="form-text text-muted">{{ trans('mining-manager::taxes.refund_amount_help') }}</small>
                </div>

                <div class="form-group">
                    <label for="rfReason">{{ trans('mining-manager::taxes.refund_reason') }}</label>
                    <textarea class="form-control" id="rfReason" rows="2"
                              placeholder="{{ trans('mining-manager::taxes.refund_reason_placeholder') }}"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('mining-manager::taxes.cancel') }}</button>
                <button type="button" class="btn btn-warning" id="rfSubmit" onclick="submitRefund()">
                    <i class="fas fa-undo mr-1"></i> {{ trans('mining-manager::taxes.refund_balance') }}
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Marking a refund sent without a transfer behind it. Separate dialog rather
     than a bare confirm, because the note is the only record of why this was
     done and a confirm box cannot ask for one. --}}
<div class="modal fade" id="markSentModal" tabindex="-1">
    <div class="modal-dialog" role="document">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-check mr-2"></i>{{ trans('mining-manager::taxes.refund_mark_sent_title') }}</h5>
                <button type="button" class="close text-light" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">{{ trans('mining-manager::taxes.refund_mark_sent_intro') }}</p>

                <div class="alert alert-warning py-2 px-3 small">
                    {{ trans('mining-manager::taxes.refund_mark_sent_warning') }}
                </div>

                <input type="hidden" id="msRefundId">

                <div class="row mb-3">
                    <div class="col-md-6">
                        <small class="text-muted d-block">{{ trans('mining-manager::taxes.refund_holder') }}</small>
                        <strong id="msHolder">-</strong>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">{{ trans('mining-manager::taxes.refund_amount') }}</small>
                        <strong id="msAmount">-</strong>
                    </div>
                </div>

                <div class="form-group">
                    <label for="msNote">{{ trans('mining-manager::taxes.refund_mark_sent_note') }}</label>
                    <textarea class="form-control" id="msNote" rows="2" maxlength="255"
                              placeholder="{{ trans('mining-manager::taxes.refund_mark_sent_note_placeholder') }}"></textarea>
                    <small class="form-text text-muted">{{ trans('mining-manager::taxes.refund_mark_sent_note_help') }}</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('mining-manager::taxes.cancel') }}</button>
                <button type="button" class="btn btn-info" id="msSubmit" onclick="submitMarkSent()">
                    <i class="fas fa-user-check mr-1"></i> {{ trans('mining-manager::taxes.refund_mark_sent') }}
                </button>
            </div>
        </div>
    </div>
</div>
@endif

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/jquery.dataTables.min.js') }}"></script>
<script>
function copyRefundKeyword() {
    var text = $('#rfKeyword').text();

    // Clipboard API needs a secure context, which an internal SeAT install
    // often is not, so fall back to selecting it for a manual copy.
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            toastr.success('{{ trans("mining-manager::taxes.copied") }}');
        });
        return;
    }

    var range = document.createRange();
    range.selectNodeContents(document.getElementById('rfKeyword'));
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    toastr.info('{{ trans("mining-manager::taxes.copy_manual") }}');
}

function openRefund(creditId, holder, available) {
    $('#rfCreditId').val(creditId);
    $('#rfHolder').text(holder);
    $('#rfAvailable').text(Number(available).toLocaleString('en-US', {maximumFractionDigits: 0}) + ' ISK');
    $('#rfAmount').val('').attr('max', available);
    $('#rfReason').val('');
    $('#rfSubmit').prop('disabled', false);

    // AdminLTE traps the backdrop above the dialog unless the modal is moved
    // out of the content wrapper first.
    $('#refundModal').appendTo('body').modal('show');
}

function submitRefund() {
    var reason = $('#rfReason').val();

    if (!reason || !reason.trim()) {
        toastr.error('{{ trans("mining-manager::taxes.refund_failed_reason_required") }}');
        return;
    }

    $('#rfSubmit').prop('disabled', true);

    $.ajax({
        url: '{{ route("mining-manager.taxes.balances.refund") }}',
        type: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            credit_id: $('#rfCreditId').val(),
            // Empty means all of it, which the server reads as a full refund.
            amount: $('#rfAmount').val() || '',
            reason: reason
        },
        success: function(response) {
            $('#refundModal').modal('hide');
            toastr.success(response.message || 'Refund recorded');
            setTimeout(function() { location.reload(); }, 1500);
        },
        error: function(xhr) {
            $('#rfSubmit').prop('disabled', false);
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not record that refund';
            toastr.error(msg);
        }
    });
}

function openMarkSent(refundId, amount, holder) {
    $('#msRefundId').val(refundId);
    $('#msHolder').text(holder);
    $('#msAmount').text(Number(amount).toLocaleString('en-US', {maximumFractionDigits: 0}) + ' ISK');
    $('#msNote').val('');
    $('#msSubmit').prop('disabled', false);

    $('#markSentModal').appendTo('body').modal('show');
}

function submitMarkSent() {
    var note = $('#msNote').val();

    if (!note || !note.trim()) {
        toastr.error('{{ trans("mining-manager::taxes.refund_failed_note_required") }}');
        return;
    }

    $('#msSubmit').prop('disabled', true);

    $.ajax({
        url: '{{ route("mining-manager.taxes.balances.refund-sent", ["refundId" => "__ID__"]) }}'.replace('__ID__', $('#msRefundId').val()),
        type: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            note: note
        },
        success: function(response) {
            $('#markSentModal').modal('hide');
            toastr.success(response.message || 'Refund marked as sent');
            setTimeout(function() { location.reload(); }, 1500);
        },
        error: function(xhr) {
            $('#msSubmit').prop('disabled', false);
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not mark that refund as sent';
            toastr.error(msg);
        }
    });
}

function reopenRefund(refundId) {
    if (!confirm('{{ trans("mining-manager::taxes.refund_reopen_confirm") }}')) {
        return;
    }

    $.ajax({
        url: '{{ route("mining-manager.taxes.balances.refund-reopen", ["refundId" => "__ID__"]) }}'.replace('__ID__', refundId),
        type: 'POST',
        data: { _token: '{{ csrf_token() }}' },
        success: function(response) {
            toastr.success(response.message || 'Refund reopened');
            setTimeout(function() { location.reload(); }, 1500);
        },
        error: function(xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not reopen that refund';
            toastr.error(msg);
        }
    });
}

$(document).ready(function() {
    // Only worth paginating once the list is long enough to need it.
    if ($('#balancesTable tbody tr').length > 10) {
        $('#balancesTable').DataTable({
            pageLength: 25,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            order: [],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "No entries found",
                paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
            }
        });
    }
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
