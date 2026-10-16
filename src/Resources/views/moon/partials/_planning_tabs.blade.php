{{-- The Moon Planner section's tabs, shared by the planner, its blueprints
     and the Extraction Simulator. Somebody with the simulator but not the
     planner, a Moon Finder for one, only sees the tabs they can open.
     $active is 'planner', 'blueprints' or 'simulator'. --}}
<ul class="nav nav-tabs">
    <li class="nav-item">
        <a class="nav-link" href="{{ route('mining-manager.moon.index') }}">
            <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_extractions') }}
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="{{ route('mining-manager.moon.calendar') }}">
            <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.extraction_calendar') }}
        </a>
    </li>
    @canany(\MiningManager\Http\Controllers\MoonPlannerController::PLANNER_ABILITIES)
    <li class="nav-item">
        <a class="nav-link {{ $active === 'planner' ? 'active' : '' }}" href="{{ route('mining-manager.moon.planner') }}">
            <i class="fas fa-calendar-check"></i> {{ trans('mining-manager::menu.moon_planner') }}
            <span class="badge badge-primary ml-1" style="font-size: 0.6em;">Moon Manager</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $active === 'blueprints' ? 'active' : '' }}" href="{{ route('mining-manager.moon.blueprints') }}">
            <i class="fas fa-drafting-compass"></i> Blueprints
            <span class="badge badge-primary ml-1" style="font-size: 0.6em;">Moon Manager</span>
        </a>
    </li>
    @endcanany
    <li class="nav-item">
        <a class="nav-link {{ $active === 'simulator' ? 'active' : '' }}" href="{{ route('mining-manager.moon.calculator') }}">
            <i class="fas fa-flask"></i> {{ trans('mining-manager::menu.moon_value_calculator') }}
        </a>
    </li>
</ul>
