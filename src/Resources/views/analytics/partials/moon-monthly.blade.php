{{-- MOON ANALYTICS — MONTHLY VIEW --}}

{{-- SUMMARY CARDS --}}
<div class="row mb-3">
    <div class="col-md-3">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <h3>{{ number_format($summary['total_pool_m3'] ?? 0, 0) }}</h3>
            <p>{{ trans('mining-manager::analytics.total_pool_m3') }}</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #1cc88a 0%, #17a673 100%);">
            <h3>{{ number_format($summary['total_mined_m3'] ?? 0, 0) }}</h3>
            <p>{{ trans('mining-manager::analytics.total_mined_m3') }}</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #f6c23e 0%, #dda20a 100%);">
            <h3>{{ $summary['utilization_pct'] ?? 0 }}%</h3>
            <p>{{ trans('mining-manager::analytics.overall_utilization') }}</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #36b9cc 0%, #258391 100%);">
            <h3>{{ $summary['unique_miners'] ?? 0 }}</h3>
            <p>{{ trans('mining-manager::analytics.unique_miners') }}</p>
        </div>
    </div>
</div>

{{-- VALUE SUMMARY CARDS --}}
<div class="row mb-3">
    <div class="col-md-4">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);">
            <h3>{{ number_format(($summary['total_pool_isk'] ?? 0) / 1000000, 0) }}M</h3>
            <p>{{ trans('mining-manager::analytics.pool_value_isk') }}</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);">
            <h3>{{ number_format(($summary['total_mined_isk'] ?? 0) / 1000000, 0) }}M</h3>
            <p>{{ trans('mining-manager::analytics.mined_value_isk') }}</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%);">
            <h3>{{ $summary['value_pct'] ?? 0 }}%</h3>
            <p>{{ trans('mining-manager::analytics.value_captured') }}</p>
        </div>
    </div>
</div>

{{-- MOON UTILIZATION TABLE --}}
<div class="row">
    <div class="col-12">
        <div class="card card-info card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-moon"></i>
                    {{ trans('mining-manager::analytics.moon_utilization') }}
                </h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::analytics.moon_name') }}</th>
                                <th class="text-center">{{ trans('mining-manager::analytics.extractions') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.pool_m3') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.mined_m3') }}</th>
                                <th style="width: 15%;">{{ trans('mining-manager::analytics.utilization') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.pool_isk') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.mined_isk') }}</th>
                                <th style="width: 15%;">{{ trans('mining-manager::analytics.value_captured') }}</th>
                                <th class="text-center">{{ trans('mining-manager::analytics.miners') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($utilization as $moon)
                            <tr>
                                <td>
                                    <i class="fas fa-moon"></i> {{ $moon->moon_name }}
                                    @if($moon->structure_name)
                                        <br><small class="text-muted"><i class="fas fa-industry"></i> {{ $moon->structure_name }}</small>
                                    @endif
                                </td>
                                <td class="text-center">{{ $moon->extraction_count }}</td>
                                <td class="text-right">{{ number_format($moon->pool_m3, 0) }}</td>
                                <td class="text-right">{{ number_format($moon->mined_m3, 0) }}</td>
                                <td>
                                    <div class="mm-progress-wrap">
                                        <div class="progress" style="height: 22px;">
                                            <div class="progress-bar {{ $moon->util_pct >= 80 ? 'bg-success' : ($moon->util_pct >= 50 ? 'bg-info' : 'bg-warning') }}" style="width: {{ max($moon->util_pct, 1) }}%"></div>
                                        </div>
                                        <span class="mm-pct-label">{{ $moon->util_pct }}%</span>
                                    </div>
                                </td>
                                <td class="text-right text-success">{{ number_format($moon->pool_isk / 1000000, 0) }}M</td>
                                <td class="text-right text-success">{{ number_format($moon->mined_isk / 1000000, 0) }}M</td>
                                <td>
                                    <div class="mm-progress-wrap">
                                        <div class="progress" style="height: 22px;">
                                            <div class="progress-bar {{ $moon->value_pct >= 80 ? 'bg-success' : ($moon->value_pct >= 50 ? 'bg-primary' : 'bg-danger') }}" style="width: {{ max($moon->value_pct, 1) }}%"></div>
                                        </div>
                                        <span class="mm-pct-label">{{ $moon->value_pct }}%</span>
                                    </div>
                                </td>
                                <td class="text-center">{{ $moon->unique_miners }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <i class="fas fa-moon fa-3x mb-2"></i><br>
                                    {{ trans('mining-manager::analytics.no_moon_data') }}
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

@if($popularity->isNotEmpty())
@php
    // Group ore popularity by R-category
    $categoryLabels = [
        'r4' => 'Moon R4', 'r8' => 'Moon R8', 'r16' => 'Moon R16',
        'r32' => 'Moon R32', 'r64' => 'Moon R64', 'unknown' => 'Other',
    ];
    $oreByCategoryIsk = [];
    $oreByCategoryQty = [];
    if ($orePopularity->isNotEmpty()) {
        foreach ($orePopularity as $ore) {
            $rarity = \MiningManager\Services\TypeIdRegistry::getMoonOreRarity($ore->type_id) ?? 'unknown';
            $label = $categoryLabels[$rarity] ?? ucfirst($rarity);
            $oreByCategoryIsk[$label] = ($oreByCategoryIsk[$label] ?? 0) + $ore->total_isk;
            $oreByCategoryQty[$label] = ($oreByCategoryQty[$label] ?? 0) + $ore->total_quantity;
        }
        arsort($oreByCategoryIsk);
        // Sort quantity in same order as ISK
        $oreByCategoryQty = array_replace(array_intersect_key(array_flip(array_keys($oreByCategoryIsk)), $oreByCategoryQty), $oreByCategoryQty);
    }
@endphp
{{-- MOON POPULARITY CHART --}}
<div class="row">
    <div class="col-12">
        <div class="card card-success card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-users"></i>
                    {{ trans('mining-manager::analytics.moon_popularity') }}
                </h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="moonPopularityChart"></canvas>
                </div>
                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_moon_popularity') }}</small>
            </div>
        </div>
    </div>
</div>

{{-- POOL ORE DISTRIBUTION --}}
@if(!empty($poolOreDistribution))
<div class="row">
    <div class="col-lg-6">
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-gem"></i>
                    {{ trans('mining-manager::analytics.pool_ore_distribution') }} (ISK)
                </h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="poolOreIskChart"></canvas>
                </div>
                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.pool_ore_note') }}</small>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-cubes"></i>
                    {{ trans('mining-manager::analytics.pool_ore_distribution') }} (m³)
                </h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="poolOreVolChart"></canvas>
                </div>
                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.pool_ore_note') }}</small>
            </div>
        </div>
    </div>
</div>
@endif

{{-- MINED ORE DISTRIBUTION --}}
<div class="row">
    <div class="col-lg-6">
        <div class="card card-warning card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-gem"></i>
                    {{ trans('mining-manager::analytics.mined_ore_distribution') }} (ISK)
                </h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="orePopularityChart"></canvas>
                </div>
                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.mined_ore_note') }}</small>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-warning card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-cubes"></i>
                    {{ trans('mining-manager::analytics.mined_ore_distribution') }} ({{ trans('mining-manager::analytics.total_quantity') }})
                </h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="orePopularityQtyChart"></canvas>
                </div>
                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.mined_ore_note') }}</small>
            </div>
        </div>
    </div>
</div>
@endif

@if($orePopularity->isNotEmpty())
{{-- ORE POPULARITY TABLE --}}
<div class="row">
    <div class="col-12">
        <div class="card card-warning card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-gem"></i>
                    {{ trans('mining-manager::analytics.ore_popularity_detail') }}
                </h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::analytics.ore_name') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.quantity_mined') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.volume_mined_m3') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.value_mined_isk') }}</th>
                                <th style="width: 20%;">{{ trans('mining-manager::analytics.share_of_total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $maxOreIsk = $orePopularity->max('total_isk'); @endphp
                            @foreach($orePopularity as $ore)
                            <tr>
                                <td><i class="fas fa-gem"></i> {{ $ore->ore_name }}</td>
                                <td class="text-right">{{ number_format($ore->total_quantity, 0) }}</td>
                                <td class="text-right">{{ number_format($ore->total_m3, 0) }} m&sup3;</td>
                                <td class="text-right text-success">{{ number_format($ore->total_isk / 1000000, 1) }}M ISK</td>
                                <td>
                                    @php $orePct = $maxOreIsk > 0 ? ($ore->total_isk / $maxOreIsk) * 100 : 0; @endphp
                                    <div class="mm-progress-wrap">
                                        <div class="progress" style="height: 22px;">
                                            <div class="progress-bar bg-warning" style="width: {{ max($orePct, 1) }}%"></div>
                                        </div>
                                        <span class="mm-pct-label">{{ number_format($orePct, 1) }}%</span>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

@push('javascript')
@if($popularity->isNotEmpty())
<script>
$(document).ready(function() {
    // Moon Popularity — horizontal bar chart
    var popCtx = document.getElementById('moonPopularityChart').getContext('2d');
    new Chart(popCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($popularity->map(function($m) { return $m->structure_name ? $m->structure_name : $m->moon_name; })->values()->toArray()) !!},
            datasets: [{
                label: '{{ trans('mining-manager::analytics.unique_miners') }}',
                data: {!! json_encode($popularity->pluck('unique_miners')->toArray()) !!},
                backgroundColor: 'rgba(28, 200, 138, 0.8)',
                borderColor: 'rgba(28, 200, 138, 1)',
                borderWidth: 1,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, color: '#ccc' },
                    grid: { color: 'rgba(255,255,255,0.1)' },
                },
                y: {
                    ticks: { color: '#ccc' },
                    grid: { display: false },
                }
            }
        }
    });

    var oreColors = [
        '#4e73df', '#1cc88a', '#f6c23e', '#e74a3b', '#36b9cc',
        '#858796', '#5a5c69', '#6610f2', '#fd7e14', '#20c9a6'
    ];

    @if(!empty($poolOreDistribution))
    // Pool Ore Distribution (ISK) — what extractions offered
    @php
        $poolLabels = array_keys($poolOreDistribution);
        $poolIskValues = array_values(array_map(fn($v) => round($v['isk'] / 1000000, 1), $poolOreDistribution));
        $poolVolValues = array_values(array_map(fn($v) => round($v['volume'], 0), $poolOreDistribution));
    @endphp
    new Chart(document.getElementById('poolOreIskChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: {!! json_encode($poolLabels) !!},
            datasets: [{
                data: {!! json_encode($poolIskValues) !!},
                backgroundColor: oreColors.slice(0, {{ count($poolLabels) }}),
                borderWidth: 1,
                borderColor: '#1a1a2e',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: '#ccc', padding: 12, font: { size: 11 } },
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed.toFixed(1) + 'M ISK';
                        }
                    }
                }
            }
        }
    });

    // Pool Ore Distribution (Volume) — what extractions offered
    new Chart(document.getElementById('poolOreVolChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: {!! json_encode($poolLabels) !!},
            datasets: [{
                data: {!! json_encode($poolVolValues) !!},
                backgroundColor: oreColors.slice(0, {{ count($poolLabels) }}),
                borderWidth: 1,
                borderColor: '#1a1a2e',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: '#ccc', padding: 12, font: { size: 11 } },
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed.toLocaleString() + ' m³';
                        }
                    }
                }
            }
        }
    });
    @endif

    @if(!empty($oreByCategoryIsk))
    // Mined Ore Distribution (ISK) — what players actually mined
    new Chart(document.getElementById('orePopularityChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: {!! json_encode(array_keys($oreByCategoryIsk)) !!},
            datasets: [{
                data: {!! json_encode(array_values(array_map(fn($v) => round($v / 1000000, 1), $oreByCategoryIsk))) !!},
                backgroundColor: oreColors.slice(0, {{ count($oreByCategoryIsk) }}),
                borderWidth: 1,
                borderColor: '#1a1a2e',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: '#ccc', padding: 12, font: { size: 11 } },
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed.toFixed(1) + 'M ISK';
                        }
                    }
                }
            }
        }
    });

    // Ore Distribution (Quantity) — doughnut chart by category
    new Chart(document.getElementById('orePopularityQtyChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: {!! json_encode(array_keys($oreByCategoryQty)) !!},
            datasets: [{
                data: {!! json_encode(array_values($oreByCategoryQty)) !!},
                backgroundColor: oreColors.slice(0, {{ count($oreByCategoryQty) }}),
                borderWidth: 1,
                borderColor: '#1a1a2e',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: '#ccc', padding: 12, font: { size: 11 } },
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed.toLocaleString() + ' units';
                        }
                    }
                }
            }
        }
    });
    @endif
});
</script>
@endif
@endpush
