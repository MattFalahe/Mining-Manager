@php
    $corporationLabel = function ($id) use ($reportCorporations) {
        if ($id === null) {
            return 'Global';
        }

        $corporation = $reportCorporations[$id] ?? null;

        return $corporation ? "[{$corporation->ticker}] {$corporation->name}" : "Corporation {$id}";
    };
    $shown = fn ($value) => $value === null ? 'not set' : ($value === '' ? 'empty' : $value);
@endphp

<div class="card card-dark mb-3">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-exchange-alt"></i>
            Move into tax profiles
        </h5>
    </div>
    <div class="card-body">
        @if($transferReport && !\MiningManager\Services\Configuration\SettingsMovedNotice::movedAnything($transferReport))
            <p class="mb-0">
                Settings have been in tax profiles since <strong>{{ $transferReport['transferred_at'] }}</strong> UTC.
                No Moon Owner Corporation was set then and no corporation had settings of its own, as on a new install, so there was nothing to move.
            </p>
        @elseif($transferReport)
            <p>
                Settings moved into tax profiles on <strong>{{ $transferReport['transferred_at'] }}</strong> UTC, when the plugin updated.
                {{ $transferReport['written'] }} values were written where they now live and {{ $transferReport['kept'] }} were there already.
                Every settings row as it was before is kept in the <code>mining_manager_settings_archive</code> table.
            </p>

            <h6>Configured corporations at the time</h6>
            <p>
                @forelse($transferReport['configured'] ?? [] as $id)
                    <span class="badge badge-secondary mr-1">{{ $corporationLabel((int) $id) }}</span>
                @empty
                    None, so the Global profile taxes everyone.
                @endforelse
            </p>

            <h6>What changed in effect, from the day after</h6>
            @if(!empty($transferReport['effects']))
                <ul>
                    @foreach($transferReport['effects'] as $effect)
                        <li>{{ $effect }}</li>
                    @endforeach
                </ul>
            @else
                <p>Nothing: everyone was taxed as before.</p>
            @endif

            @if(!empty($transferReport['writes']))
                <details class="mb-2">
                    <summary>What was written ({{ $transferReport['written'] }})</summary>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm table-mm">
                            <thead>
                                <tr><th>Setting</th><th>Profile</th><th>Was</th><th>Became</th><th>Why</th></tr>
                            </thead>
                            <tbody>
                                @foreach($transferReport['writes'] as $write)
                                    <tr>
                                        <td><code>{{ $write['key'] }}</code></td>
                                        <td>{{ $corporationLabel($write['corporation_id'] ?? null) }}</td>
                                        <td>{{ $shown($write['from'] ?? null) }}</td>
                                        <td>{{ $shown($write['to'] ?? null) }}</td>
                                        <td class="text-muted">{{ $write['reason'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if(!empty($transferReport['writes_cut']))
                        <small class="text-muted">Only the first {{ count($transferReport['writes']) }} were kept with the record.</small>
                    @endif
                </details>
            @endif

            @if(!empty($transferReport['superseded']))
                <details>
                    <summary>Copies the plugin stopped reading ({{ count($transferReport['superseded']) }})</summary>
                    <p class="text-muted mt-2 mb-2">Saved under one corporation for a setting that has one value for the whole install, so nothing reads them.</p>
                    <div class="table-responsive">
                        <table class="table table-sm table-mm">
                            <thead>
                                <tr><th>Setting</th><th>Corporation</th><th>Its copy</th><th>Value in effect</th></tr>
                            </thead>
                            <tbody>
                                @foreach($transferReport['superseded'] as $copy)
                                    <tr>
                                        <td><code>{{ $copy['key'] }}</code></td>
                                        <td>{{ $corporationLabel($copy['corporation_id'] ?? null) }}</td>
                                        <td>{{ $shown($copy['value'] ?? null) }}</td>
                                        <td>{{ ($copy['in_effect'] ?? null) === null ? 'the default' : $shown($copy['in_effect']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif

            @if(($retiredRows ?? 0) > 0)
                <p class="mt-3 mb-0">
                    Once settings were in tax profiles, {{ $retiredRows }} rows nothing reads any more were cleared from the settings table: retired settings, and copies saved under a corporation of settings that have one value for the whole install. A copy of each is kept in the <code>mining_manager_settings_archive</code> table.
                </p>
            @endif
        @else
            <div class="alert alert-warning mb-0">
                <i class="fas fa-exclamation-triangle"></i>
                Settings have not moved into tax profiles yet. The plugin's migrations do that when it updates. To see what the move would do on this install:
                <code>docker exec -it seat-docker-front-1 php artisan mining-manager:settings-transfer --dry-run</code>
            </div>
        @endif
    </div>
</div>
