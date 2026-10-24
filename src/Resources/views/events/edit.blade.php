@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::events.edit_event'))
@section('page_header', trans('mining-manager::menu.mining_events') . ' - ' . $event->name)

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=10">
@endpush

@section('full')
@include('mining-manager::partials.toastr')
<div class="mining-manager-wrapper mining-dashboard events-edit-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.events.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_events') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.events.active') }}">
                    <i class="fas fa-play-circle"></i> {{ trans('mining-manager::menu.active_events') }}
                </a>
            </li>
            @can('mining-manager.director')
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.events.create') }}">
                    <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::menu.create_event') }}
                </a>
            </li>
            @endcan
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.events.calendar') }}">
                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.event_calendar') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.events.my-events') }}">
                    <i class="fas fa-user-check"></i> {{ trans('mining-manager::menu.my_events') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <form id="eventForm" action="{{ route('mining-manager.events.update', $event->id) }}" method="POST">
            @csrf
            @method('PUT')

            @include('mining-manager::events._form')

            <button type="submit" class="btn btn-mm-primary">
                <i class="fas fa-save"></i> {{ trans('mining-manager::events.save_changes') }}
            </button>
            <a href="{{ route('mining-manager.events.show', $event->id) }}" class="btn btn-outline-secondary ml-2">
                <i class="fas fa-times"></i> {{ trans('mining-manager::events.cancel') }}
            </a>
        </form>

        @can('mining-manager.admin')
            <div class="card card-dark mt-4 mb-0">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-exclamation-triangle text-danger"></i> {{ trans('mining-manager::events.danger_zone') }}</h3>
                </div>
                <div class="card-body">
                    <p>{{ trans('mining-manager::events.delete_warning') }}</p>
                    <form method="POST" action="{{ route('mining-manager.events.destroy', $event->id) }}" onsubmit='return confirm(@json(trans('mining-manager::events.confirm_delete_warning')));'>
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> {{ trans('mining-manager::events.delete_event') }}
                        </button>
                    </form>
                </div>
            </div>
        @endcan
    </div>
</div>

</div>{{-- /.mining-manager-wrapper --}}
@endsection
