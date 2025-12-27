@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::events.create_event'))
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


<div class="create-event">
    
    <form id="eventForm" action="{{ route('mining-manager.events.store') }}" method="POST">
        @csrf
        
        <div class="row">
            {{-- Basic Information --}}
            <div class="col-md-8">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title">{{ trans('mining-manager::events.basic_information') }}</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="name">{{ trans('mining-manager::events.event_name') }} <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required>
                            @error('name')
                            <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label for="description">{{ trans('mining-manager::events.description') }}</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4">{{ old('description') }}</textarea>
                            @error('description')
                            <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="type">{{ trans('mining-manager::events.event_type') }} <span class="text-danger">*</span></label>
                                    <select class="form-control @error('type') is-invalid @enderror" id="type" name="type" required>
                                        <option value="">{{ trans('mining-manager::events.select_type') }}</option>
                                        <option value="mining_op">{{ trans('mining-manager::events.mining_op') }}</option>
                                        <option value="moon_extraction">{{ trans('mining-manager::events.moon_extraction') }}</option>
                                        <option value="ice_mining">{{ trans('mining-manager::events.ice_mining') }}</option>
                                        <option value="gas_huffing">{{ trans('mining-manager::events.gas_huffing') }}</option>
                                        <option value="special">{{ trans('mining-manager::events.special') }}</option>
                                    </select>
                                    @error('type')
                                    <span class="invalid-feedback">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="location">{{ trans('mining-manager::events.location') }}</label>
                                    <input type="text" class="form-control" id="location" name="location" value="{{ old('location') }}" placeholder="Jita, Amarr, etc.">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="start_time">{{ trans('mining-manager::events.start_time') }} <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control @error('start_time') is-invalid @enderror" id="start_time" name="start_time" value="{{ old('start_time') }}" required>
                                    @error('start_time')
                                    <span class="invalid-feedback">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="end_time">{{ trans('mining-manager::events.end_time') }}</label>
                                    <input type="datetime-local" class="form-control" id="end_time" name="end_time" value="{{ old('end_time') }}">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Settings --}}
            <div class="col-md-4">
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title">{{ trans('mining-manager::events.event_settings') }}</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="max_participants">{{ trans('mining-manager::events.max_participants') }}</label>
                            <input type="number" class="form-control" id="max_participants" name="max_participants" value="{{ old('max_participants') }}" min="1" placeholder="{{ trans('mining-manager::events.unlimited') }}">
                            <small class="form-text text-muted">{{ trans('mining-manager::events.leave_empty_unlimited') }}</small>
                        </div>

                        <div class="form-group">
                            <label for="tax_modifier">{{ trans('mining-manager::events.tax_modifier') }} (%)</label>
                            <input type="number" class="form-control" id="tax_modifier" name="tax_modifier" value="{{ old('tax_modifier', 0) }}" step="0.1" min="-100" max="100">
                            <small class="form-text text-muted">{{ trans('mining-manager::events.negative_bonus') }}</small>
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="is_mandatory" name="is_mandatory" value="1" {{ old('is_mandatory') ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_mandatory">
                                {{ trans('mining-manager::events.mandatory_event') }}
                            </label>
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="auto_start" name="auto_start" value="1" {{ old('auto_start', true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="auto_start">
                                {{ trans('mining-manager::events.auto_start') }}
                            </label>
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="send_notifications" name="send_notifications" value="1" {{ old('send_notifications', true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="send_notifications">
                                {{ trans('mining-manager::events.send_notifications') }}
                            </label>
                        </div>
                    </div>
                </div>

                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title">{{ trans('mining-manager::events.organizer') }}</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="organizer_id">{{ trans('mining-manager::events.event_organizer') }}</label>
                            <select class="form-control" id="organizer_id" name="organizer_id">
                                <option value="{{ auth()->user()->id }}" selected>{{ auth()->user()->name }}</option>
                                @foreach($directors ?? [] as $director)
                                <option value="{{ $director->id }}">{{ $director->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card card-dark">
                    <div class="card-footer">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> {{ trans('mining-manager::events.create_event') }}
                        </button>
                        <a href="{{ route('mining-manager.events.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> {{ trans('mining-manager::events.cancel') }}
                        </a>
                        <button type="button" class="btn btn-info float-right" id="previewEvent">
                            <i class="fas fa-eye"></i> {{ trans('mining-manager::events.preview') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>

</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Form submission
    $('#eventForm').on('submit', function(e) {
        e.preventDefault();
        
        $.ajax({
            url: $(this).attr('action'),
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                toastr.success(response.message || '{{ trans("mining-manager::events.created_success") }}');
                setTimeout(() => window.location.href = '{{ route("mining-manager.events.index") }}', 1000);
            },
            error: function(xhr) {
                if (xhr.status === 422) {
                    const errors = xhr.responseJSON.errors;
                    Object.keys(errors).forEach(key => {
                        toastr.error(errors[key][0]);
                    });
                } else {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::events.create_failed") }}');
                }
            }
        });
    });

    // Preview event
    $('#previewEvent').on('click', function() {
        const formData = $('#eventForm').serializeArray();
        let preview = '<div class="event-preview">';
        preview += '<h4>' + $('#name').val() + '</h4>';
        preview += '<p>' + $('#description').val() + '</p>';
        preview += '<p><strong>Type:</strong> ' + $('#type option:selected').text() + '</p>';
        preview += '<p><strong>Start:</strong> ' + $('#start_time').val() + '</p>';
        preview += '</div>';
        
        // Show preview in modal or alert
        alert('Event Preview:\n\n' + $('#name').val() + '\n' + $('#description').val());
    });
});
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

@endsection
