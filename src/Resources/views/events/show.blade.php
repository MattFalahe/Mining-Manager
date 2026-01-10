@extends('web::layouts.grids.12')

@section('title', $event->name)
@section('page_header', trans('mining-manager::menu.mining_events'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')


{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/events') && !Request::is('*/events/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.events.index') }}">
                <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_events') }}
            </a>
        </li>
        <li class="{{ Request::is('*/events/active') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.events.active') }}">
                <i class="fas fa-play-circle"></i> {{ trans('mining-manager::menu.active_events') }}
            </a>
        </li>
        @can('mining-manager.events.create')
        <li class="{{ Request::is('*/events/create') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.events.create') }}">
                <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::menu.create_event') }}
            </a>
        </li>
        @endcan
        <li class="{{ Request::is('*/events/calendar') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.events.calendar') }}">
                <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.event_calendar') }}
            </a>
        </li>
        <li class="{{ Request::is('*/events/my-events') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.events.my-events') }}">
                <i class="fas fa-user-check"></i> {{ trans('mining-manager::menu.my_events') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">


<div class="event-details">
    
    {{-- Event Header --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-{{ $event->status === 'active' ? 'success' : ($event->status === 'cancelled' ? 'danger' : 'primary') }} card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-{{ $event->icon ?? 'calendar' }}"></i>
                        {{ $event->name }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-{{ $event->status === 'active' ? 'success' : ($event->status === 'cancelled' ? 'danger' : 'info') }}">
                            {{ trans('mining-manager::events.' . $event->status) }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <p class="lead">{{ $event->description }}</p>
                            
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <h5>{{ trans('mining-manager::events.event_information') }}</h5>
                                    <p><i class="fas fa-tag"></i> <strong>{{ trans('mining-manager::events.type') }}:</strong> {{ trans('mining-manager::events.' . $event->type) }}</p>
                                    <p><i class="fas fa-calendar-alt"></i> <strong>{{ trans('mining-manager::events.starts') }}:</strong> {{ $event->start_time->format('F d, Y H:i') }}</p>
                                    @if($event->end_time)
                                    <p><i class="fas fa-clock"></i> <strong>{{ trans('mining-manager::events.ends') }}:</strong> {{ $event->end_time->format('F d, Y H:i') }}</p>
                                    @endif
                                    @if($event->location)
                                    <p><i class="fas fa-map-marker-alt"></i> <strong>{{ trans('mining-manager::events.location') }}:</strong> {{ $event->location }}</p>
                                    @endif
                                    <p><i class="fas fa-user"></i> <strong>{{ trans('mining-manager::events.organizer') }}:</strong> {{ $event->organizer->name ?? 'Unknown' }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h5>{{ trans('mining-manager::events.statistics') }}</h5>
                                    <p><i class="fas fa-users"></i> <strong>{{ trans('mining-manager::events.participants') }}:</strong> {{ $event->participants_count ?? 0 }} / {{ $event->max_participants ?? '∞' }}</p>
                                    <p><i class="fas fa-coins"></i> <strong>{{ trans('mining-manager::events.total_mined') }}:</strong> {{ number_format($event->total_mined_value ?? 0, 0) }} ISK</p>
                                    @if($event->tax_modifier != 0)
                                    <p><i class="fas fa-percentage"></i> <strong>{{ trans('mining-manager::events.tax_modifier') }}:</strong> {{ $event->tax_modifier > 0 ? '+' : '' }}{{ $event->tax_modifier }}%</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                @if($event->status === 'upcoming' || $event->status === 'active')
                                    @if($event->isParticipating(auth()->user()))
                                        <button class="btn btn-danger btn-lg btn-block leave-event" data-event-id="{{ $event->id }}">
                                            <i class="fas fa-sign-out-alt"></i> {{ trans('mining-manager::events.leave_event') }}
                                        </button>
                                    @else
                                        <button class="btn btn-success btn-lg btn-block join-event" data-event-id="{{ $event->id }}">
                                            <i class="fas fa-sign-in-alt"></i> {{ trans('mining-manager::events.join_event') }}
                                        </button>
                                    @endif
                                @endif
                                
                                @can('mining-manager.director')
                                <hr>
                                <a href="{{ route('mining-manager.events.edit', $event->id) }}" class="btn btn-warning btn-block">
                                    <i class="fas fa-edit"></i> {{ trans('mining-manager::events.edit') }}
                                </a>
                                <button class="btn btn-danger btn-block delete-event" data-event-id="{{ $event->id }}">
                                    <i class="fas fa-trash"></i> {{ trans('mining-manager::events.delete') }}
                                </button>
                                @endcan
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Participants --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::events.participants') }}</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-dark table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::events.rank') }}</th>
                                <th>{{ trans('mining-manager::events.character') }}</th>
                                <th class="text-right">{{ trans('mining-manager::events.mined_value') }}</th>
                                <th class="text-right">{{ trans('mining-manager::events.quantity') }}</th>
                                <th>{{ trans('mining-manager::events.joined_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($event->participants->sortByDesc('total_mined') as $index => $participant)
                            <tr>
                                <td>
                                    @if($index < 3)
                                        <i class="fas fa-trophy text-{{ ['warning', 'secondary', 'orange'][$index] }}"></i> #{{ $index + 1 }}
                                    @else
                                        #{{ $index + 1 }}
                                    @endif
                                </td>
                                <td>
                                    <img src="https://images.evetech.net/characters/{{ $participant->character_id }}/portrait?size=32" class="img-circle" style="width: 32px;">
                                    {{ $participant->character_name }}
                                </td>
                                <td class="text-right">{{ number_format($participant->total_mined ?? 0, 0) }} ISK</td>
                                <td class="text-right">{{ number_format($participant->quantity_mined ?? 0, 0) }}</td>
                                <td>{{ $participant->joined_at->diffForHumans() }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted">{{ trans('mining-manager::events.no_participants_yet') }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script>
$('.join-event, .leave-event, .delete-event').on('click', function() {
    const eventId = $(this).data('event-id');
    const action = $(this).hasClass('join-event') ? 'join' : $(this).hasClass('leave-event') ? 'leave' : 'delete';
    
    let route, method, confirmMsg;
    if (action === 'join') {
        route = '{{ route("mining-manager.events.join", ":id") }}'.replace(':id', eventId);
        method = 'POST';
    } else if (action === 'leave') {
        route = '{{ route("mining-manager.events.leave", ":id") }}'.replace(':id', eventId);
        method = 'POST';
        confirmMsg = '{{ trans("mining-manager::events.confirm_leave") }}';
    } else {
        route = '{{ route("mining-manager.events.destroy", ":id") }}'.replace(':id', eventId);
        method = 'DELETE';
        confirmMsg = '{{ trans("mining-manager::events.confirm_delete") }}';
    }
    
    if (confirmMsg && !confirm(confirmMsg)) return;
    
    $.ajax({
        url: route,
        method: method,
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
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

@endsection
