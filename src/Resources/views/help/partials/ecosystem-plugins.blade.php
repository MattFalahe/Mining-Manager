{{-- Every plugin Mining Manager works with and whether this server has it:
     the crucial ones, a divider, then the optional ones. --}}
@php
    $about = [
        'manager-core' => [
            'icon' => 'fa-network-wired',
            'url' => 'https://github.com/MattFalahe/manager-core',
            'text' => 'The hub of the plugin ecosystem. It carries events and data between the plugins, so each one can use what the others know.',
            'here' => 'Shared prices, Extraction Started within minutes of a pull starting, and chunks, bills and theft passed on to HR Manager and SeAT Broadcast. Structure Manager is reached through it.',
        ],
        'structure-manager' => [
            'icon' => 'fa-building',
            'url' => 'https://github.com/MattFalahe/Structure-Manager',
            'text' => 'Tracks your Upwell structures: fuel, reinforcement timers, attacks and losses.',
            'here' => 'Extraction At Risk and Extraction Lost alerts for a refinery with a pull under way, and from Structure Manager 2.0.5 the fuel in the Extraction Simulator. Both reach it through Manager Core.',
        ],
        'seat-broadcast' => [
            'icon' => 'fa-bullhorn',
            'url' => 'https://github.com/MattFalahe/SeAT-Discord-Pings',
            'text' => 'Sends pings and broadcasts from SeAT to Discord, and keeps a list of your Discord roles with their colours.',
            'here' => 'Settings lists those roles to pick from for notifications. SeAT Connector does the same, so one of the two is enough. With Manager Core, its FC Opportunities board also shows your chunks as they come ready.',
        ],
        'seat-connector' => [
            'icon' => 'fa-plug',
            'url' => 'https://github.com/zenobio93/seat-connector',
            'text' => 'Links SeAT to your Discord server and knows its roles.',
            'here' => 'Settings lists those roles to pick from for notifications. SeAT Broadcast does the same, so one of the two is enough, and the older Warlof Discord connector works too.',
        ],
    ];
    $groups = [
        'crucial' => ['Crucial', 'Needed for full functionality.'],
        'optional' => ['Optional', 'Each adds to one feature. Mining Manager works without them.'],
    ];
    $inGroup = array_count_values(array_column($ecosystem['plugins'], 'role'));
@endphp
<div class="help-card">
    <h3><i class="fas fa-puzzle-piece"></i> Plugins &amp; Ecosystem</h3>
    <p>Mining Manager works on its own. These plugins add to it, and each one says whether this server has it, checked when this page opens.</p>

    @foreach($groups as $role => [$title, $line])
        @if(!$loop->first)
            <hr class="eco-divider">
        @endif
        <h4 class="eco-group">{{ $title }}</h4>
        <p class="eco-group-line">{{ ($inGroup[$role] ?? 0) > 0 ? $line : 'None.' }}</p>
        @foreach($ecosystem['plugins'] as $key => $plugin)
            @continue($plugin['role'] !== $role)
            <div class="eco-plugin">
                <div class="eco-plugin-head">
                    <i class="fas {{ $about[$key]['icon'] }}"></i>
                    <strong>{{ $plugin['name'] }}</strong>
                    <span class="eco-badges">
                        @foreach($plugin['badges'] as $badge)
                            <span class="badge badge-{{ $badge['tone'] }}{{ $badge['pulse'] ? ' eco-pulse' : '' }}">{{ $badge['label'] }}</span>
                        @endforeach
                    </span>
                </div>
                <p>{{ $about[$key]['text'] }}</p>
                <p><strong>In Mining Manager:</strong> {{ $about[$key]['here'] }}</p>
                <a href="{{ $about[$key]['url'] }}" target="_blank" rel="noopener" class="eco-link"><i class="fab fa-github"></i> {{ $plugin['name'] }} on GitHub</a>
            </div>
        @endforeach
    @endforeach
</div>
