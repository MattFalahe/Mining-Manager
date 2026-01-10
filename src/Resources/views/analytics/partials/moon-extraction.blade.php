{{-- MOON ANALYTICS — PER EXTRACTION VIEW --}}

{{-- EXTRACTION HEADER --}}
<div class="row mb-3">
    <div class="col-12">
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-moon"></i>
                    {{ $data['moon_name'] }}
                    @if($data['extraction']->is_jackpot ?? false)
                        <span class="badge badge-warning ml-2" style="background: linear-gradient(45deg, #ffd700, #ffed4e); color: #000;">
                            <i class="fas fa-star"></i> JACKPOT
                        </span>
                    @endif
                </h3>
                <div class="card-tools text-muted">
                    @if($data['extraction']->chunk_arrival_time)
                        {{ $data['extraction']->chunk_arrival_time->format('M d, Y') }}
                        &mdash;
                        {{ $data['extraction']->natural_decay_time ? $data['extraction']->natural_decay_time->format('M d, Y') : '?' }}
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- SUMMARY CARDS --}}
<div class="row mb-3">
    <div class="col-md-3">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <h3>{{ number_format($data['pool_m3'], 0) }}</h3>
            <p>{{ trans('mining-manager::analytics.pool_m3') }}</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #1cc88a 0%, #17a673 100%);">
            <h3>{{ number_format($data['mined_m3'], 0) }}</h3>
            <p>{{ trans('mining-manager::analytics.mined_m3') }}</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #f6c23e 0%, #dda20a 100%);">
            <h3>{{ $data['util_pct'] }}%</h3>
            <p>{{ trans('mining-manager::analytics.utilization') }}</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #36b9cc 0%, #258391 100%);">
            <h3>{{ $data['unique_miners'] }}</h3>
            <p>{{ trans('mining-manager::analytics.unique_miners') }}</p>
        </div>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-4">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);">
            <h3>{{ number_format($data['pool_isk'] / 1000000, 0) }}M</h3>
            <p>{{ trans('mining-manager::analytics.pool_value_isk') }}</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);">
            <h3>{{ number_format($data['mined_isk'] / 1000000, 0) }}M</h3>
            <p>{{ trans('mining-manager::analytics.mined_value_isk') }}</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="moon-stat-card" style="background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%);">
            <h3>{{ $data['value_pct'] }}%</h3>
            <p>{{ trans('mining-manager::analytics.value_captured') }}</p>
        </div>
    </div>
</div>

<div class="row">
    {{-- POOL COMPOSITION (what was available) --}}
    <div class="col-lg-6">
        <div class="card card-info card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-database"></i>
                    {{ trans('mining-manager::analytics.pool_composition') }}
                </h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::analytics.ore_name') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.volume_m3') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.value') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($data['pool_ores'] as $ore)
                            <tr>
                                <td><i class="fas fa-gem"></i> {{ $ore['name'] }}</td>
                                <td class="text-right">{{ number_format($ore['volume_m3'], 0) }} m&sup3;</td>
                                <td class="text-right text-success">{{ number_format($ore['value'] / 1000000, 1) }}M ISK</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted">{{ trans('mining-manager::analytics.no_composition_data') }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td>{{ trans('mining-manager::analytics.total') }}</td>
                                <td class="text-right">{{ number_format($data['pool_m3'], 0) }} m&sup3;</td>
                                <td class="text-right text-success">{{ number_format($data['pool_isk'] / 1000000, 1) }}M ISK</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- WHAT WAS MINED --}}
    <div class="col-lg-6">
        <div class="card card-success card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-hammer"></i>
                    {{ trans('mining-manager::analytics.actually_mined') }}
                </h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::analytics.ore_name') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.quantity') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.volume_m3') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.value') }}</th>
                                <th class="text-center">{{ trans('mining-manager::analytics.miners') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($data['mined_ores'] as $ore)
                            <tr>
                                <td><i class="fas fa-gem"></i> {{ $ore->typeName }}</td>
                                <td class="text-right">{{ number_format($ore->qty, 0) }}</td>
                                <td class="text-right">{{ number_format($ore->mined_m3, 0) }} m&sup3;</td>
                                <td class="text-right text-success">{{ number_format($ore->mined_isk / 1000000, 1) }}M ISK</td>
                                <td class="text-center">{{ $ore->miners }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted">{{ trans('mining-manager::analytics.no_mining_activity') }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td>{{ trans('mining-manager::analytics.total') }}</td>
                                <td></td>
                                <td class="text-right">{{ number_format($data['mined_m3'], 0) }} m&sup3;</td>
                                <td class="text-right text-success">{{ number_format($data['mined_isk'] / 1000000, 1) }}M ISK</td>
                                <td class="text-center">{{ $data['unique_miners'] }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@if($data['mined_ores']->isNotEmpty() && count($data['pool_ores']) > 0)
{{-- COMPARISON CHART --}}
<div class="row">
    <div class="col-12">
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-chart-bar"></i>
                    {{ trans('mining-manager::analytics.pool_vs_mined') }}
                </h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="poolVsMinedChart"></canvas>
                </div>
                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::moons.note_pool_vs_mined') }}</small>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
$(document).ready(function() {
    var ctx = document.getElementById('poolVsMinedChart').getContext('2d');

    // Build ore labels from pool composition
    var poolOres = @json($data['pool_ores']);
    var minedOres = @json($data['mined_ores']->keyBy('type_id')->toArray());

    var labels = poolOres.map(function(o) { return o.name; });
    var poolValues = poolOres.map(function(o) { return (o.value / 1000000).toFixed(1); });
    var minedValues = poolOres.map(function(o) {
        var typeId = o.type_id;
        var mined = minedOres[typeId];
        return mined ? (mined.mined_isk / 1000000).toFixed(1) : 0;
    });

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '{{ trans('mining-manager::analytics.pool_value_isk') }}',
                    data: poolValues,
                    backgroundColor: 'rgba(78, 115, 223, 0.7)',
                    borderColor: 'rgba(78, 115, 223, 1)',
                    borderWidth: 1,
                },
                {
                    label: '{{ trans('mining-manager::analytics.mined_value_isk') }}',
                    data: minedValues,
                    backgroundColor: 'rgba(28, 200, 138, 0.7)',
                    borderColor: 'rgba(28, 200, 138, 1)',
                    borderWidth: 1,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#ccc' }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y + 'M ISK';
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#ccc' },
                    grid: { display: false },
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#ccc',
                        callback: function(value) { return value + 'M'; }
                    },
                    grid: { color: 'rgba(255,255,255,0.1)' },
                }
            }
        }
    });
});
</script>
@endpush
@endif
