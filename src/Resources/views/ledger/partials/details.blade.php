<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ trans('mining-manager::ledger.entry_details') }}</h3>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <dl class="row">
                    <dt class="col-sm-4">{{ trans('mining-manager::ledger.date') }}:</dt>
                    <dd class="col-sm-8">{{ \Carbon\Carbon::parse($entry->date)->format('Y-m-d H:i:s') }}</dd>

                    <dt class="col-sm-4">{{ trans('mining-manager::ledger.character') }}:</dt>
                    <dd class="col-sm-8">
                        @if($entry->character)
                            <img src="https://images.evetech.net/characters/{{ $entry->character->character_id }}/portrait?size=32" 
                                 class="img-circle" style="width: 24px; height: 24px;">
                            {{ $entry->character->name }}
                        @else
                            {{ trans('mining-manager::ledger.unknown') }}
                        @endif
                    </dd>

                    <dt class="col-sm-4">{{ trans('mining-manager::ledger.ore_type') }}:</dt>
                    <dd class="col-sm-8">
                        @if($entry->type)
                            <img src="https://images.evetech.net/types/{{ $entry->type_id }}/icon?size=32" 
                                 style="width: 24px; height: 24px;">
                            {{ $entry->type->typeName }}
                        @else
                            {{ trans('mining-manager::ledger.unknown') }}
                        @endif
                    </dd>

                    <dt class="col-sm-4">{{ trans('mining-manager::ledger.solar_system') }}:</dt>
                    <dd class="col-sm-8">
                        @if($entry->solarSystem)
                            {{ $entry->solarSystem->name }}
                        @else
                            {{ trans('mining-manager::ledger.unknown') }}
                        @endif
                    </dd>
                </dl>
            </div>

            <div class="col-md-6">
                <dl class="row">
                    <dt class="col-sm-4">{{ trans('mining-manager::ledger.quantity') }}:</dt>
                    <dd class="col-sm-8">{{ number_format($entry->quantity, 2) }}</dd>

                    <dt class="col-sm-4">{{ trans('mining-manager::ledger.price') }}:</dt>
                    <dd class="col-sm-8">{{ number_format($entry->price, 2) }} ISK</dd>

                    <dt class="col-sm-4">{{ trans('mining-manager::ledger.total_value') }}:</dt>
                    <dd class="col-sm-8"><strong>{{ number_format($entry->total_value, 2) }} ISK</strong></dd>

                    <dt class="col-sm-4">{{ trans('mining-manager::ledger.tax_rate') }}:</dt>
                    <dd class="col-sm-8">{{ number_format($entry->tax_rate, 2) }}%</dd>

                    <dt class="col-sm-4">{{ trans('mining-manager::ledger.tax_amount') }}:</dt>
                    <dd class="col-sm-8"><strong>{{ number_format($entry->tax_amount, 2) }} ISK</strong></dd>
                </dl>
            </div>
        </div>

        @if($taxRecord)
            <hr>
            <h4>{{ trans('mining-manager::ledger.tax_record') }}</h4>
            <div class="row">
                <div class="col-md-12">
                    <dl class="row">
                        <dt class="col-sm-2">{{ trans('mining-manager::tax.month') }}:</dt>
                        <dd class="col-sm-10">{{ \Carbon\Carbon::parse($taxRecord->month)->format('F Y') }}</dd>

                        <dt class="col-sm-2">{{ trans('mining-manager::tax.amount') }}:</dt>
                        <dd class="col-sm-10">{{ number_format($taxRecord->amount, 2) }} ISK</dd>

                        <dt class="col-sm-2">{{ trans('mining-manager::tax.status') }}:</dt>
                        <dd class="col-sm-10">
                            @if($taxRecord->status === 'paid')
                                <span class="badge badge-success">{{ trans('mining-manager::tax.paid') }}</span>
                            @elseif($taxRecord->status === 'pending')
                                <span class="badge badge-warning">{{ trans('mining-manager::tax.pending') }}</span>
                            @elseif($taxRecord->status === 'overdue')
                                <span class="badge badge-danger">{{ trans('mining-manager::tax.overdue') }}</span>
                            @else
                                <span class="badge badge-secondary">{{ $taxRecord->status }}</span>
                            @endif
                        </dd>

                        @if($taxRecord->paid_at)
                            <dt class="col-sm-2">{{ trans('mining-manager::tax.paid_at') }}:</dt>
                            <dd class="col-sm-10">{{ \Carbon\Carbon::parse($taxRecord->paid_at)->format('Y-m-d H:i:s') }}</dd>
                        @endif

                        @if($taxRecord->due_date)
                            <dt class="col-sm-2">{{ trans('mining-manager::tax.due_date') }}:</dt>
                            <dd class="col-sm-10">{{ \Carbon\Carbon::parse($taxRecord->due_date)->format('Y-m-d') }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        @endif
    </div>
</div>
