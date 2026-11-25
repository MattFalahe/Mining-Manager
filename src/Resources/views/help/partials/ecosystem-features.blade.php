{{-- Features that need another plugin get boxes of their own, so a missing
     plugin shows on the feature it takes away. Red: a crucial plugin is
     missing. Yellow: only an optional one is missing. --}}
@php
    $hub = $ecosystem['hub'];
    $boxes = [
        'mc-pricing' => [
            'icon' => 'fa-tags',
            'title' => 'Prices from Manager Core',
            'text' => 'Manager Core can be the price provider. It fetches prices once for every plugin, and its Pricing Preferences page sets the market and whether Buy, Sell or Average is used.',
            'until' => 'Until then, prices come from Fuzzwork, Janice or SeAT\'s own market prices.',
        ],
        'fast-poll' => [
            'icon' => 'fa-bolt',
            'title' => 'Extraction Started within minutes',
            'text' => 'Manager Core\'s ESI fast-poll picks up the in-game notification about two minutes after a pull starts, and the alert names the pilot who started it.',
            'until' => 'Until then, a new pull is picked up from SeAT\'s moon extraction data on the plugin\'s schedule, and the alert waits for the notification to reach SeAT.',
        ],
        'mining-events' => [
            'icon' => 'fa-broadcast-tower',
            'title' => 'Mining news for other plugins',
            'text' => 'Mining Manager tells the other plugins when a chunk is ready, goes unstable or expires, when a bill is raised, paid, reopened, overdue or written off, and when theft is found. HR Manager and SeAT Broadcast listen, and a plugin can ask about a member\'s tax and recent mining.',
            'until' => 'Until then, nothing is sent. Mining Manager\'s own Discord alerts work without it.',
        ],
        'structure-alerts' => [
            'icon' => 'fa-shield-alt',
            'title' => 'Extraction At Risk and Extraction Lost',
            'text' => 'Structure Manager\'s threat alerts reach Mining Manager. When a refinery with a pull under way runs low on fuel, is reinforced or is destroyed, the alert names the attacker and links to its Structure Board.',
            'until' => 'Until then, the two notifications stay switched off.',
        ],
        'simulator-fuel' => [
            'icon' => 'fa-gas-pump',
            'title' => 'Fuel in the Extraction Simulator',
            'text' => 'The simulator takes fuel off both sides at Structure Manager\'s Fuel Economics prices: 5 fuel blocks an hour for a Moon Drill, 5 blocks and 200 Magmatic Gas for a Metenox. With Structure Manager\'s Economics switched off, both are shown before fuel.',
            'until' => 'Until then, both sides are shown before fuel.',
        ],
        'role-names' => [
            'icon' => 'fa-user-tag',
            'title' => 'Roles picked by name',
            'text' => 'Settings lists your Discord roles to pick from for each notification, and the Notification Routing Map shows each role by its name.',
            'until' => 'Until then, type a role as <@&ROLE_ID> or its ID.',
        ],
    ];
@endphp
<h4 class="eco-heading"><i class="fas fa-project-diagram"></i> With other plugins</h4>
<p>These need another plugin. <a href="#plugins" data-section-link="plugins">Plugins &amp; Ecosystem</a> says what each one adds and whether this server has it.</p>
<div class="feature-grid">
    <div class="feature-item eco-box eco-hub eco-{{ $hub['state'] }}{{ $hub['state'] !== 'on' ? ' eco-pulse' : '' }}">
        @if($hub['banner'])
            <span class="eco-banner">{{ $hub['banner'] }}</span>
        @endif
        <i class="fas fa-network-wired"></i>
        <h5>Manager Core: The Ecosystem Era</h5>
        <p>Manager Core links the plugins: what happens in one reaches the others, one can ask another for what it knows, and they share prices instead of each fetching its own.</p>
        @if($hub['coming_soon'])
            <p class="eco-note">Mining Manager does not connect to it yet. It works on its own today, and a later release brings it into the ecosystem.</p>
        @elseif(!$hub['installed'])
            <p class="eco-note">Manager Core is not installed on this server. Mining Manager works on its own, without what the boxes below add.</p>
        @endif
    </div>
    @foreach($boxes as $key => $box)
        @php $state = $ecosystem['features'][$key]; @endphp
        <div class="feature-item eco-box eco-{{ $state['state'] }}{{ $state['state'] !== 'on' ? ' eco-pulse' : '' }}">
            @if($state['banner'])
                <span class="eco-banner">{{ $state['banner'] }}</span>
            @endif
            <i class="fas {{ $box['icon'] }}"></i>
            <h5>{{ $box['title'] }}</h5>
            <p>{{ $box['text'] }}</p>
            @if($state['state'] === 'on')
                <span class="badge badge-success eco-tag">With {{ $state['using'] }}</span>
            @else
                <p class="eco-note">Needs {{ $state['needs'] }}. {{ $box['until'] }}</p>
            @endif
        </div>
    @endforeach
</div>
