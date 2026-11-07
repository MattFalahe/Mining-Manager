@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::ledger.mining_summary'))
@section('page_header', trans('mining-manager::ledger.mining_summary'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=10">
<style>
    .character-portrait {
        width: 32px;
        height: 32px;
        border-radius: 4px;
        vertical-align: middle;
        margin-right: 8px;
    }
    .ore-icon {
        width: 24px;
        height: 24px;
        margin: 0 2px;
        border-radius: 2px;
        vertical-align: middle;
    }
    .alt-badge {
        background-color: #17a2b8;
        color: white;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75rem;
        margin-left: 8px;
    }
    .system-badge {
        background-color: #6c757d;
        color: white;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75rem;
        margin-left: 4px;
    }
    .loading-spinner {
        text-align: center;
        padding: 20px;
    }
    .ore-type-section {
        margin: 15px 0;
    }
    .ore-category {
        display: inline-block;
        margin: 5px 10px 5px 0;
    }
    .ore-category-label {
        font-weight: bold;
        margin-right: 5px;
    }
    .system-link {
        color: #007bff;
        cursor: pointer;
        text-decoration: underline;
    }
    .system-link:hover {
        color: #0056b3;
    }
    .details-table {
        margin: 0;
        font-size: 0.875rem;
    }
    .details-table th {
        background-color: #e9ecef;
    }
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
<div class="mining-manager-wrapper mining-dashboard mining-ledger-summary">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ (Request::is('*/ledger') && !Request::is('*/ledger/*')) || Request::is('*/ledger/summary') ? 'active' : '' }}" href="{{ route('mining-manager.ledger.index') }}">
                    <i class="fas fa-layer-group"></i> {{ trans('mining-manager::ledger.mining_summary') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/ledger/my-mining') ? 'active' : '' }}" href="{{ route('mining-manager.ledger.my-mining') }}">
                    <i class="fas fa-user"></i> {{ trans('mining-manager::menu.my_mining') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/ledger/reprocessing') ? 'active' : '' }}" href="{{ route('mining-manager.ledger.reprocessing') }}">
                    <i class="fas fa-recycle"></i> {{ trans('mining-manager::ledger.reprocessing_calculator') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">

    {{-- FILTERS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i>
                        {{ trans('mining-manager::ledger.filters') }}
                    </h3>
                </div>
                <div class="card-body">
                    {{-- Two questions, one page: what a corporation's miners mined, or what came off its refineries --}}
                    @php
                        $moonsScope = $scope === \MiningManager\Services\Ledger\LedgerSummaryService::SCOPE_MOONS;
                        $scopeParams = ['month' => $month, 'group_by_main' => $groupByMain ? 1 : 0] + ($biweekly ? ['half' => $half] : []);
                        $oreLabels = [
                            'all' => trans('mining-manager::ledger.ore_all'),
                            'my_moons' => trans('mining-manager::ledger.ore_my_moons'),
                            'other_moons' => trans('mining-manager::ledger.ore_other_moons'),
                            'any_moons' => trans('mining-manager::ledger.ore_any_moons'),
                            'ore' => trans('mining-manager::ledger.regular_ore'),
                            'ice' => trans('mining-manager::ledger.ice'),
                            'gas' => trans('mining-manager::ledger.gas'),
                            'abyssal' => trans('mining-manager::ledger.abyssal'),
                            'triglavian' => trans('mining-manager::ledger.triglavian'),
                        ];
                        $periodLabel = $periodNav['label'] ?? $monthDate->format('F Y');
                        // A member only ever sees their own characters, so their hint speaks to them.
                        $scopeHint = ($moonsScope ? 'scope_moons_hint' : 'scope_members_hint') . ($isDirector ? '' : '_own');
                    @endphp
                    <div class="btn-group mb-2" role="group">
                        <a href="{{ route('mining-manager.ledger.summary', ['scope' => 'members'] + $scopeParams) }}"
                           class="btn btn-sm {{ $moonsScope ? 'btn-outline-secondary' : 'btn-primary' }}">
                            <i class="fas fa-users"></i> {{ trans('mining-manager::ledger.scope_members') }}
                        </a>
                        <a href="{{ route('mining-manager.ledger.summary', ['scope' => 'moons'] + $scopeParams) }}"
                           class="btn btn-sm {{ $moonsScope ? 'btn-primary' : 'btn-outline-secondary' }}">
                            <i class="fas fa-moon"></i> {{ trans('mining-manager::ledger.scope_moons') }}
                        </a>
                    </div>
                    <p class="text-muted small mb-3">
                        {{ trans('mining-manager::ledger.' . $scopeHint) }}
                    </p>

                    {{-- While the Moon Owner bills twice a month, each half is billed on its own --}}
                    @if($periodNav)
                        <div class="d-flex flex-wrap align-items-center mb-3">
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
                            <div class="btn-group btn-group-sm mb-1" role="group">
                                @foreach($periodNav['choices'] as $choice)
                                    @if($choice['url'])
                                        <a href="{{ $choice['url'] }}" class="btn {{ $choice['active'] ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $choice['label'] }}</a>
                                    @else
                                        <span class="btn btn-outline-secondary disabled">{{ $choice['label'] }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <form method="GET" action="{{ route('mining-manager.ledger.summary') }}" id="filterForm">
                        <input type="hidden" name="scope" value="{{ $scope }}">
                        @if($biweekly)
                            <input type="hidden" name="half" id="half" value="{{ $half }}">
                        @endif
                        <div class="row">
                            {{-- Month Selector --}}
                            <div class="col-lg col-md-6">
                                <div class="form-group">
                                    <label for="month">{{ trans('mining-manager::ledger.month') }}</label>
                                    <input type="month" class="form-control" id="month" name="month" value="{{ $month }}" required>
                                </div>
                            </div>

                            {{-- Corporation Filter: a member only ever sees their own rows, so it is a director's --}}
                            @if($isDirector)
                                <div class="col-lg col-md-6">
                                    <div class="form-group">
                                        <label for="corporation_id">{{ $moonsScope ? trans('mining-manager::ledger.refinery_owner') : trans('mining-manager::ledger.corporation') }}</label>
                                        <select class="form-control" id="corporation_id" name="corporation_id">
                                            <option value="">{{ $moonsScope ? trans('mining-manager::ledger.all_refineries') : trans('mining-manager::ledger.all_corporations') }}</option>
                                            @foreach($corporations as $corp)
                                                <option value="{{ $corp->corporation_id }}" {{ $selectedCorporationId == $corp->corporation_id ? 'selected' : '' }}>
                                                    {{ $corp->ticker ? '[' . $corp->ticker . '] ' : '' }}{{ $corp->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            @endif

                            {{-- Ore: the slices the Performance Charts offer; the moons view is moon ore already --}}
                            @unless($moonsScope)
                                <div class="col-lg col-md-6">
                                    <div class="form-group">
                                        <label for="ore">{{ trans('mining-manager::ledger.ore_filter') }}</label>
                                        <select class="form-control" id="ore" name="ore">
                                            @foreach($oreLabels as $oreKey => $oreLabel)
                                                <option value="{{ $oreKey }}" {{ $ore === $oreKey ? 'selected' : '' }}>{{ $oreLabel }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            @endunless

                            {{-- Group by Main Toggle --}}
                            <div class="col-lg col-md-6">
                                <div class="form-group">
                                    <label for="group_by_main">{{ trans('mining-manager::ledger.view_mode') }}</label>
                                    <select class="form-control" id="group_by_main" name="group_by_main">
                                        <option value="1" {{ $groupByMain ? 'selected' : '' }}>{{ trans('mining-manager::ledger.group_by_main') }}</option>
                                        <option value="0" {{ !$groupByMain ? 'selected' : '' }}>{{ trans('mining-manager::ledger.show_all_characters') }}</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Apply Button --}}
                            <div class="col-lg col-md-6">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary btn-block">
                                        <i class="fas fa-search"></i> {{ trans('mining-manager::ledger.apply_filters') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- SUMMARY STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-bar"></i>
                        {{ trans('mining-manager::ledger.summary_statistics') }} - {{ $periodLabel }}
                        @if($ore !== 'all')
                            <span class="badge badge-info ml-2">
                                <i class="fas fa-filter"></i> {{ $oreLabels[$ore] }}
                            </span>
                        @endif
                        @if($isCurrentMonth)
                            <span class="badge badge-warning ml-2">
                                <i class="fas fa-clock"></i> {{ trans('mining-manager::ledger.live') }}
                            </span>
                        @elseif($fromFinalized)
                            <span class="badge badge-success ml-2">
                                <i class="fas fa-check-circle"></i> {{ trans('mining-manager::ledger.finalized') }}
                            </span>
                        @else
                            <span class="badge badge-secondary ml-2">
                                <i class="fas fa-database"></i> {{ trans('mining-manager::ledger.from_ledger') }}
                            </span>
                        @endif
                    </h3>
                </div>
                <div class="card-body">
                    @if($ore === 'other_moons')
                        <div class="alert alert-info py-2">
                            <i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.other_moons_inferred') }}
                        </div>
                    @endif
                    @if($classificationCutover)
                        <div class="alert alert-info py-2">
                            <i class="fas fa-clock"></i>
                            {{ trans('mining-manager::analytics.classification_cutover_note', ['date' => $classificationCutover->format('j M Y')]) }}
                        </div>
                    @endif
                    <div class="row">
                        {{-- Total Value --}}
                        <div class="col-lg col-md-6">
                            <div class="info-box bg-gradient-success">
                                <span class="info-box-icon">
                                    <i class="fas fa-coins"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_value') }}</span>
                                    <span class="info-box-number">{{ number_format($totals['total_value'], 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>

                        {{-- Total Tax: a bill covers all of a character's mining, so the moons view leaves it out --}}
                        @if(!$moonsScope)
                        <div class="col-lg col-md-6">
                            <div class="info-box bg-gradient-warning">
                                <span class="info-box-icon">
                                    <i class="fas fa-percent"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_tax') }}</span>
                                    <span class="info-box-number">{{ number_format($totals['total_tax'], 0) }}</span>
                                    <small>ISK</small>
                                    @if($ore === 'other_moons')
                                        <small class="d-block text-light">{{ trans('mining-manager::ledger.other_moons_untaxed') }}</small>
                                    @elseif($ore !== 'all')
                                        <small class="d-block text-light">{{ trans('mining-manager::ledger.tax_on_slice', ['slice' => $oreLabels[$ore]]) }}</small>
                                    @endif
                                    @foreach($halfTaxes as $halfTax)
                                        <small class="d-block text-light">{{ $halfTax['label'] }}: {{ number_format($halfTax['tax'], 0) }} ISK</small>
                                    @endforeach
                                    @if(!empty($totals['event_discount_total']) && $totals['event_discount_total'] > 0)
                                        <small class="d-block text-light" title="ISK waived over these days by event modifiers. Already taken off the tax above, shown here so it can be seen.">
                                            <i class="fas fa-gift"></i>
                                            incl. &minus;{{ number_format($totals['event_discount_total'], 0) }} ISK event discount
                                        </small>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- Total Quantity --}}
                        <div class="col-lg col-md-6">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon">
                                    <i class="fas fa-cubes"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_quantity') }}</span>
                                    <span class="info-box-number">{{ number_format($totals['total_quantity'], 0) }}</span>
                                    <small>{{ trans('mining-manager::ledger.units') }}</small>
                                </div>
                            </div>
                        </div>

                        {{-- Total Volume --}}
                        <div class="col-lg col-md-6">
                            <div class="info-box bg-gradient-teal">
                                <span class="info-box-icon">
                                    <i class="fas fa-box"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_volume') }}</span>
                                    <span class="info-box-number">{{ number_format($totals['total_volume_m3'] ?? 0, 0) }}</span>
                                    <small>m³</small>
                                </div>
                            </div>
                        </div>

                        {{-- Active Miners --}}
                        <div class="col-lg col-md-6">
                            <div class="info-box bg-gradient-primary">
                                <span class="info-box-icon">
                                    <i class="fas fa-users"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.active_miners') }}</span>
                                    <span class="info-box-number">{{ $summaries->count() }}</span>
                                    <small>{{ $groupByMain ? trans('mining-manager::ledger.mains') : trans('mining-manager::ledger.characters') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Moon ore by class, each with its share of the value above --}}
                    @if($moonsScope)
                        <div class="mt-2">
                            <strong class="mr-3">{{ trans('mining-manager::ledger.moon_ore_by_class') }}</strong>
                            @foreach($moonClasses as $moonClass)
                                @php
                                    $classValue = (float) ($totals['moon_' . $moonClass . '_value'] ?? 0);
                                    $classShare = $totals['total_value'] > 0 ? $classValue / $totals['total_value'] * 100 : 0;
                                @endphp
                                <span class="mr-3 text-nowrap">
                                    <span class="badge {{ \MiningManager\Services\Moon\MoonOreHelper::rarityBadgeClass($moonClass) }}">{{ strtoupper($moonClass) }}</span>
                                    {{ number_format($classValue, 0) }} ISK
                                    <small class="text-muted">({{ number_format($classShare, 1) }}%)</small>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- CHARACTER SUMMARIES TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::ledger.character_summaries') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info">{{ $summaries->count() }} {{ $groupByMain ? trans('mining-manager::ledger.mains') : trans('mining-manager::ledger.characters') }}</span>
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover table-striped" id="summaryTable">
                        <thead>
                            <tr>
                                <th class="sortable" onclick="sortTable('character_name')">
                                    {{ trans('mining-manager::ledger.character') }}
                                    <i class="fas fa-sort{{ $sortBy === 'character_name' ? ($sortDir === 'asc' ? '-up active' : '-down active') : '' }} sort-icon{{ $sortBy === 'character_name' ? ' active' : '' }}"></i>
                                </th>
                                <th>{{ trans('mining-manager::ledger.ore_types_mined') }}</th>
                                <th class="text-right sortable" onclick="sortTable('total_quantity')">
                                    {{ trans('mining-manager::ledger.total_quantity') }}
                                    <i class="fas fa-sort{{ $sortBy === 'total_quantity' ? ($sortDir === 'asc' ? '-up active' : '-down active') : '' }} sort-icon{{ $sortBy === 'total_quantity' ? ' active' : '' }}"></i>
                                </th>
                                <th class="text-right">{{ trans('mining-manager::ledger.total_volume') }}</th>
                                <th class="text-right sortable" onclick="sortTable('total_value')">
                                    {{ trans('mining-manager::ledger.total_value') }}
                                    <i class="fas fa-sort{{ $sortBy === 'total_value' ? ($sortDir === 'asc' ? '-up active' : '-down active') : '' }} sort-icon{{ $sortBy === 'total_value' ? ' active' : '' }}"></i>
                                </th>
                                <th>{{ trans('mining-manager::ledger.primary_system') }}</th>
                                <th class="text-center" style="width: 120px;">{{ trans('mining-manager::ledger.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($summaries as $summary)
                                <tr>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $summary->character_id }}/portrait?size=32"
                                             class="character-portrait"
                                             alt="{{ $summary->character_info['name'] ?? $summary->character_name }}">
                                        <strong>{{ $summary->character_info['name'] ?? $summary->character_name }}</strong>

                                        {{-- Corporation Badge --}}
                                        @if(isset($summary->character_info['corporation_name']))
                                            <br>
                                            <span class="badge badge-secondary" style="font-size: 85%;">
                                                <img src="https://images.evetech.net/corporations/{{ $summary->character_info['corporation_id'] }}/logo?size=32"
                                                     style="width: 16px; height: 16px; vertical-align: middle; margin-right: 3px;"
                                                     alt="">
                                                {{ $summary->character_info['corporation_name'] }}
                                            </span>
                                        @endif

                                        {{-- Unregistered Badge --}}
                                        @if(isset($summary->character_info['is_registered']) && !$summary->character_info['is_registered'])
                                            <span class="badge badge-warning" style="font-size: 85%; margin-left: 5px;">
                                                <i class="fas fa-exclamation-triangle"></i> {{ trans('mining-manager::ledger.not_registered') }}
                                            </span>
                                        @endif

                                        {{-- Alt Count Badge --}}
                                        @if(isset($summary->alt_count) && $summary->alt_count > 0)
                                            <span class="alt-badge">+{{ $summary->alt_count }} {{ trans('mining-manager::ledger.alts') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(isset($summary->ore_type_ids) && count($summary->ore_type_ids) > 0)
                                            @foreach(array_slice($summary->ore_type_ids, 0, 10) as $typeId)
                                                <img src="https://images.evetech.net/types/{{ $typeId }}/icon?size=32"
                                                     class="ore-icon"
                                                     title="Type ID: {{ $typeId }}"
                                                     alt="Ore">
                                            @endforeach
                                            @if(count($summary->ore_type_ids) > 10)
                                                <span class="badge badge-secondary">+{{ count($summary->ore_type_ids) - 10 }}</span>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($summary->total_quantity, 0) }}</td>
                                    <td class="text-right">{{ number_format($summary->total_volume_m3 ?? 0, 0) }} m³</td>
                                    <td class="text-right"><strong>{{ number_format($summary->total_value, 0) }} ISK</strong></td>
                                    <td>
                                        @if(isset($summary->primary_system))
                                            {{ $summary->primary_system->solarSystem->name ?? 'Unknown' }}
                                            @if($summary->system_count > 1)
                                                <span class="system-badge">+{{ $summary->system_count - 1 }} {{ trans('mining-manager::ledger.more') }}</span>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <a href="{{ route('mining-manager.ledger.character-details', [
                                                'characterId' => $summary->character_id,
                                                'month' => $month,
                                                'include_alts' => $groupByMain ? '1' : '0'
                                            ] + ($biweekly ? ['half' => $half] : [])) }}"
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i> {{ trans('mining-manager::ledger.view_details') }}
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center">
                                        <em>{{ trans('mining-manager::ledger.no_mining_data') }}</em>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
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
// A different month opens on its own default period, not the half picked
// for the month before.
document.getElementById('month').addEventListener('change', function () {
    const half = document.getElementById('half');
    if (half) {
        half.value = '';
    }
});

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

    // Navigate to new URL
    window.location.href = url.toString();
}
</script>
@endpush
