@php
    // Pages by what they are for, in the order someone setting up the plugin
    // needs them.
    $groups = [
        ['icon' => 'fas fa-coins', 'title' => 'Taxes', 'pages' => [
            ['setup', 'fas fa-flag', 'Setup'],
            ['profiles', 'fas fa-percent', 'Tax profiles'],
            ['billing', 'fas fa-file-invoice-dollar', 'Billing'],
            ['payments', 'fas fa-wallet', 'Payments'],
        ]],
        ['icon' => 'fas fa-moon', 'title' => 'Moons and prices', 'pages' => [
            ['planner', 'fas fa-calendar-check', 'Moon Planner'],
            ['pricing', 'fas fa-tags', trans('mining-manager::settings.pricing')],
        ]],
        ['icon' => 'fas fa-bell', 'title' => 'Alerts', 'pages' => [
            ['webhooks', 'fas fa-satellite-dish', trans('mining-manager::settings.webhooks')],
            ['notifications', 'fas fa-bell', 'Notifications'],
            ['routing-map', 'fas fa-project-diagram', 'Routing Map'],
        ]],
        ['icon' => 'fas fa-puzzle-piece', 'title' => 'Plugin', 'pages' => [
            ['features', 'fas fa-toggle-on', trans('mining-manager::settings.features')],
            ['dashboard', 'fas fa-tachometer-alt', 'Dashboard'],
            ['maintenance', 'fas fa-tools', 'Maintenance'],
            ['help', 'fas fa-question-circle', trans('mining-manager::settings.help')],
        ]],
    ];
@endphp

<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-cog"></i>
            {{ trans('mining-manager::settings.settings_menu') }}
        </h3>
    </div>
    <div class="card-body p-0">
        <ul class="nav nav-pills flex-column">
            @foreach($groups as $index => $group)
                <li class="nav-header px-3 py-2 text-muted small text-uppercase {{ $index > 0 ? 'mt-2' : '' }}" style="background: rgba(102, 126, 234, 0.1); border-left: 3px solid #667eea;">
                    <i class="{{ $group['icon'] }}"></i> {{ $group['title'] }}
                </li>
                @foreach($group['pages'] as [$tab, $icon, $label])
                    <li class="nav-item">
                        <a href="#" class="nav-link {{ $tab === 'setup' ? 'active' : '' }}" data-tab="{{ $tab }}">
                            <i class="{{ $icon }}"></i>
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
            @endforeach
        </ul>
    </div>
</div>

{{-- What a value applies to. The one place it differs is the Tax profiles table, so it says so once here. --}}
<div class="card card-dark mt-3">
    <div class="card-header py-2">
        <h6 class="card-title mb-0">
            <i class="fas fa-info-circle"></i> How settings apply
        </h6>
    </div>
    <div class="card-body py-2">
        <small class="text-muted d-block mb-1">
            <strong>Tax profiles</strong> has a column for each corporation you tax, and one for everyone else, called Global.
        </small>
        <small class="text-muted d-block">
            Every other page applies to the whole install, so a value is set once and used everywhere.
        </small>
    </div>
</div>
