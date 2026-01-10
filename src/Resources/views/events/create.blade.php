@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::events.create_event'))
@section('page_header', trans('mining-manager::menu.mining_events'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard events-create-page">

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

                        <div class="form-group">
                            <label for="type">{{ trans('mining-manager::events.event_type') }} <span class="text-danger">*</span></label>
                            <select class="form-control @error('type') is-invalid @enderror" id="type" name="type" required>
                                <option value="">{{ trans('mining-manager::events.select_type') }}</option>
                                <option value="mining_op" {{ old('type') == 'mining_op' ? 'selected' : '' }}>{{ trans('mining-manager::events.mining_op') }}</option>
                                <option value="moon_extraction" {{ old('type') == 'moon_extraction' ? 'selected' : '' }}>{{ trans('mining-manager::events.moon_extraction') }}</option>
                                <option value="ice_mining" {{ old('type') == 'ice_mining' ? 'selected' : '' }}>{{ trans('mining-manager::events.ice_mining') }}</option>
                                <option value="gas_huffing" {{ old('type') == 'gas_huffing' ? 'selected' : '' }}>{{ trans('mining-manager::events.gas_huffing') }}</option>
                                <option value="special" {{ old('type') == 'special' ? 'selected' : '' }}>{{ trans('mining-manager::events.special') }}</option>
                            </select>
                            @error('type')
                            <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="location_scope">{{ trans('mining-manager::events.location_scope') }}</label>
                                    <select class="form-control @error('location_scope') is-invalid @enderror" id="location_scope" name="location_scope">
                                        <option value="any" {{ old('location_scope', 'any') == 'any' ? 'selected' : '' }}>{{ trans('mining-manager::events.scope_any') }}</option>
                                        <option value="region" {{ old('location_scope') == 'region' ? 'selected' : '' }}>{{ trans('mining-manager::events.scope_region') }}</option>
                                        <option value="constellation" {{ old('location_scope') == 'constellation' ? 'selected' : '' }}>{{ trans('mining-manager::events.scope_constellation') }}</option>
                                        <option value="system" {{ old('location_scope') == 'system' ? 'selected' : '' }}>{{ trans('mining-manager::events.scope_system') }}</option>
                                    </select>
                                    @error('location_scope')
                                    <span class="invalid-feedback">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group" id="location-select-group" style="{{ old('location_scope', 'any') == 'any' ? 'display:none;' : '' }}">
                                    <label for="solar_system_id">{{ trans('mining-manager::events.location') }}</label>
                                    <select class="form-control select2-location @error('solar_system_id') is-invalid @enderror" id="solar_system_id" name="solar_system_id" style="width: 100%;">
                                        @if(old('solar_system_id'))
                                        <option value="{{ old('solar_system_id') }}" selected>{{ old('location_name', 'Selected Location') }}</option>
                                        @endif
                                    </select>
                                    @error('solar_system_id')
                                    <span class="invalid-feedback">{{ $message }}</span>
                                    @enderror
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
                            <label for="tax_modifier">{{ trans('mining-manager::events.tax_modifier') }}</label>
                            <select class="form-control @error('tax_modifier') is-invalid @enderror" id="tax_modifier" name="tax_modifier" required>
                                <option value="-100" {{ old('tax_modifier') == -100 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_tax_free') }}</option>
                                <option value="-75" {{ old('tax_modifier') == -75 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_reduced_75') }}</option>
                                <option value="-50" {{ old('tax_modifier') == -50 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_reduced_50') }}</option>
                                <option value="-25" {{ old('tax_modifier') == -25 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_reduced_25') }}</option>
                                <option value="0" {{ old('tax_modifier', 0) == 0 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_normal') }}</option>
                                <option value="25" {{ old('tax_modifier') == 25 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_increase_25') }}</option>
                                <option value="50" {{ old('tax_modifier') == 50 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_increase_50') }}</option>
                                <option value="75" {{ old('tax_modifier') == 75 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_increase_75') }}</option>
                                <option value="100" {{ old('tax_modifier') == 100 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_double') }}</option>
                            </select>
                            @error('tax_modifier')
                            <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">{{ trans('mining-manager::events.tax_modifier_help') }}</small>
                        </div>

                        <div class="form-group">
                            <label for="corporation_id">{{ trans('mining-manager::events.corporation_scope') }}</label>
                            <select class="form-control @error('corporation_id') is-invalid @enderror" id="corporation_id" name="corporation_id">
                                <option value="">{{ trans('mining-manager::events.all_corporations') }}</option>
                                @if(isset($corporations))
                                    @foreach($corporations as $corp)
                                        <option value="{{ $corp->corporation_id }}" {{ old('corporation_id') == $corp->corporation_id ? 'selected' : '' }}>
                                            [{{ $corp->ticker }}] {{ $corp->name }}
                                        </option>
                                    @endforeach
                                @endif
                            </select>
                            @error('corporation_id')
                            <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">{{ trans('mining-manager::events.corporation_scope_help') }}</small>
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
    // Initialize Select2 for location search
    $('.select2-location').select2({
        theme: 'bootstrap4',
        ajax: {
            url: '{{ route("mining-manager.events.search-locations") }}',
            dataType: 'json',
            delay: 300,
            data: function(params) {
                return {
                    q: params.term,
                    scope: $('#location_scope').val()
                };
            },
            processResults: function(data) {
                return { results: data };
            },
            cache: true
        },
        placeholder: '{{ trans("mining-manager::events.search_location") }}',
        minimumInputLength: 2,
        allowClear: true
    });

    // Toggle location selector based on scope
    $('#location_scope').on('change', function() {
        if ($(this).val() === 'any') {
            $('#location-select-group').hide();
            $('#solar_system_id').val(null).trigger('change');
        } else {
            $('#location-select-group').show();
            // Clear the current selection when scope changes
            $('#solar_system_id').val(null).trigger('change');
        }
    });

    // Form submission
    $('#eventForm').on('submit', function(e) {
        e.preventDefault();

        $.ajax({
            url: $(this).attr('action'),
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (typeof toastr !== 'undefined') {
                    toastr.success(response.message || '{{ trans("mining-manager::events.created_success") }}');
                }
                setTimeout(() => window.location.href = '{{ route("mining-manager.events.index") }}', 1000);
            },
            error: function(xhr) {
                if (xhr.status === 422) {
                    const errors = xhr.responseJSON.errors;
                    Object.keys(errors).forEach(key => {
                        if (typeof toastr !== 'undefined') {
                            toastr.error(errors[key][0]);
                        } else {
                            alert('Error: ' + errors[key][0]);
                        }
                    });
                } else {
                    const errorMsg = xhr.responseJSON?.message || '{{ trans("mining-manager::events.create_failed") }}';
                    if (typeof toastr !== 'undefined') {
                        toastr.error(errorMsg);
                    } else {
                        alert('Error: ' + errorMsg);
                    }
                }
            }
        });
    });

    // Preview event
    $('#previewEvent').on('click', function() {
        let preview = 'Event Preview:\n\n';
        preview += 'Name: ' + $('#name').val() + '\n';
        preview += 'Description: ' + $('#description').val() + '\n';
        preview += 'Type: ' + $('#type option:selected').text() + '\n';
        preview += 'Location: ' + ($('#location_scope').val() === 'any' ? 'Any Location' : ($('#solar_system_id option:selected').text() || 'Not Selected')) + '\n';
        preview += 'Start: ' + $('#start_time').val() + '\n';
        preview += 'Tax Modifier: ' + $('#tax_modifier option:selected').text();

        alert(preview);
    });
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
