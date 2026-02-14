@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::events.edit_event'))
@section('page_header', trans('mining-manager::menu.mining_events')) . ' - ' . $event->name)

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper events-edit-page">

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


<div class="edit-event">
    
    <form id="eventForm" action="{{ route('mining-manager.events.update', $event->id) }}" method="POST">
        @csrf
        @method('PUT')
        
        <div class="row">
            <div class="col-md-8">
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title">{{ trans('mining-manager::events.basic_information') }}</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="name">{{ trans('mining-manager::events.event_name') }} <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" value="{{ old('name', $event->name) }}" required>
                        </div>

                        <div class="form-group">
                            <label for="description">{{ trans('mining-manager::events.description') }}</label>
                            <textarea class="form-control" id="description" name="description" rows="4">{{ old('description', $event->description) }}</textarea>
                        </div>

                        <div class="form-group">
                            <label for="type">{{ trans('mining-manager::events.event_type') }}</label>
                            <select class="form-control" id="type" name="type">
                                <option value="mining_op" {{ old('type', $event->type) == 'mining_op' ? 'selected' : '' }}>{{ trans('mining-manager::events.mining_op') }}</option>
                                <option value="moon_extraction" {{ old('type', $event->type) == 'moon_extraction' ? 'selected' : '' }}>{{ trans('mining-manager::events.moon_extraction') }}</option>
                                <option value="ice_mining" {{ old('type', $event->type) == 'ice_mining' ? 'selected' : '' }}>{{ trans('mining-manager::events.ice_mining') }}</option>
                                <option value="gas_huffing" {{ old('type', $event->type) == 'gas_huffing' ? 'selected' : '' }}>{{ trans('mining-manager::events.gas_huffing') }}</option>
                                <option value="special" {{ old('type', $event->type) == 'special' ? 'selected' : '' }}>{{ trans('mining-manager::events.special') }}</option>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="location_scope">{{ trans('mining-manager::events.location_scope') }}</label>
                                    <select class="form-control" id="location_scope" name="location_scope">
                                        <option value="any" {{ old('location_scope', $event->location_scope) == 'any' ? 'selected' : '' }}>{{ trans('mining-manager::events.scope_any') }}</option>
                                        <option value="region" {{ old('location_scope', $event->location_scope) == 'region' ? 'selected' : '' }}>{{ trans('mining-manager::events.scope_region') }}</option>
                                        <option value="constellation" {{ old('location_scope', $event->location_scope) == 'constellation' ? 'selected' : '' }}>{{ trans('mining-manager::events.scope_constellation') }}</option>
                                        <option value="system" {{ old('location_scope', $event->location_scope) == 'system' ? 'selected' : '' }}>{{ trans('mining-manager::events.scope_system') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group" id="location-select-group" style="{{ old('location_scope', $event->location_scope ?? 'any') == 'any' ? 'display:none;' : '' }}">
                                    <label for="solar_system_id">{{ trans('mining-manager::events.location') }}</label>
                                    <select class="form-control select2-location" id="solar_system_id" name="solar_system_id" style="width: 100%;">
                                        @if($event->solar_system_id)
                                        <option value="{{ $event->solar_system_id }}" selected>{{ $event->getLocationName() ?? 'Selected Location' }}</option>
                                        @endif
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="start_time">{{ trans('mining-manager::events.start_time') }}</label>
                                    <input type="datetime-local" class="form-control" id="start_time" name="start_time" value="{{ old('start_time', $event->start_time->format('Y-m-d\TH:i')) }}" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="end_time">{{ trans('mining-manager::events.end_time') }}</label>
                                    <input type="datetime-local" class="form-control" id="end_time" name="end_time" value="{{ old('end_time', $event->end_time ? $event->end_time->format('Y-m-d\TH:i') : '') }}">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="status">{{ trans('mining-manager::events.status') }}</label>
                                    <select class="form-control" id="status" name="status">
                                        <option value="planned" {{ $event->status == 'planned' ? 'selected' : '' }}>{{ trans('mining-manager::events.planned') }}</option>
                                        <option value="active" {{ $event->status == 'active' ? 'selected' : '' }}>{{ trans('mining-manager::events.active') }}</option>
                                        <option value="completed" {{ $event->status == 'completed' ? 'selected' : '' }}>{{ trans('mining-manager::events.completed') }}</option>
                                        <option value="cancelled" {{ $event->status == 'cancelled' ? 'selected' : '' }}>{{ trans('mining-manager::events.cancelled') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="max_participants">{{ trans('mining-manager::events.max_participants') }}</label>
                                    <input type="number" class="form-control" id="max_participants" name="max_participants" value="{{ old('max_participants', $event->max_participants) }}">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="tax_modifier">{{ trans('mining-manager::events.tax_modifier') }}</label>
                            <select class="form-control @error('tax_modifier') is-invalid @enderror" id="tax_modifier" name="tax_modifier" required>
                                <option value="-100" {{ old('tax_modifier', $event->tax_modifier) == -100 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_tax_free') }}</option>
                                <option value="-75" {{ old('tax_modifier', $event->tax_modifier) == -75 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_reduced_75') }}</option>
                                <option value="-50" {{ old('tax_modifier', $event->tax_modifier) == -50 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_reduced_50') }}</option>
                                <option value="-25" {{ old('tax_modifier', $event->tax_modifier) == -25 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_reduced_25') }}</option>
                                <option value="0" {{ old('tax_modifier', $event->tax_modifier) == 0 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_normal') }}</option>
                                <option value="25" {{ old('tax_modifier', $event->tax_modifier) == 25 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_increase_25') }}</option>
                                <option value="50" {{ old('tax_modifier', $event->tax_modifier) == 50 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_increase_50') }}</option>
                                <option value="75" {{ old('tax_modifier', $event->tax_modifier) == 75 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_increase_75') }}</option>
                                <option value="100" {{ old('tax_modifier', $event->tax_modifier) == 100 ? 'selected' : '' }}>{{ trans('mining-manager::events.modifier_double') }}</option>
                            </select>
                            @error('tax_modifier')
                            <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">{{ trans('mining-manager::events.tax_modifier_help') }}</small>
                        </div>

                        <div class="form-check mb-2">
                            <input type="checkbox" class="form-check-input" id="is_mandatory" name="is_mandatory" value="1" {{ $event->is_mandatory ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_mandatory">{{ trans('mining-manager::events.mandatory_event') }}</label>
                        </div>

                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="send_notifications" name="send_notifications" value="1" checked>
                            <label class="form-check-label" for="send_notifications">{{ trans('mining-manager::events.notify_participants') }}</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title">{{ trans('mining-manager::events.event_statistics') }}</h3>
                    </div>
                    <div class="card-body">
                        <p><strong>{{ trans('mining-manager::events.participants') }}:</strong> {{ $event->participants_count ?? 0 }}</p>
                        <p><strong>{{ trans('mining-manager::events.total_mined') }}:</strong> {{ number_format($event->total_mined_value ?? 0, 0) }} ISK</p>
                        <p><strong>{{ trans('mining-manager::events.created') }}:</strong> {{ $event->created_at->diffForHumans() }}</p>
                        <p><strong>{{ trans('mining-manager::events.organizer') }}:</strong> {{ $event->organizer->name ?? 'N/A' }}</p>
                    </div>
                </div>

                <div class="card card-danger">
                    <div class="card-header">
                        <h3 class="card-title">{{ trans('mining-manager::events.danger_zone') }}</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">{{ trans('mining-manager::events.delete_warning') }}</p>
                        <button type="button" class="btn btn-danger btn-block" id="deleteEvent">
                            <i class="fas fa-trash"></i> {{ trans('mining-manager::events.delete_event') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card card-dark">
                    <div class="card-footer">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-save"></i> {{ trans('mining-manager::events.save_changes') }}
                        </button>
                        <a href="{{ route('mining-manager.events.show', $event->id) }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> {{ trans('mining-manager::events.cancel') }}
                        </a>
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

    $('#eventForm').on('submit', function(e) {
        e.preventDefault();

        $.ajax({
            url: $(this).attr('action'),
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (typeof toastr !== 'undefined') {
                    toastr.success(response.message);
                }
                setTimeout(() => window.location.href = '{{ route("mining-manager.events.show", $event->id) }}', 1000);
            },
            error: function(xhr) {
                const errorMsg = xhr.responseJSON?.message || '{{ trans("mining-manager::events.update_failed") }}';
                if (typeof toastr !== 'undefined') {
                    toastr.error(errorMsg);
                } else {
                    alert('Error: ' + errorMsg);
                }
            }
        });
    });

    $('#deleteEvent').on('click', function() {
        if (confirm('{{ trans("mining-manager::events.confirm_delete_warning") }}')) {
            $.ajax({
                url: '{{ route("mining-manager.events.destroy", $event->id) }}',
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                success: function(response) {
                    if (typeof toastr !== 'undefined') {
                        toastr.success(response.message);
                    }
                    setTimeout(() => window.location.href = '{{ route("mining-manager.events.index") }}', 1000);
                },
                error: function(xhr) {
                    const errorMsg = xhr.responseJSON?.message || 'Delete failed';
                    if (typeof toastr !== 'undefined') {
                        toastr.error(errorMsg);
                    } else {
                        alert('Error: ' + errorMsg);
                    }
                }
            });
        }
    });
});
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
