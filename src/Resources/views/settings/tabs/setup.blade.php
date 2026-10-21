<h4>
    <i class="fas fa-flag"></i>
    Setup
</h4>
<hr>

@unless($usesTaxProfiles)
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    Settings have not moved into tax profiles yet. The plugin's migrations do that when it updates; until they have run, configured corporations cannot be added or removed here.
</div>
@endunless

{{-- Moon Owner Corporation --}}
<div class="card card-dark mb-3">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-moon"></i>
            Moon Owner Corporation
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('mining-manager.settings.update-setup') }}">
            @csrf
            <div class="form-group mb-0">
                <label for="setup_moon_owner">The corporation that owns your refineries and runs the tax program</label>
                <div class="input-group">
                    <select class="form-control" id="setup_moon_owner" name="moon_owner_corporation_id" required>
                        <option value="">-- Select Corporation --</option>
                        @foreach($corporations as $corporation)
                            <option value="{{ $corporation->corporation_id }}" {{ (int) old('moon_owner_corporation_id', $settings['general']['moon_owner_corporation_id'] ?? 0) === (int) $corporation->corporation_id ? 'selected' : '' }}>
                                [{{ $corporation->ticker }}] {{ $corporation->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-mm-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </div>
                <small class="form-text text-muted">
                    Its refineries are the ones tax and theft detection read, and its wallet is the one payments are matched in. It is always a configured corporation. A new Moon Owner that was not configured yet starts with the outgoing one's tax profile, so its members' tax does not change with the switch.
                </small>
            </div>
        </form>
    </div>
</div>

{{-- Configured corporations --}}
<div class="card card-dark mb-3">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-building"></i>
            Configured corporations
        </h5>
    </div>
    <div class="card-body">
        <p>
            Members of these corporations are members here. Each corporation has a tax profile of its own, which taxes its members at your refineries and for their own mining.
            Everyone else is a non-member, taxed by the Global profile, and only for mining at your refineries. A pilot counts as a member of the corporation they were in on the day they mined.
        </p>

        <table class="table table-sm table-mm mb-3">
            <thead>
                <tr>
                    <th>Corporation</th>
                    <th class="text-right"></th>
                </tr>
            </thead>
            <tbody>
                @forelse(array_slice($profiles, 1) as $profile)
                    <tr>
                        <td>
                            @if($profile['ticker'])
                                [{{ $profile['ticker'] }}]
                            @endif
                            {{ $profile['name'] }}
                            @if($profile['moon_owner'])
                                <span class="badge badge-info ml-1">Moon Owner</span>
                            @endif
                        </td>
                        <td class="text-right">
                            @unless($profile['moon_owner'])
                                <form method="POST"
                                      action="{{ route('mining-manager.settings.corporations.remove') }}"
                                      class="d-inline"
                                      onsubmit='return confirm(@json("Stop configuring {$profile['name']}? From then on its members are non-members, taxed by the Global profile at your refineries only."));'>
                                    @csrf
                                    <input type="hidden" name="corporation_id" value="{{ $profile['corporation_id'] }}">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="text-muted">
                            None yet. With no corporation configured, the Global profile taxes everyone.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($usesTaxProfiles)
            <form method="POST" action="{{ route('mining-manager.settings.corporations.add') }}">
                @csrf
                <label for="setup_add_corporation">Add a corporation</label>
                <div class="input-group">
                    <select class="form-control" id="setup_add_corporation" name="corporation_id" required>
                        <option value="">-- Select Corporation --</option>
                        @foreach($addableCorporations as $corporation)
                            <option value="{{ $corporation->corporation_id }}">
                                [{{ $corporation->ticker }}] {{ $corporation->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-mm-primary">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
                <small class="form-text text-muted">
                    It starts with a copy of the Moon Owner's tax profile, which you can then change under Tax profiles.
                </small>
            </form>
        @endif
    </div>
</div>

{{-- Time --}}
<div class="card card-dark mb-3">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-clock"></i>
            Time
        </h5>
    </div>
    <div class="card-body">
        <p class="mb-0">
            Every date the plugin works with is UTC, EVE time: mining days, billing periods, due dates and the moon planner. It matches the timestamps in the EVE API and the calendar your alliance bills moon rentals on.
        </p>
    </div>
</div>
