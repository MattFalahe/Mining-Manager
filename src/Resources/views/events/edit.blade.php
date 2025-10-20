@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::events.edit_event'))
@section('page_header', trans('mining-manager::events.edit_event') . ' - ' . $event->name)

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
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

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="type">{{ trans('mining-manager::events.event_type') }}</label>
                                    <select class="form-control" id="type" name="type">
                                        <option value="mining_op" {{ $event->type == 'mining_op' ? 'selected' : '' }}>{{ trans('mining-manager::events.mining_op') }}</option>
                                        <option value="moon_extraction" {{ $event->type == 'moon_extraction' ? 'selected' : '' }}>{{ trans('mining-manager::events.moon_extraction') }}</option>
                                        <option value="ice_mining" {{ $event->type == 'ice_mining' ? 'selected' : '' }}>{{ trans('mining-manager::events.ice_mining') }}</option>
                                        <option value="gas_huffing" {{ $event->type == 'gas_huffing' ? 'selected' : '' }}>{{ trans('mining-manager::events.gas_huffing') }}</option>
                                        <option value="special" {{ $event->type == 'special' ? 'selected' : '' }}>{{ trans('mining-manager::events.special') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="location">{{ trans('mining-manager::events.location') }}</label>
                                    <input type="text" class="form-control" id="location" name="location" value="{{ old('location', $event->location) }}">
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
                                        <option value="upcoming" {{ $event->status == 'upcoming' ? 'selected' : '' }}>{{ trans('mining-manager::events.upcoming') }}</option>
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
                            <label for="tax_modifier">{{ trans('mining-manager::events.tax_modifier') }} (%)</label>
                            <input type="number" class="form-control" id="tax_modifier" name="tax_modifier" value="{{ old('tax_modifier', $event->tax_modifier) }}" step="0.1">
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
    $('#eventForm').on('submit', function(e) {
        e.preventDefault();
        
        $.ajax({
            url: $(this).attr('action'),
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                toastr.success(response.message);
                setTimeout(() => window.location.href = '{{ route("mining-manager.events.show", $event->id) }}', 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::events.update_failed") }}');
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
                    toastr.success(response.message);
                    setTimeout(() => window.location.href = '{{ route("mining-manager.events.index") }}', 1000);
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message);
                }
            });
        }
    });
});
</script>
@endpush
@endsection
