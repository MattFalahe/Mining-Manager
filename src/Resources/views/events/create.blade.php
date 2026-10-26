@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::events.create_event'))
@section('page_header', trans('mining-manager::menu.mining_events'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=10">
@endpush

@section('full')
@include('mining-manager::partials.toastr')
<div class="mining-manager-wrapper mining-dashboard events-create-page">

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
            @if (app(\MiningManager\Services\Events\EventAccess::class)->canCreate(auth()->user()))
            <li class="nav-item">
                <a class="nav-link active" href="{{ route('mining-manager.events.create') }}">
                    <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::menu.create_event') }}
                </a>
            </li>
            @endif
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
        <form id="eventForm" action="{{ route('mining-manager.events.store') }}" method="POST">
            @csrf

            @include('mining-manager::events._form')

            <button type="submit" class="btn btn-mm-primary">
                <i class="fas fa-check"></i> {{ trans('mining-manager::events.create_event') }}
            </button>
            <a href="{{ route('mining-manager.events.index') }}" class="btn btn-outline-secondary ml-2">
                <i class="fas fa-times"></i> {{ trans('mining-manager::events.cancel') }}
            </a>
        </form>
    </div>
</div>

</div>{{-- /.mining-manager-wrapper --}}
@endsection
