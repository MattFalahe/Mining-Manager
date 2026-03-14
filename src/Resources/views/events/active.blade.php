@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::events.active_events'))
@section('page_header', trans('mining-manager::menu.mining_events'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
<meta http-equiv="refresh" content="30">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard events-active-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/events') && !Request::is('*/events/*') ? 'active' : '' }}" href="{{ route('mining-manager.events.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_events') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/events/active') ? 'active' : '' }}" href="{{ route('mining-manager.events.active') }}">
                    <i class="fas fa-play-circle"></i> {{ trans('mining-manager::menu.active_events') }}
                </a>
            </li>
            @can('mining-manager.director')
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/events/create') ? 'active' : '' }}" href="{{ route('mining-manager.events.create') }}">
                    <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::menu.create_event') }}
                </a>
            </li>
            @endcan
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/events/calendar') ? 'active' : '' }}" href="{{ route('mining-manager.events.calendar') }}">
                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.event_calendar') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/events/my-events') ? 'active' : '' }}" href="{{ route('mining-manager.events.my-events') }}">
                    <i class="fas fa-user-check"></i> {{ trans('mining-manager::menu.my_events') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">


<div class="active-events">
    
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                {{ trans('mining-manager::events.auto_refresh_notice') }}
            </div>
        </div>
    </div>

    @forelse($activeEvents as $event)
    <div class="row">
        <div class="col-12">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-broadcast-tower pulse text-success"></i>
                        {{ $event->name }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-success">{{ trans('mining-manager::events.live_now') }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Event Info --}}
                        <div class="col-md-4">
                            <h5>{{ trans('mining-manager::events.event_information') }}</h5>
                            <p><strong>{{ trans('mining-manager::events.type') }}:</strong> {{ $event->getTypeLabel() }}</p>
                            <p><strong>{{ trans('mining-manager::events.started') }}:</strong> {{ $event->start_time->diffForHumans() }}</p>
                            @if($event->end_time)
                            <p><strong>{{ trans('mining-manager::events.duration') }}:</strong> {{ $event->start_time->diff($event->end_time)->format('%h hours %i minutes') }}</p>
                            @endif
                            <p><strong>{{ trans('mining-manager::events.location') }}:</strong> {{ $event->getLocationName() ?? $event->getLocationScopeLabel() }}</p>
                            <p>
                                <strong>{{ trans('mining-manager::events.tax_modifier') }}:</strong>
                                <span class="badge badge-{{ $event->tax_modifier < 0 ? 'success' : ($event->tax_modifier > 0 ? 'warning' : 'secondary') }}">
                                    {{ $event->getTaxModifierLabel() }}
                                </span>
                            </p>
                        </div>

                        {{-- Participants --}}
                        <div class="col-md-8">
                            <h5>{{ trans('mining-manager::events.participants') }} ({{ $event->participants->count() }})</h5>
                            <div class="table-responsive">
                                <table class="table table-dark table-sm">
                                    <thead>
                                        <tr>
                                            <th>{{ trans('mining-manager::events.character') }}</th>
                                            <th class="text-right">{{ trans('mining-manager::events.mined_value') }}</th>
                                            <th class="text-right">{{ trans('mining-manager::events.quantity') }}</th>
                                            <th>{{ trans('mining-manager::events.joined') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($event->participants as $participant)
                                        <tr>
                                            <td>
                                                <img src="https://images.evetech.net/characters/{{ $participant->character_id }}/portrait?size=32" class="img-circle" style="width: 32px;">
                                                {{ $participant->character_name }}
                                            </td>
                                            <td class="text-right">{{ number_format($participant->total_mined ?? 0, 0) }} ISK</td>
                                            <td class="text-right">{{ number_format($participant->quantity_mined ?? 0, 0) }}</td>
                                            <td>{{ $participant->joined_at->diffForHumans() }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- Leaderboard --}}
                    <div class="row mt-3">
                        <div class="col-12">
                            <h5>{{ trans('mining-manager::events.leaderboard') }}</h5>
                            <div class="row">
                                @foreach($event->topParticipants(3) as $index => $top)
                                <div class="col-md-4">
                                    <div class="info-box bg-gradient-{{ ['success', 'info', 'warning'][$index] ?? 'secondary' }}">
                                        <span class="info-box-icon">
                                            <i class="fas fa-trophy"></i>
                                        </span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">{{ ['1st Place', '2nd Place', '3rd Place'][$index] }}</span>
                                            <span class="info-box-number">{{ $top->character_name }}</span>
                                            <small>{{ number_format($top->total_mined, 0) }} ISK</small>
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="btn-group">
                        <a href="{{ route('mining-manager.events.show', $event->id) }}" class="btn btn-info">
                            <i class="fas fa-eye"></i> {{ trans('mining-manager::events.view_details') }}
                        </a>
                        @if($event->isParticipating(auth()->user()))
                            <button class="btn btn-danger leave-event" data-event-id="{{ $event->id }}">
                                <i class="fas fa-sign-out-alt"></i> {{ trans('mining-manager::events.leave') }}
                            </button>
                        @else
                            <button class="btn btn-success join-event" data-event-id="{{ $event->id }}">
                                <i class="fas fa-sign-in-alt"></i> {{ trans('mining-manager::events.join') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    @empty
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-body text-center text-muted">
                    <i class="fas fa-calendar-times fa-3x mb-3"></i>
                    <h4>{{ trans('mining-manager::events.no_active_events') }}</h4>
                    <p>{{ trans('mining-manager::events.check_upcoming') }}</p>
                    <a href="{{ route('mining-manager.events.index') }}" class="btn btn-info">
                        <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::events.view_all_events') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
    @endforelse

</div>

@push('javascript')
<script>
$(document).ready(function() {
    $('.join-event, .leave-event').on('click', function() {
        const eventId = $(this).data('event-id');
        const action = $(this).hasClass('join-event') ? 'join' : 'leave';
        const route = action === 'join' 
            ? '{{ route("mining-manager.events.join", ":id") }}'.replace(':id', eventId)
            : '{{ route("mining-manager.events.leave", ":id") }}'.replace(':id', eventId);
        
        $.ajax({
            url: route,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function(response) {
                toastr.success(response.message);
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message);
            }
        });
    });
});

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
.pulse { animation: pulse 2s infinite; }
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
