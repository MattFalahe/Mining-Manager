@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::ledger.mining_ledger') . ' - ' . ($characterInfo['name'] ?? 'Character'))
@section('page_header', trans('mining-manager::ledger.mining_ledger') . ' - ' . ($characterInfo['name'] ?? 'Character'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=9">
<style>
    .sortable {
        cursor: pointer;
        user-select: none;
    }
    .sortable:hover {
        background-color: rgba(0,0,0,0.05);
    }
    .sort-icon {
        margin-left: 5px;
        font-size: 0.8em;
        opacity: 0.5;
    }
    .sort-icon.active {
        opacity: 1;
    }
</style>
@endpush

@section('full')
@php
    // Each kind of ore as the tax settings name it, moon ore in its rarity's colour.
    $oreBadge = function (string $class) {
        if (str_starts_with($class, 'moon')) {
            $rarity = substr($class, 5);

            return [
                $rarity !== '' ? \MiningManager\Services\Moon\MoonOreHelper::rarityBadgeClass($rarity) : 'badge-warning',
                trim(trans('mining-manager::ledger.moon_ore') . ' ' . strtoupper($rarity)),
            ];
        }

        return match ($class) {
            'ice' => ['badge-info', trans('mining-manager::ledger.ice')],
            'gas' => ['badge-success', trans('mining-manager::ledger.gas')],
            'abyssal' => ['badge-danger', trans('mining-manager::ledger.abyssal')],
            'triglavian' => ['badge-dark', trans('mining-manager::ledger.triglavian')],
            default => ['badge-secondary', trans('mining-manager::ledger.regular_ore')],
        };
    };
    $percent = fn ($rate) => rtrim(rtrim(number_format((float) $rate, 2), '0'), '.') . '%';
    $sortIcon = fn (string $column) => 'fas fa-sort' . ($sortBy === $column ? ($sortDir === 'asc' ? '-up' : '-down') . ' active' : '') . ' sort-icon' . ($sortBy === $column ? ' active' : '');
@endphp
<div class="mining-manager-wrapper mining-dashboard mining-ledger-details">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.ledger.index') }}">
                    <i class="fas fa-layer-group"></i> {{ trans('mining-manager::ledger.mining_summary') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.ledger.my-mining') }}">
                    <i class="fas fa-user"></i> {{ trans('mining-manager::menu.my_mining') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.ledger.reprocessing') }}">
                    <i class="fas fa-recycle"></i> {{ trans('mining-manager::ledger.reprocessing_calculator') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">

    {{-- CHARACTER HEADER --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <img src="https://images.evetech.net/characters/{{ $characterId }}/portrait?size=128"
                                 style="width: 64px; height: 64px; border-radius: 8px;"
                                 alt="{{ $characterInfo['name'] ?? 'Character' }}">
                        </div>
                        <div class="col">
                            <h3 class="mb-1">{{ $characterInfo['name'] ?? 'Unknown Character' }}</h3>
                            @if($showingMultiple ?? false)
                                <span class="badge badge-info">
                                    <i class="fas fa-users"></i> Showing Main + {{ $altCharacters->count() }} {{ trans('mining-manager::ledger.alts') }}
                                </span>
                            @endif
                            @if(isset($characterInfo['corporation_name']))
                                <p class="mb-0">
                                    <img src="https://images.evetech.net/corporations/{{ $characterInfo['corporation_id'] }}/logo?size=32"
                                         style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;"
                                         alt="">
                                    <strong>{{ $characterInfo['corporation_name'] }}</strong>
                                </p>
                            @endif
                            <p class="text-muted mb-0">{{ trans('mining-manager::ledger.mining_ledger') }} - {{ $periodNav['label'] }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ $summaryUrl }}" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> {{ trans('mining-manager::ledger.view_all') }}
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="d-flex flex-wrap align-items-center">
                        <div class="btn-group btn-group-sm mr-2 mb-1" role="group">
                            @foreach($quickPeriods as $quick)
                                <a href="{{ $quick['url'] }}" class="btn {{ $quick['active'] ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $quick['label'] }}</a>
                            @endforeach
                        </div>
                        <div class="btn-group btn-group-sm mr-2 mb-1" role="group" aria-label="{{ trans('mining-manager::ledger.period') }}">
                            @if($periodNav['prev'])
                                <a href="{{ $periodNav['prev'] }}" class="btn btn-outline-secondary" title="{{ trans('mining-manager::ledger.previous_period') }}"><i class="fas fa-chevron-left"></i></a>
                            @else
                                <span class="btn btn-outline-secondary disabled"><i class="fas fa-chevron-left"></i></span>
                            @endif
                            <span class="btn btn-secondary disabled">{{ $periodNav['label'] }}</span>
                            @if($periodNav['next'])
                                <a href="{{ $periodNav['next'] }}" class="btn btn-outline-secondary" title="{{ trans('mining-manager::ledger.next_period') }}"><i class="fas fa-chevron-right"></i></a>
                            @else
                                <span class="btn btn-outline-secondary disabled"><i class="fas fa-chevron-right"></i></span>
                            @endif
                        </div>
                        {{-- Biweekly installs bill each half of the month on its own --}}
                        @if($biweekly)
                            <div class="btn-group btn-group-sm mr-2 mb-1" role="group">
                                @foreach($periodNav['choices'] as $choice)
                                    @if($choice['url'])
                                        <a href="{{ $choice['url'] }}" class="btn {{ $choice['active'] ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $choice['label'] }}</a>
                                    @else
                                        <span class="btn btn-outline-secondary disabled">{{ $choice['label'] }}</span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        <div class="btn-group btn-group-sm ml-auto mb-1" role="group">
                            <a href="{{ $taxedLinks['all'] }}" class="btn {{ $taxedOnly ? 'btn-outline-secondary' : 'btn-primary' }}">
                                <i class="fas fa-list"></i> {{ trans('mining-manager::ledger.all_mining') }}
                            </a>
                            <a href="{{ $taxedLinks['taxed'] }}" class="btn {{ $taxedOnly ? 'btn-primary' : 'btn-outline-secondary' }}">
                                <i class="fas fa-percent"></i> {{ trans('mining-manager::ledger.taxed_only') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- SUMMARY STATISTICS --}}
    <div class="row mb-3">
        <div class="col-lg-4 col-md-6">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon">
                    <i class="fas fa-coins"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_value') }}</span>
                    <span class="info-box-number">{{ number_format($totals->total_value ?? 0, 0) }}</span>
                    <small>ISK</small>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="info-box bg-gradient-danger">
                <span class="info-box-icon">
                    <i class="fas fa-percent"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::ledger.estimated_tax') }}</span>
                    <span class="info-box-number">{{ number_format($totals->total_tax ?? 0, 0) }}</span>
                    <small>ISK</small>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon">
                    <i class="fas fa-cube"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_quantity') }}</span>
                    <span class="info-box-number">{{ number_format($totals->total_quantity ?? 0, 0) }}</span>
                    <small>{{ trans('mining-manager::ledger.units') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="info-box bg-gradient-teal">
                <span class="info-box-icon">
                    <i class="fas fa-box"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_volume') }}</span>
                    <span class="info-box-number">{{ number_format($totals->total_volume_m3 ?? 0, 0) }}</span>
                    <small>m³</small>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="info-box bg-gradient-warning">
                <span class="info-box-icon">
                    <i class="fas fa-map-marked-alt"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::ledger.unique_systems') }}</span>
                    <span class="info-box-number">{{ $totals->unique_systems ?? 0 }}</span>
                    <small>{{ trans('mining-manager::ledger.solar_system') }}s</small>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="info-box bg-gradient-primary">
                <span class="info-box-icon">
                    <i class="fas fa-gem"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::ledger.unique_ores') }}</span>
                    <span class="info-box-number">{{ $totals->unique_ores ?? 0 }}</span>
                    <small>{{ trans('mining-manager::ledger.ore_types') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- WHAT WAS TAXED, AND WHAT WAS NOT --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-percent"></i>
                        {{ trans('mining-manager::ledger.tax_on_this_mining') }}
                    </h3>
                </div>
                <div class="card-body">
                    <p class="text-muted small">{{ trans('mining-manager::ledger.tax_on_this_mining_hint') }}</p>
                    <div class="row">
                        <div class="col-lg-7">
                            <h5>{{ trans('mining-manager::ledger.taxed') }}</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>{{ trans('mining-manager::ledger.ore_type') }}</th>
                                            <th class="text-right">{{ trans('mining-manager::ledger.tax_rate') }}</th>
                                            <th class="text-right">{{ trans('mining-manager::ledger.value') }}</th>
                                            <th class="text-right">{{ trans('mining-manager::ledger.tax') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($taxedGroups as $group)
                                            @php
                                                [$badgeClass, $badgeLabel] = $oreBadge($group['class']);
                                            @endphp
                                            <tr>
                                                <td>
                                                    <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                                    <small class="d-block text-muted">{{ implode(', ', $group['ores']) }}</small>
                                                </td>
                                                <td class="text-right">{{ $percent($group['rate']) }}</td>
                                                <td class="text-right">{{ number_format($group['value'], 0) }} ISK</td>
                                                <td class="text-right">
                                                    {{ number_format($group['tax'], 0) }} ISK
                                                    @if($group['discount'] >= 1)
                                                        <small class="d-block text-success">{{ trans('mining-manager::ledger.event_discount_amount', ['amount' => number_format($group['discount'], 0)]) }}</small>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="text-center text-muted"><em>{{ trans('mining-manager::ledger.nothing_taxed') }}</em></td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                    @if($taxedGroups)
                                        <tfoot>
                                            <tr>
                                                <th colspan="2">{{ trans('mining-manager::ledger.total') }}</th>
                                                <th class="text-right">{{ number_format(array_sum(array_column($taxedGroups, 'value')), 0) }} ISK</th>
                                                <th class="text-right">{{ number_format($taxedTotal, 0) }} ISK</th>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <h5>{{ trans('mining-manager::ledger.not_taxed') }}</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>{{ trans('mining-manager::ledger.ore_type') }}</th>
                                            <th>{{ trans('mining-manager::ledger.why') }}</th>
                                            <th class="text-right">{{ trans('mining-manager::ledger.value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($untaxedGroups as $group)
                                            @php
                                                [$badgeClass, $badgeLabel] = $oreBadge($group['kind']);
                                            @endphp
                                            <tr>
                                                <td><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
                                                <td>
                                                    {{ trans('mining-manager::ledger.tax_reason_' . $group['reason']) }}
                                                    <small class="d-block text-muted">{{ trans('mining-manager::ledger.tax_reason_' . $group['reason'] . '_help') }}</small>
                                                </td>
                                                <td class="text-right">{{ number_format($group['value'], 0) }} ISK</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3" class="text-center text-muted"><em>{{ trans('mining-manager::ledger.everything_taxed') }}</em></td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                    @if($untaxedGroups)
                                        <tfoot>
                                            <tr>
                                                <th colspan="2">{{ trans('mining-manager::ledger.total') }}</th>
                                                <th class="text-right">{{ number_format(array_sum(array_column($untaxedGroups, 'value')), 0) }} ISK</th>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>
                        </div>
                    </div>
                    @if(abs($taxedTotal - ($totals->total_tax ?? 0)) >= 1)
                        <div class="mm-notice mm-notice-secondary mb-0">
                            <p class="mb-0"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::ledger.tax_billed_differs', ['billed' => number_format($totals->total_tax ?? 0, 0), 'rows' => number_format($taxedTotal, 0)]) }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- MINING ENTRIES TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        {{ trans('mining-manager::ledger.ledger_entries') }}
                    </h3>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th class="sortable" onclick="sortTable('date')">
                                    {{ trans('mining-manager::ledger.date') }}
                                    <i class="{{ $sortIcon('date') }}"></i>
                                </th>
                                @if($showingMultiple ?? false)
                                    <th class="sortable" onclick="sortTable('character_id')">
                                        {{ trans('mining-manager::ledger.character') }}
                                        <i class="{{ $sortIcon('character_id') }}"></i>
                                    </th>
                                @endif
                                <th>{{ trans('mining-manager::ledger.system') }}</th>
                                <th>{{ trans('mining-manager::ledger.ore_type') }}</th>
                                <th class="text-right sortable" onclick="sortTable('quantity')">
                                    {{ trans('mining-manager::ledger.quantity') }}
                                    <i class="{{ $sortIcon('quantity') }}"></i>
                                </th>
                                <th class="text-right">{{ trans('mining-manager::ledger.volume') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.price_per_unit') }}</th>
                                <th class="text-right sortable" onclick="sortTable('total_value')">
                                    {{ trans('mining-manager::ledger.total_value') }}
                                    <i class="{{ $sortIcon('total_value') }}"></i>
                                </th>
                                <th class="text-right sortable" onclick="sortTable('tax')">
                                    {{ trans('mining-manager::ledger.tax') }}
                                    <i class="{{ $sortIcon('tax') }}"></i>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($entries as $entry)
                                @php
                                    $tax = $taxes[$entry->id];
                                    [$badgeClass, $badgeLabel] = $oreBadge($tax['class']);
                                @endphp
                                <tr>
                                    <td>{{ $entry->date->format('Y-m-d') }}</td>
                                    @if($showingMultiple ?? false)
                                        <td>
                                            <img src="https://images.evetech.net/characters/{{ $entry->character_id }}/portrait?size=32"
                                                 style="width: 24px; height: 24px; border-radius: 4px; vertical-align: middle; margin-right: 5px;"
                                                 alt="">
                                            {{ $entry->character_name }}
                                        </td>
                                    @endif
                                    <td>
                                        {{ $entry->solarSystem->name ?? 'Unknown System' }}
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/types/{{ $entry->type_id }}/icon?size=32"
                                             style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;"
                                             alt="">
                                        {{ $entry->type->typeName ?? 'Unknown Ore' }}
                                        <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                    </td>
                                    <td class="text-right">{{ number_format($entry->quantity, 0) }}</td>
                                    <td class="text-right">{{ number_format($entry->quantity * ($entry->type->volume ?? 0), 2) }} m³</td>
                                    <td class="text-right">{{ number_format($entry->unit_price ?? 0, 2) }} ISK</td>
                                    <td class="text-right"><strong>{{ number_format($entry->total_value, 2) }} ISK</strong></td>
                                    <td class="text-right">
                                        @if($tax['taxed'])
                                            {{ number_format($tax['tax'], 2) }} ISK
                                            <small class="d-block text-muted">{{ $percent($tax['rate']) }}</small>
                                        @else
                                            <span class="text-muted">-</span>
                                            <small class="d-block text-muted">{{ trans('mining-manager::ledger.tax_reason_' . $tax['reason']) }}</small>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ ($showingMultiple ?? false) ? '9' : '8' }}" class="text-center">
                                        <em>{{ $taxedOnly ? trans('mining-manager::ledger.no_taxed_entries') : trans('mining-manager::ledger.no_entries') }}</em>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($entries->hasPages())
                    <div class="card-footer">
                        {{ $entries->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    </div>
    {{-- End card-body --}}

</div>
{{-- End card-tabs --}}

</div>
{{-- End mining-manager-wrapper --}}

@endsection

@push('javascript')
<script>
function sortTable(column) {
    const currentSort = '{{ $sortBy }}';
    const currentDir = '{{ $sortDir }}';

    // Toggle direction if clicking the same column, otherwise default to desc
    let newDir = 'desc';
    if (column === currentSort) {
        newDir = currentDir === 'asc' ? 'desc' : 'asc';
    }

    // Get current URL parameters
    const url = new URL(window.location.href);
    url.searchParams.set('sort_by', column);
    url.searchParams.set('sort_dir', newDir);
    url.searchParams.delete('page');

    // Navigate to new URL
    window.location.href = url.toString();
}
</script>
@endpush
