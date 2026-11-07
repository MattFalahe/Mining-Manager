@can('mining-manager.admin')
    @php
        $settingsMoved = app(\MiningManager\Services\Configuration\SettingsMovedNotice::class)->pending();
    @endphp

    @if($settingsMoved)
        <div class="mm-notice">
            <i class="fas fa-exchange-alt"></i>
            Settings moved into tax profiles on <strong>{{ \Carbon\Carbon::parse($settingsMoved['transferred_at'])->format('Y-m-d H:i') }}</strong> UTC, when the plugin updated.
            <a href="{{ route('mining-manager.settings.index') }}#maintenance">See what changed under Settings, Maintenance.</a>
            No webhook taking Plugin Health alerts has carried the message about it yet, so admins see it here for two weeks after the move.
        </div>
    @endif
@endcan
