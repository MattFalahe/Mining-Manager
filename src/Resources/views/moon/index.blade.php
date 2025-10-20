@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.moon_mining'))
@section('page_header', trans('mining-manager::moons.moon_mining'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
    .extraction-card {
        transition: transform 0.2s;
        border-left: 4px solid;
    }
    .extraction-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
    }
    .extraction-card.status-extracting {
        border-left-color: #ffc107;
    }
    .extraction-card.status-ready {
        border-left-color: #28a745;
    }
    .extraction-card.status-completed {
        border-left-color: #6c757d;
    }
    .status-badge {
        font-size: 0.85rem;
        padding: 0.35rem 0.65rem;
    }
    .countdown {
        font-family: 'Courier New', monospace;
        font-size: 1.1rem;
        font-weight: bold;
    }
</style>
@endpush

@section('full')
<div class="moon-extractions">
    
    {{-- QUICK STATS --}}
    <div class="row">
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-warning">
                <span class="info-box-icon">
                    <i class="fas fa-circle-notch fa-spin"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.extracting') }}</span>
                    <span class="info-box-number">{{ $extractions->where('status', 'extracting')->count() }}</span>
                    <small>{{ trans('mining-manager::moons.active_extractions') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon">
                    <i class="fas fa-gem"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.ready') }}</span>
                    <span class="info-box-number">{{ $extractions->where('status', 'ready')->count() }}</span>
                    <small>{{ trans('mining-manager::moons.ready_to_mine') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon">
                    <i class="fas fa-clock"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.upcoming_7d') }}</span>
                    <span class="info-box-number">{{ $upcoming->count() }}</span>
                    <small>{{ trans('mining-manager::moons.next_week') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-secondary">
                <span class="info-box-icon">
                    <i class="fas fa-check-circle"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.completed') }}</span>
                    <span class="info-box-number">{{ $extractions->where('status', 'completed')->count() }}</span>
                    <small>{{ trans('mining-manager::moons.this_month') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- UPCOMING EXTRACTIONS (NEXT 7 DAYS) --}}
    @if($upcoming->count() > 0)
    <div class="row">
        <div class="col-12">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-week"></i>
                        {{ trans('mining-manager::moons.upcoming_extractions') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-warning">{{ $upcoming->count() }} {{ trans('mining-manager::moons.upcoming') }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::moons.structure') }}</th>
                                    <th>{{ trans('mining-manager::moons.moon') }}</th>
                                    <th>{{ trans('mining-manager::moons.chunk_arrival') }}</th>
                                    <th>{{ trans('mining-manager::moons.time_remaining') }}</th>
                                    <th>{{ trans('mining-manager::moons.estimated_value') }}</th>
                                    <th>{{ trans('mining-manager::moons.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($upcoming as $extraction)
                                <tr>
                                    <td>
                                        {{ $extraction->structure->name ?? 'Unknown' }}
                                    </td>
                                    <td>
                                        <i class="fas fa-moon text-info"></i>
                                        {{ $extraction->moon->name ?? 'N/A' }}
                                    </td>
                                    <td>
                                        {{ $extraction->chunk_arrival_time->format('M d, Y H:i') }}
                                    </td>
                                    <td>
                                        <span class="countdown text-warning">
                                            {{ $extraction->chunk_arrival_time->diffForHumans() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($extraction->ore_composition)
                                            <span class="text-success">~{{ number_format($extraction->estimated_value ?? 0, 0) }} ISK</span>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('mining-manager.moon.show', $extraction->id) }}" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
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

    {{-- FILTERS & ACTIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i>
                        {{ trans('mining-manager::moons.filters') }}
                    </h3>
                    <div class="card-tools">
                        @can('mining-manager.moon.update')
                        <form action="{{ route('mining-manager.moon.refresh-all') }}" method="POST" style="display: inline;">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::moons.refresh_all') }}
                            </button>
                        </form>
                        @endcan
                        <a href="{{ route('mining-manager.moon.calendar') }}" class="btn btn-sm btn-info">
                            <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::moons.calendar_view') }}
                        </a>
                        <a href="{{ route('mining-manager.moon.compositions') }}" class="btn btn-sm btn-success">
                            <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::moons.compositions') }}
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <form method="GET" action="{{ route('mining-manager.moon.index') }}">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>{{ trans('mining-manager::moons.status') }}</label>
                                    <select name="status" class="form-control">
                                        <option value="all" {{ $status === 'all' ? 'selected' : '' }}>{{ trans('mining-manager::moons.all') }}</option>
                                        <option value="extracting" {{ $status === 'extracting' ? 'selected' : '' }}>{{ trans('mining-manager::moons.extracting') }}</option>
                                        <option value="ready" {{ $status === 'ready' ? 'selected' : '' }}>{{ trans('mining-manager::moons.ready') }}</option>
                                        <option value="completed" {{ $status === 'completed' ? 'selected' : '' }}>{{ trans('mining-manager::moons.completed') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i> {{ trans('mining-manager::moons.filter') }}
                                        </button>
                                        <a href="{{ route('mining-manager.moon.index') }}" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> {{ trans('mining-manager::moons.clear') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ALL EXTRACTIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        {{ trans('mining-manager::moons.all_extractions') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info">{{ $extractions->total() }} {{ trans('mining-manager::moons.total') }}</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    @forelse($extractions as $extraction)
                    <div class="extraction-card card mb-2 status-{{ $extraction->status }}">
                        <div class="card-body">
                            <div class="row align-items-center">
                                {{-- Structure & Moon Info --}}
                                <div class="col-md-4">
                                    <h5 class="mb-1">
                                        <i class="fas fa-building text-primary"></i>
                                        {{ $extraction->structure->name ?? 'Unknown Structure' }}
                                    </h5>
                                    <p class="mb-0 text-muted">
                                        <i class="fas fa-moon text-info"></i>
                                        {{ $extraction->moon->name ?? 'Unknown Moon' }}
                                    </p>
                                </div>

                                {{-- Status & Times --}}
                                <div class="col-md-4">
                                    <div class="mb-2">
                                        <span class="status-badge badge badge-{{ 
                                            $extraction->status === 'extracting' ? 'warning' : 
                                            ($extraction->status === 'ready' ? 'success' : 'secondary') 
                                        }}">
                                            {{ trans('mining-manager::moons.' . $extraction->status) }}
                                        </span>
                                    </div>
                                    <p class="mb-1">
                                        <strong>{{ trans('mining-manager::moons.chunk_arrival') }}:</strong><br>
                                        {{ $extraction->chunk_arrival_time->format('M d, Y H:i') }}
                                        @if($extraction->chunk_arrival_time->isFuture())
                                            <span class="text-warning">({{ $extraction->chunk_arrival_time->diffForHumans() }})</span>
                                        @endif
                                    </p>
                                    @if($extraction->natural_decay_time)
                                    <p class="mb-0 text-muted small">
                                        <strong>{{ trans('mining-manager::moons.decay') }}:</strong> {{ $extraction->natural_decay_time->format('M d, H:i') }}
                                    </p>
                                    @endif
                                </div>

                                {{-- Value & Actions --}}
                                <div class="col-md-4 text-right">
                                    @if($extraction->ore_composition)
                                        <h4 class="mb-2 text-success">
                                            ~{{ number_format($extraction->estimated_value ?? 0, 0) }} ISK
                                        </h4>
                                        <p class="mb-2 text-muted small">{{ trans('mining-manager::moons.estimated_value') }}</p>
                                    @else
                                        <p class="mb-2 text-muted">{{ trans('mining-manager::moons.no_composition_data') }}</p>
                                    @endif
                                    
                                    <div class="btn-group">
                                        <a href="{{ route('mining-manager.moon.show', $extraction->id) }}" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i> {{ trans('mining-manager::moons.details') }}
                                        </a>
                                        @can('mining-manager.moon.update')
                                        <form action="{{ route('mining-manager.moon.update', $extraction->id) }}" method="POST" style="display: inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::moons.update') }}
                                            </button>
                                        </form>
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-moon fa-3x mb-3"></i>
                        <h4>{{ trans('mining-manager::moons.no_extractions') }}</h4>
                        <p>{{ trans('mining-manager::moons.no_extractions_message') }}</p>
                    </div>
                    @endforelse
                </div>
                @if($extractions->hasPages())
                <div class="card-footer">
                    {{ $extractions->appends(request()->query())->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script>
// Auto-refresh countdown timers
setInterval(function() {
    $('.countdown').each(function() {
        // Simple refresh - in production you might want to update via AJAX
    });
}, 60000); // Update every minute
</script>
@endpush
@endsection
