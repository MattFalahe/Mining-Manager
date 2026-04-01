@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.tax_details'))
@section('page_header', trans('mining-manager::taxes.tax_details') . ' - ' . ($tax->character_info['name'] ?? $tax->character->name ?? 'Unknown'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard taxes-details-page">

@include('mining-manager::taxes.partials.tab-navigation')

<div class="tax-details">

    {{-- Breadcrumb --}}
    <div class="row">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-dark">
                    <li class="breadcrumb-item"><a href="{{ route('mining-manager.taxes.index') }}">{{ trans('mining-manager::taxes.tax_overview') }}</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('mining-manager.taxes.my-taxes') }}">{{ trans('mining-manager::taxes.my_taxes') }}</a></li>
                    <li class="breadcrumb-item active">{{ trans('mining-manager::taxes.details') }}</li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- Tax Method Indicator --}}
    @if(($taxCalculationMethod ?? 'individually') === 'accumulated')
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info alert-sm">
                <i class="fas fa-users"></i> This tax is calculated using <strong>account-based taxation</strong> (all characters combined).
            </div>
        </div>
    </div>
    @endif

    {{-- Character & Tax Info --}}
    <div class="row">
        <div class="col-md-4">
            <div class="card card-dark card-outline">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.character_info') }}</h3>
                </div>
                <div class="card-body box-profile">
                    <div class="text-center">
                        <img class="profile-user-img img-fluid img-circle"
                             src="https://images.evetech.net/characters/{{ $tax->character_id }}/portrait?size=128">
                    </div>
                    <h3 class="profile-username text-center">{{ $tax->character_info['name'] ?? $tax->character->name ?? 'Unknown' }}</h3>
                    <p class="text-muted text-center">{{ $tax->character_info['corporation_name'] ?? $tax->affiliation->corporation_name ?? 'Unknown' }}</p>

                    <ul class="list-group list-group-unbordered mb-3">
                        <li class="list-group-item bg-dark">
                            <b>Period</b>
                            <span class="float-right">{{ $tax->formatted_period ?? \Carbon\Carbon::parse($tax->month)->format('F Y') }}</span>
                        </li>
                        <li class="list-group-item bg-dark">
                            <b>{{ trans('mining-manager::taxes.status') }}</b>
                            <span class="float-right">
                                @switch($tax->status)
                                    @case('paid')
                                        <span class="badge badge-success">{{ trans('mining-manager::taxes.paid') }}</span>
                                        @break
                                    @case('unpaid')
                                        <span class="badge badge-warning">{{ trans('mining-manager::taxes.unpaid') }}</span>
                                        @break
                                    @case('overdue')
                                        <span class="badge badge-danger">{{ trans('mining-manager::taxes.overdue') }}</span>
                                        @break
                                    @case('partial')
                                        <span class="badge badge-info">{{ trans('mining-manager::taxes.partial') }}</span>
                                        @break
                                @endswitch
                            </span>
                        </li>
                        <li class="list-group-item bg-dark">
                            <b>{{ trans('mining-manager::taxes.tax_code') }}</b>
                            <span class="float-right">
                                @php
                                    $activeTaxCode = $tax->taxCodes->where('status', 'active')->first()
                                                   ?? $tax->taxCodes->where('status', 'used')->first();
                                @endphp
                                @if($activeTaxCode)
                                    <code>{{ $activeTaxCode->getFullCode() }}</code>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </span>
                        </li>
                    </ul>

                    <button class="btn btn-info btn-block" onclick="window.print()">
                        <i class="fas fa-print"></i> {{ trans('mining-manager::taxes.print') }}
                    </button>

                    @if(auth()->user()->can('mining-manager.admin'))
                    <hr>
                    @if($tax->status !== 'paid')
                    <button class="btn btn-success btn-block" data-toggle="modal" data-target="#markPaidModal">
                        <i class="fas fa-check"></i> Mark as Paid
                    </button>
                    @endif
                    <div class="form-group mt-2">
                        <label class="text-muted"><small>Change Status</small></label>
                        <select class="form-control form-control-sm" id="statusSelect">
                            <option value="" disabled selected>-- Select --</option>
                            <option value="unpaid" {{ $tax->status === 'unpaid' ? 'disabled' : '' }}>Unpaid</option>
                            <option value="paid" {{ $tax->status === 'paid' ? 'disabled' : '' }}>Paid</option>
                            <option value="overdue" {{ $tax->status === 'overdue' ? 'disabled' : '' }}>Overdue</option>
                            <option value="exempted" {{ $tax->status === 'exempted' ? 'disabled' : '' }}>Exempted</option>
                        </select>
                    </div>
                    @if($tax->status !== 'paid')
                    <button class="btn btn-danger btn-block mt-2" id="deleteTaxBtn">
                        <i class="fas fa-trash"></i> Delete Record
                    </button>
                    @endif
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.tax_summary') }}</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-box bg-gradient-primary">
                                <span class="info-box-icon"><i class="fas fa-calculator"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.total_mined_value') }}</span>
                                    <span class="info-box-number">{{ number_format($miningTotal ?? 0, 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-box bg-gradient-danger">
                                <span class="info-box-icon"><i class="fas fa-receipt"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.amount_owed') }}</span>
                                    <span class="info-box-number">{{ number_format($tax->amount_owed, 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if($tax->status === 'paid')
                    <div class="callout callout-success">
                        <h5><i class="fas fa-check-circle"></i> {{ trans('mining-manager::taxes.payment_confirmed') }}</h5>
                        <p>{{ trans('mining-manager::taxes.paid_on') }}: {{ $tax->paid_at ? \Carbon\Carbon::parse($tax->paid_at)->format('F d, Y') : 'N/A' }}</p>
                    </div>
                    @endif

                    {{-- Triggered By --}}
                    @if($tax->triggered_by)
                    <div class="callout callout-secondary">
                        <h5><i class="fas fa-user-clock"></i> Triggered By</h5>
                        <p class="mb-0">{{ $tax->triggered_by }} &mdash; {{ $tax->calculated_at ? \Carbon\Carbon::parse($tax->calculated_at)->format('F d, Y H:i') : '' }}</p>
                    </div>
                    @endif

                    {{-- Notes (shows alt breakdown for accumulated taxes) --}}
                    @if($tax->notes)
                    <div class="callout callout-info">
                        <h5><i class="fas fa-info-circle"></i> Notes</h5>
                        <pre class="mb-0" style="color: #ccc; background: transparent; border: none; padding: 0;">{{ $tax->notes }}</pre>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Mining Breakdown --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.mining_breakdown') }}</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    @if(($taxCalculationMethod ?? 'individually') === 'accumulated')
                                    <th>Character</th>
                                    @endif
                                    <th>{{ trans('mining-manager::taxes.ore_type') }}</th>
                                    <th>Category</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.total_value') }}</th>
                                    <th class="text-right">Tax Rate</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.tax_amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($miningBreakdown ?? [] as $ore)
                                <tr>
                                    @if(($taxCalculationMethod ?? 'individually') === 'accumulated')
                                    <td>{{ $ore['character_name'] ?? '' }}</td>
                                    @endif
                                    <td>
                                        {{ $ore['type_name'] ?? 'Unknown' }}
                                        @if(!empty($ore['rarity']))
                                            <span class="badge badge-{{ $ore['rarity'] === 'r64' ? 'warning' : ($ore['rarity'] === 'r32' ? 'info' : 'secondary') }}">
                                                {{ strtoupper($ore['rarity']) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td><small class="text-muted">{{ ucfirst(str_replace('_', ' ', $ore['category'] ?? 'ore')) }}</small></td>
                                    <td class="text-right">{{ number_format($ore['quantity'] ?? 0, 0) }}</td>
                                    <td class="text-right">{{ number_format($ore['total_value'] ?? 0, 0) }} ISK</td>
                                    <td class="text-right">
                                        {{ number_format($ore['tax_rate'] ?? 0, 1) }}%
                                        @if(($ore['event_modifier'] ?? 0) != 0)
                                            <br><small class="text-{{ $ore['event_modifier'] < 0 ? 'success' : 'danger' }}">
                                                ({{ $ore['event_modifier'] > 0 ? '+' : '' }}{{ $ore['event_modifier'] }}% event)
                                            </small>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($ore['tax_amount'] ?? 0, 0) }} ISK</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="{{ ($taxCalculationMethod ?? 'individually') === 'accumulated' ? 7 : 6 }}" class="text-center text-muted">{{ trans('mining-manager::taxes.no_breakdown') }}</td>
                                </tr>
                                @endforelse
                            </tbody>
                            @if(!empty($miningBreakdown))
                            <tfoot class="bg-secondary">
                                <tr>
                                    @if(($taxCalculationMethod ?? 'individually') === 'accumulated')
                                    <td></td>
                                    @endif
                                    <td colspan="2"><strong>Total</strong></td>
                                    <td></td>
                                    <td class="text-right"><strong>{{ number_format(collect($miningBreakdown)->sum('total_value'), 0) }} ISK</strong></td>
                                    <td></td>
                                    <td class="text-right"><strong>{{ number_format(collect($miningBreakdown)->sum('tax_amount'), 0) }} ISK</strong></td>
                                </tr>
                            </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}

@if(auth()->user()->can('mining-manager.admin'))
{{-- Mark as Paid Modal --}}
<div class="modal fade" id="markPaidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">Mark as Paid</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Amount Paid (ISK)</label>
                    <input type="number" class="form-control" id="detailAmountPaid" value="{{ $tax->amount_owed }}">
                </div>
                <div class="form-group">
                    <label>Payment Date</label>
                    <input type="date" class="form-control" id="detailPaymentDate" value="{{ now()->format('Y-m-d') }}">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea class="form-control" id="detailNotes" rows="3" placeholder="e.g. Paid via external plugin, manual verification..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmDetailMarkPaid">
                    <i class="fas fa-check"></i> Confirm Payment
                </button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Mark as Paid
    $('#confirmDetailMarkPaid').on('click', function() {
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        $.ajax({
            url: '{{ route("mining-manager.taxes.mark-paid") }}',
            method: 'POST',
            data: {
                tax_id: {{ $tax->id }},
                amount_paid: $('#detailAmountPaid').val(),
                payment_date: $('#detailPaymentDate').val(),
                notes: $('#detailNotes').val()
            },
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function(response) {
                toastr.success(response.message || 'Marked as paid');
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || 'Error marking as paid');
                $('#confirmDetailMarkPaid').prop('disabled', false).html('<i class="fas fa-check"></i> Confirm Payment');
            }
        });
    });

    // Change Status
    $('#statusSelect').on('change', function() {
        var newStatus = $(this).val();
        if (!newStatus) return;

        if (confirm('Change status to "' + newStatus + '"?')) {
            $.ajax({
                url: '{{ url("mining-manager/tax") }}/{{ $tax->id }}/status',
                method: 'POST',
                data: { status: newStatus },
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                success: function(response) {
                    toastr.success(response.message || 'Status updated');
                    setTimeout(() => location.reload(), 1000);
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || 'Error updating status');
                    $('#statusSelect').val('');
                }
            });
        } else {
            $(this).val('');
        }
    });

    // Delete Tax Record
    $('#deleteTaxBtn').on('click', function() {
        if (confirm('Delete this tax record for {{ $tax->character_info["name"] ?? "Unknown" }} ({{ $tax->formatted_period ?? \Carbon\Carbon::parse($tax->month)->format("F Y") }})?\n\nThis action cannot be undone.')) {
            $.ajax({
                url: '{{ url("mining-manager/tax") }}/{{ $tax->id }}',
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                success: function(response) {
                    toastr.success(response.message || 'Tax record deleted');
                    setTimeout(() => window.location.href = '{{ route("mining-manager.taxes.index") }}', 1000);
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || 'Cannot delete this tax record');
                }
            });
        }
    });
});
</script>
@endpush
@endif
@endsection
