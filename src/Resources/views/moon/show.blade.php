@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.extraction_details'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard moon-show-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon') && !Request::is('*/moon/*') ? 'active' : '' }}" href="{{ route('mining-manager.moon.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/active') ? 'active' : '' }}" href="{{ route('mining-manager.moon.active') }}">
                    <i class="fas fa-hourglass-half"></i> {{ trans('mining-manager::menu.active_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/calendar') ? 'active' : '' }}" href="{{ route('mining-manager.moon.calendar') }}">
                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.extraction_calendar') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/compositions') ? 'active' : '' }}" href="{{ route('mining-manager.moon.compositions') }}">
                    <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::menu.moon_compositions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/calculator') ? 'active' : '' }}" href="{{ route('mining-manager.moon.calculator') }}">
                    <i class="fas fa-flask"></i> {{ trans('mining-manager::menu.moon_value_calculator') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">


<div class="extraction-details">
    
    {{-- BACK BUTTON --}}
    <div class="row mb-3">
        <div class="col-12">
            <a href="{{ route('mining-manager.moon.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> {{ trans('mining-manager::moons.back_to_list') }}
            </a>
            @can('mining-manager.director')
            <form action="{{ route('mining-manager.moon.update', $extraction->id) }}" method="POST" style="display: inline;">
                @csrf
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::moons.refresh_data') }}
                </button>
            </form>
            @endcan

            {{-- Report Jackpot Button (only for ready/fractured extractions — chunk must have arrived) --}}
            @if(!$extraction->is_jackpot && in_array($extraction->getEffectiveStatus(), ['ready', 'fractured', 'unstable']))
                <form action="{{ route('mining-manager.moon.report-jackpot', $extraction->id) }}" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure this is a jackpot extraction? This will notify all configured webhooks.');">
                    @csrf
                    <button type="submit" class="btn btn-warning" style="background: linear-gradient(45deg, #ffd700, #ffed4e); color: #000; border: none;">
                        <i class="fas fa-star"></i> Report Jackpot
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- Jackpot Status --}}
    @if($extraction->is_jackpot)
    <div class="row mb-3">
        <div class="col-12">
            <div class="alert mb-0" style="background: linear-gradient(135deg, #ffd700, #ffed4e); color: #000; border: 2px solid #daa520;">
                <div class="d-flex align-items-center">
                    <i class="fas fa-star fa-2x mr-3"></i>
                    <div>
                        <h5 class="mb-1"><strong>JACKPOT EXTRACTION</strong></h5>
                        @if($extraction->jackpot_reported_by)
                            @php
                                $reporterName = \DB::table('character_infos')
                                    ->where('character_id', $extraction->jackpot_reported_by)
                                    ->value('name') ?? 'Character #' . $extraction->jackpot_reported_by;
                            @endphp
                            <span>Reported by <strong>{{ $reporterName }}</strong>
                            on {{ $extraction->jackpot_detected_at->format('M d, Y H:i') }}</span>
                            <br>
                            @if($extraction->jackpot_verified === true)
                                <span class="badge badge-success mt-1"><i class="fas fa-check-circle"></i> Verified by mining data
                                    {{ $extraction->jackpot_verified_at ? $extraction->jackpot_verified_at->format('M d, H:i') : '' }}
                                </span>
                            @elseif($extraction->jackpot_verified === false)
                                <span class="badge badge-danger mt-1"><i class="fas fa-times-circle"></i> Could not verify — no jackpot ores found in mining data</span>
                            @else
                                <span class="badge badge-secondary mt-1"><i class="fas fa-hourglass-half"></i> Awaiting verification from mining data</span>
                            @endif
                        @else
                            <span>Detected automatically on {{ $extraction->jackpot_detected_at->format('M d, Y H:i') }}</span>
                            <span class="badge badge-success mt-1 ml-2"><i class="fas fa-check-circle"></i> Verified</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- MAIN INFORMATION --}}
    <div class="row">
        <div class="col-md-8">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle"></i>
                        {{ trans('mining-manager::moons.extraction_information') }}
                    </h3>
                    <div class="card-tools">
                        @php $effectiveStatus = $extraction->getEffectiveStatus(); @endphp
                        <span class="badge badge-{{
                            $effectiveStatus === 'extracting' ? 'warning' :
                            ($effectiveStatus === 'ready' ? 'success' :
                            ($effectiveStatus === 'unstable' ? 'warning mm-badge-unstable' : 'secondary'))
                        }}">
                            {{ trans('mining-manager::moons.' . $effectiveStatus) }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>{{ trans('mining-manager::moons.structure') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                <i class="fas fa-building text-primary"></i>
                                {{ $extraction->structure_name ?? 'Unknown' }}
                            </p>

                            <p><strong>{{ trans('mining-manager::moons.moon') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                <i class="fas fa-moon text-info"></i>
                                {{ $extraction->moon_name ?? 'Unknown' }}
                            </p>

                            <p><strong>{{ trans('mining-manager::moons.corporation') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                {{ $extraction->corporation->name ?? 'Unknown' }}
                            </p>

                            <p><strong>{{ trans('mining-manager::moons.status') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                @php $effectiveStatus = $extraction->getEffectiveStatus(); @endphp
                                <span class="badge badge-lg badge-{{
                                    $effectiveStatus === 'extracting' ? 'warning' :
                                    ($effectiveStatus === 'ready' ? 'success' :
                                    ($effectiveStatus === 'unstable' ? 'warning mm-badge-unstable' : 'secondary'))
                                }}">
                                    {{ trans('mining-manager::moons.' . $effectiveStatus) }}
                                </span>
                            </p>
                        </div>

                        <div class="col-md-6">
                            <p><strong>{{ trans('mining-manager::moons.extraction_started') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                {{ $extraction->extraction_start_time->format('M d, Y H:i') }}<br>
                                <small class="text-muted">{{ $extraction->extraction_start_time->diffForHumans() }}</small>
                            </p>

                            <p><strong>{{ trans('mining-manager::moons.chunk_arrival') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                {{ $extraction->chunk_arrival_time->format('M d, Y H:i') }}<br>
                                <small class="text-muted">{{ $extraction->chunk_arrival_time->diffForHumans() }}</small>
                            </p>

                            @if($extraction->fractured_at)
                            <p><strong>Fractured:</strong></p>
                            <p class="ml-3 mb-3">
                                {{ $extraction->fractured_at->format('M d, Y H:i') }}<br>
                                <small class="text-muted">
                                    {{ $extraction->fractured_at->diffForHumans() }}
                                    @if($extraction->fractured_by)
                                        &mdash; by {{ $extraction->fractured_by }}
                                    @elseif($extraction->auto_fractured)
                                        &mdash; auto-fracture
                                    @endif
                                </small>
                            </p>
                            @endif

                            <p><strong>{{ trans('mining-manager::moons.natural_decay') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                @if($extraction->getExpiryTime())
                                    {{ $extraction->getExpiryTime()->format('M d, Y H:i') }}<br>
                                    <small class="text-muted">{{ $extraction->getExpiryTime()->diffForHumans() }}</small>
                                @else
                                    {{ $extraction->natural_decay_time->format('M d, Y H:i') }}<br>
                                    <small class="text-muted">{{ $extraction->natural_decay_time->diffForHumans() }}</small>
                                @endif
                                @if($extraction->isUnstable())
                                    <br><span class="badge badge-warning">{{ trans('mining-manager::moons.unstable') }}</span>
                                @endif
                            </p>

                            @if($estimatedValue)
                            <p><strong>{{ trans('mining-manager::moons.estimated_value') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                <span class="h4 text-success">{{ number_format($estimatedValue, 0) }} ISK</span>
                            </p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- COUNTDOWN TIMERS --}}
        <div class="col-md-4">
            @if($timeUntilArrival !== null && $timeUntilArrival > 0)
            <div class="card card-warning card-outline mb-3">
                <div class="card-body p-0">
                    <div class="mm-timer-box mm-timer-arrival">
                        <p class="mb-2">{{ trans('mining-manager::moons.chunk_arrives_in') }}</p>
                        <h2>{{ floor($timeUntilArrival / 24) }}d {{ $timeUntilArrival % 24 }}h</h2>
                        <small>{{ $extraction->chunk_arrival_time->format('M d, H:i') }}</small>
                    </div>
                </div>
            </div>
            @endif

            @if($timeUntilDecay !== null && $timeUntilDecay > 0)
            <div class="card card-{{ $extraction->isUnstable() ? 'warning' : 'danger' }} card-outline">
                <div class="card-body p-0">
                    <div class="mm-timer-box {{ $extraction->isUnstable() ? 'mm-timer-unstable' : 'mm-timer-fracture' }}">
                        @if($extraction->isUnstable())
                            <span class="badge badge-dark mb-2">{{ trans('mining-manager::moons.unstable') }}</span>
                            <p class="mb-2">{{ trans('mining-manager::moons.expires_in') ?? 'Expires in' }}</p>
                        @else
                            <p class="mb-2">{{ trans('mining-manager::moons.unstable_in') ?? 'Unstable in' }}</p>
                        @endif
                        <h2>{{ floor($timeUntilDecay / 24) }}d {{ $timeUntilDecay % 24 }}h</h2>
                        @if($extraction->fractured_at)
                            <small>Fractured: {{ $extraction->fractured_at->format('M d, H:i') }}{{ $extraction->fractured_by ? ' by ' . $extraction->fractured_by : '' }}</small>
                        @else
                            <small>{{ $extraction->getExpiryTime() ? $extraction->getExpiryTime()->format('M d, H:i') : '' }}</small>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            @if($extraction->status === 'ready')
            <div class="card card-success">
                <div class="card-body text-center">
                    <i class="fas fa-gem fa-3x mb-2 text-success"></i>
                    <h4>{{ trans('mining-manager::moons.ready_to_mine') }}!</h4>
                    <p class="text-muted">{{ trans('mining-manager::moons.chunk_available') }}</p>
                </div>
            </div>
            @endif
        </div>
    </div>

    {{-- ORE COMPOSITION --}}
    @if($extraction->ore_composition)
    <div class="row">
        <div class="col-12">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::moons.ore_composition') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::moons.ore_type') }}</th>
                                    <th style="width: 35%;">{{ trans('mining-manager::moons.percentage') }}</th>
                                    <th class="text-right">Quantity (Units)</th>
                                    <th class="text-right">Volume (m³)</th>
                                    <th class="text-right">{{ trans('mining-manager::moons.value') }}</th>
                                    <th class="text-center">Refines To</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $composition = is_string($extraction->ore_composition)
                                        ? json_decode($extraction->ore_composition, true)
                                        : $extraction->ore_composition;
                                    $colors = ['#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e67e22', '#34495e'];
                                    $colorIndex = 0;
                                @endphp
                                @foreach($composition as $oreType => $data)
                                <tr>
                                    <td>
                                        <i class="fas fa-cube" style="color: {{ $colors[$colorIndex % count($colors)] }}"></i>
                                        {{ $oreType }}
                                    </td>
                                    <td>
                                        <div class="mm-progress-wrap">
                                            <div class="progress" style="height: 25px;">
                                                <div class="ore-bar progress-bar"
                                                     style="width: {{ max($data['percentage'], 1) }}%; background-color: {{ $colors[$colorIndex % count($colors)] }};"></div>
                                            </div>
                                            <span class="mm-pct-label">{{ number_format($data['percentage'], 2) }}%</span>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <strong>{{ number_format($data['quantity'] ?? 0, 0) }}</strong> units
                                    </td>
                                    <td class="text-right text-info">
                                        {{ number_format($data['volume_m3'] ?? 0, 0) }} m³
                                    </td>
                                    <td class="text-right text-success">
                                        {{ number_format($data['value'] ?? 0, 0) }} ISK
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-xs btn-outline-warning"
                                                data-toggle="collapse"
                                                data-target="#minerals-{{ $colorIndex }}">
                                            <i class="fas fa-atom"></i> View Minerals
                                        </button>
                                    </td>
                                </tr>
                                <tr class="collapse" id="minerals-{{ $colorIndex }}">
                                    <td colspan="6" class="bg-dark">
                                        <div class="p-2">
                                            <strong>Refined Minerals from {{ number_format($data['quantity'] ?? 0, 0) }} units of {{ $oreType }}:</strong>
                                            <table class="table table-sm table-bordered mt-2">
                                                <thead>
                                                    <tr>
                                                        <th>Mineral</th>
                                                        <th class="text-right">Quantity</th>
                                                        <th class="text-right">Unit Price</th>
                                                        <th class="text-right">Total Value</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @php
                                                        // Get refined minerals for this ore
                                                        $minerals = \MiningManager\Services\Moon\MoonOreHelper::getRefinedMinerals($data['type_id'], $data['quantity'] ?? 0);
                                                    @endphp
                                                    @if(!empty($minerals))
                                                        @foreach($minerals as $mineral)
                                                        <tr>
                                                            <td><i class="fas fa-flask text-warning"></i> {{ $mineral['name'] }}</td>
                                                            <td class="text-right">{{ number_format($mineral['quantity'], 0) }}</td>
                                                            <td class="text-right">{{ number_format($mineral['price'], 2) }} ISK</td>
                                                            <td class="text-right text-success">{{ number_format($mineral['value'], 0) }} ISK</td>
                                                        </tr>
                                                        @endforeach
                                                    @else
                                                        <tr>
                                                            <td colspan="4" class="text-muted text-center">No mineral data available</td>
                                                        </tr>
                                                    @endif
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                                @php $colorIndex++; @endphp
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="font-weight-bold">
                                    <td colspan="3" class="text-right">{{ trans('mining-manager::moons.total_value') }}:</td>
                                    <td class="text-right text-success h5">
                                        {{ number_format($estimatedValue ?? 0, 0) }} ISK
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- Composition Visualization --}}
                    <div class="mt-4">
                        <h5>{{ trans('mining-manager::moons.composition_breakdown') }}</h5>
                        <div class="composition-chart">
                            <canvas id="compositionChart"></canvas>
                        </div>
                        <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::moons.note_composition') }}</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @else
    <div class="row">
        <div class="col-12">
            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        {{ trans('mining-manager::moons.no_composition_data') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h5><i class="fas fa-info-circle"></i> How to Get Moon Composition Data:</h5>
                        <ol>
                            <li><strong>Scan the Moon:</strong> Use a Survey Scanner probe in-game to scan this moon</li>
                            <li><strong>Wait for Sync:</strong> The composition data will be sent to ESI automatically</li>
                            <li><strong>Refresh SeAT:</strong> Wait for SeAT to sync (usually happens automatically within a few minutes)</li>
                            <li><strong>Update This Page:</strong> Click the "Refresh Data" button above to fetch the new composition</li>
                        </ol>
                    </div>
                    <p class="text-muted">
                        <i class="fas fa-moon"></i> <strong>Moon:</strong> {{ $extraction->moon_name ?? 'Unknown' }}<br>
                        <i class="fas fa-hashtag"></i> <strong>Moon ID:</strong> {{ $extraction->moon_id ?? 'N/A' }}
                    </p>
                    <p class="text-muted">
                        Once this moon is scanned, the ore composition, percentages, and estimated value will appear here.
                    </p>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- EXTRACTION HISTORY --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        {{ trans('mining-manager::moons.extraction_history') }}
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('mining-manager.moon.extractions', $extraction->structure_id) }}" class="btn btn-sm btn-info">
                            <i class="fas fa-list"></i> {{ trans('mining-manager::moons.view_all') }}
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    @if(isset($history) && $history->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-dark table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Extraction Date</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th class="text-right">Estimated Value</th>
                                        <th class="text-right">Actual Mined</th>
                                        <th class="text-center">Completion</th>
                                        <th>Ore Composition</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($history as $record)
                                    <tr>
                                        <td>
                                            <i class="fas fa-calendar-alt text-info"></i>
                                            {{ \Carbon\Carbon::parse($record->extraction_start_time)->format('M d, Y') }}
                                            <br>
                                            <small class="text-muted">{{ \Carbon\Carbon::parse($record->extraction_start_time)->diffForHumans() }}</small>
                                        </td>
                                        <td>
                                            <i class="fas fa-clock text-warning"></i>
                                            {{ $record->duration_days }} days
                                        </td>
                                        <td>
                                            <span class="badge badge-{{
                                                $record->final_status === 'ready' ? 'success' :
                                                ($record->final_status === 'expired' ? 'secondary' :
                                                ($record->final_status === 'fractured' ? 'danger' : 'warning'))
                                            }}">
                                                {{ ucfirst($record->final_status) }}
                                            </span>
                                            @if($record->is_jackpot)
                                            <span class="badge badge-warning ml-1">
                                                <i class="fas fa-gem"></i> Jackpot
                                            </span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if($record->final_estimated_value)
                                                <span class="text-success font-weight-bold">
                                                    {{ number_format($record->final_estimated_value, 0) }} ISK
                                                </span>
                                            @else
                                                <span class="text-muted">N/A</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if($record->actual_mined_value)
                                                <span class="text-info font-weight-bold">
                                                    {{ number_format($record->actual_mined_value, 0) }} ISK
                                                </span>
                                            @else
                                                <span class="text-muted">N/A</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($record->completion_percentage > 0)
                                                <div class="mm-progress-wrap">
                                                    <div class="progress" style="height: 20px; min-width: 80px;">
                                                        <div class="progress-bar bg-{{ $record->completion_percentage >= 80 ? 'success' : ($record->completion_percentage >= 50 ? 'warning' : 'danger') }}"
                                                             style="width: {{ min($record->completion_percentage, 100) }}%"></div>
                                                    </div>
                                                    <span class="mm-pct-label">{{ number_format($record->completion_percentage, 1) }}%</span>
                                                </div>
                                                <small class="text-muted">{{ $record->total_miners }} miners</small>
                                            @else
                                                <span class="text-muted">N/A</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($record->ore_composition)
                                                @php
                                                    $oreComp = is_string($record->ore_composition)
                                                        ? json_decode($record->ore_composition, true)
                                                        : $record->ore_composition;
                                                @endphp
                                                <button class="btn btn-xs btn-outline-info"
                                                        data-toggle="collapse"
                                                        data-target="#ore-details-{{ $record->id }}">
                                                    <i class="fas fa-eye"></i> View Ores
                                                </button>
                                                <div id="ore-details-{{ $record->id }}" class="collapse mt-2">
                                                    <table class="table table-sm table-bordered">
                                                        <thead>
                                                            <tr>
                                                                <th>Ore</th>
                                                                <th class="text-right">Quantity</th>
                                                                <th class="text-right">Value (Refined)</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach($oreComp as $oreName => $oreData)
                                                            <tr>
                                                                <td>{{ $oreName }}</td>
                                                                <td class="text-right">{{ number_format($oreData['quantity'] ?? 0, 0) }}</td>
                                                                <td class="text-right text-success">{{ number_format($oreData['value'] ?? 0, 0) }} ISK</td>
                                                            </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            @else
                                                <span class="text-muted">No data</span>
                                            @endif
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">
                            <i class="fas fa-info-circle"></i>
                            No other extractions found for this structure.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
@if($extraction->ore_composition)
// Composition Chart
const ctx = document.getElementById('compositionChart');
const composition = @json($composition ?? []);

const labels = Object.keys(composition);
const data = labels.map(ore => composition[ore].percentage);
const backgroundColors = ['#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e67e22', '#34495e'];

new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: labels,
        datasets: [{
            data: data,
            backgroundColor: backgroundColors,
            borderColor: '#343a40',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    color: '#fff',
                    font: {
                        size: 12
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.parsed || 0;
                        return label + ': ' + value.toFixed(2) + '%';
                    }
                }
            }
        }
    }
});
@endif
</script>
@endpush

    </div>
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
