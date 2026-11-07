@php
    $configured = count($profiles) > 1;
    // Whether today is a day a billing period can change on the day at all.
    $sameDayChange = collect($profiles)->contains(fn ($profile) => $profile['change_today']);
    $rarities = ['r4' => 'R4', 'r8' => 'R8', 'r16' => 'R16', 'r32' => 'R32', 'r64' => 'R64'];
    $others = ['ore' => 'Ore', 'ice' => 'Ice', 'gas' => 'Gas', 'abyssal_ore' => 'Abyssal ore', 'triglavian_ore' => 'Triglavian ore'];
    $moonChoices = ['all' => 'All moon ore', 'ours' => 'Only at your refineries', 'none' => 'Not taxed'];

    // What the Global profile's moon rates mean for non-members.
    $global = $profiles[0];
    $globalRates = array_map('floatval', $global['rates']['moon_ore']);
    $free = array_keys(array_filter($globalRates, fn ($rate) => $rate <= 0));
    $full = array_keys(array_filter($globalRates, fn ($rate) => $rate >= 100));
    $names = fn (array $keys) => implode(', ', array_map(fn ($key) => $rarities[$key], $keys));

    if (!$configured) {
        $mode = ['info', 'No corporation is configured, so this profile taxes everyone, for all of their mining.'];
    } elseif ($global['moon'] === 'none' || count($free) === count($globalRates)) {
        $mode = ['warning', 'Non-members mine your moons for free. Theft detection cannot flag anyone, as they never owe anything.'];
    } elseif (count($full) === count($globalRates)) {
        $mode = ['danger', 'Only configured corporations may mine your moons. Non-members are billed 100% and flagged as theft as soon as their mining is imported. Paying the bill closes the incident.'];
    } elseif (count($free) + count($full) === count($globalRates)) {
        $mode = ['info', "Non-members mine {$names($free)} for free and pay full price for {$names($full)}. A bill unpaid {$overdueDays} days past its due date counts as theft."];
    } else {
        $mode = ['info', "Non-members are billed at these rates, for mining at your refineries only. A bill unpaid {$overdueDays} days past its due date counts as theft."];
    }
@endphp

<style>
    .mm-profiles th, .mm-profiles td { vertical-align: middle; }
    .mm-profiles thead th { min-width: 180px; text-align: center; }
    .mm-profiles thead th:first-child { min-width: 150px; }
    .mm-profiles .mm-profile-group th { background: rgba(102, 126, 234, 0.12); text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.04em; }
    .mm-profiles .mm-profile-who td { font-size: 0.8rem; }
    .mm-profiles .mm-profile-who .alert { margin: 0; padding: 6px 8px; font-size: 0.8rem; }
    .mm-profiles .input-group { flex-wrap: nowrap; }
    .mm-profiles .mm-dim { opacity: 0.45; }
</style>

<h4>
    <i class="fas fa-percent"></i>
    Tax profiles
</h4>
<hr>

<p>
    A tax profile decides how often bills go out, the prefix on their payment codes, and what is taxed at which rate.
    Each configured corporation has its own, and Global taxes everyone else. A day's mining is taxed by the profile of the corporation the pilot was in that day;
    an account's bills follow the profile of its main character's corporation on the first of the month.
</p>

@unless($usesTaxProfiles)
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    Settings have not moved into tax profiles yet. The plugin's migrations do that when it updates.
</div>
@else
<form method="POST" action="{{ route('mining-manager.settings.update-profiles') }}" id="mm-profiles-form">
    @csrf

    <div class="table-responsive">
        <table class="table table-sm table-mm mm-profiles">
            <thead>
                <tr>
                    <th></th>
                    @foreach($profiles as $profile)
                        <th>
                            @if($profile['corporation_id'] === null)
                                Global
                            @else
                                @if($profile['ticker'])
                                    [{{ $profile['ticker'] }}]
                                @endif
                                {{ $profile['name'] }}
                                @if($profile['moon_owner'])
                                    <br><span class="badge badge-info">Moon Owner</span>
                                @endif
                            @endif
                        </th>
                    @endforeach
                </tr>
                <tr class="mm-profile-who">
                    <td class="text-muted">Taxes</td>
                    @foreach($profiles as $profile)
                        <td>
                            @if($profile['corporation_id'] === null)
                                <div class="alert alert-{{ $mode[0] }}">{{ $mode[1] }}</div>
                            @else
                                <span class="text-muted">Its members, at your refineries and in their own mining</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr class="mm-profile-group"><th colspan="{{ count($profiles) + 1 }}">Billing</th></tr>
                <tr>
                    <th>Billing period</th>
                    @foreach($profiles as $profile)
                        <td>
                            <select class="form-control form-control-sm" name="profiles[{{ $profile['key'] }}][period]">
                                <option value="monthly" {{ old("profiles.{$profile['key']}.period", $profile['pending'] ?: $profile['period']) === 'monthly' ? 'selected' : '' }}>Monthly</option>
                                <option value="biweekly" {{ old("profiles.{$profile['key']}.period", $profile['pending'] ?: $profile['period']) === 'biweekly' ? 'selected' : '' }}>Twice a month</option>
                            </select>
                            @if($profile['pending'] && $profile['pending_from'])
                                <small class="text-warning d-block mt-1">
                                    {{ $profile['period'] === 'biweekly' ? 'Twice a month' : 'Monthly' }} until {{ \Carbon\Carbon::parse($profile['pending_from'])->subDay()->format('M j') }}
                                </small>
                            @elseif($sameDayChange && !$profile['change_today'])
                                <small class="text-muted d-block mt-1">
                                    Has billed part of this month, so a new period starts on {{ $periodChangeDate->format('M j') }}
                                </small>
                            @endif
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <th>Tax code prefix</th>
                    @foreach($profiles as $profile)
                        <td>
                            <input type="text" class="form-control form-control-sm" maxlength="10" required
                                   name="profiles[{{ $profile['key'] }}][prefix]"
                                   value="{{ old("profiles.{$profile['key']}.prefix", $profile['prefix']) }}">
                        </td>
                    @endforeach
                </tr>

                <tr class="mm-profile-group"><th colspan="{{ count($profiles) + 1 }}">Moon ore</th></tr>
                <tr>
                    <th>Taxed</th>
                    @foreach($profiles as $profile)
                        <td>
                            <select class="form-control form-control-sm mm-moon-choice" data-profile="{{ $profile['key'] }}" name="profiles[{{ $profile['key'] }}][moon]">
                                @foreach($moonChoices as $value => $label)
                                    <option value="{{ $value }}" {{ old("profiles.{$profile['key']}.moon", $profile['moon']) === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </td>
                    @endforeach
                </tr>
                @foreach($rarities as $rarity => $label)
                    <tr>
                        <th>{{ $label }}</th>
                        @foreach($profiles as $profile)
                            <td class="mm-moon-rate" data-profile="{{ $profile['key'] }}">
                                <div class="input-group input-group-sm">
                                    <input type="number" class="form-control" min="0" max="100" step="0.01" required
                                           name="profiles[{{ $profile['key'] }}][rates][moon_ore][{{ $rarity }}]"
                                           value="{{ old("profiles.{$profile['key']}.rates.moon_ore.{$rarity}", $profile['rates']['moon_ore'][$rarity]) }}">
                                    <div class="input-group-append"><span class="input-group-text">%</span></div>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach

                <tr class="mm-profile-group"><th colspan="{{ count($profiles) + 1 }}">Their own mining</th></tr>
                @foreach($others as $other => $label)
                    <tr>
                        <th>{{ $label }}</th>
                        @foreach($profiles as $profile)
                            @php
                                // Non-members' own mining is never taxed, so Global has nothing to set here while corporations are configured.
                                $locked = $configured && $profile['corporation_id'] === null;
                                $taxed = (bool) old("profiles.{$profile['key']}.taxed.{$other}", $profile['selector'][$other] ?? false);
                            @endphp
                            <td class="{{ $locked ? 'mm-dim' : '' }}" title="{{ $locked ? 'Non-members\' own mining is never taxed' : '' }}">
                                <div class="input-group input-group-sm mm-other {{ !$locked && !$taxed ? 'mm-dim' : '' }}">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <input type="checkbox" class="mm-other-taxed" value="1" title="Taxed"
                                                   name="profiles[{{ $profile['key'] }}][taxed][{{ $other }}]"
                                                   {{ $taxed ? 'checked' : '' }} {{ $locked ? 'disabled' : '' }}>
                                        </div>
                                    </div>
                                    <input type="number" class="form-control" min="0" max="100" step="0.01"
                                           name="profiles[{{ $profile['key'] }}][rates][{{ $other }}]"
                                           value="{{ old("profiles.{$profile['key']}.rates.{$other}", $profile['rates'][$other]) }}"
                                           {{ $locked ? 'disabled' : '' }}>
                                    <div class="input-group-append"><span class="input-group-text">%</span></div>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($sameDayChange)
        <div class="custom-control custom-checkbox mb-2">
            <input type="checkbox" class="custom-control-input" id="apply_period_now" name="apply_period_now" value="1">
            <label class="custom-control-label" for="apply_period_now">Change billing periods today</label>
            <small class="form-text text-muted">
                Left unticked, a new billing period starts on {{ $periodChangeDate->format('F j') }}, after the bills for this one have gone out under the old period.
                Ticked, it starts today for every profile that has not billed any of this month yet. That is only possible up to the 15th: later, part of the month would be billed twice or not at all.
            </small>
        </div>
    @else
        <p class="text-muted small mb-2">
            <i class="fas fa-calendar-alt"></i>
            A new billing period starts on {{ $periodChangeDate->format('F j') }}, after the bills for this one have gone out under the old period.
            It can only start on the day from the 3rd to the 15th of a month, while none of the month has been billed. Before the 3rd, last month's bills have not gone out yet, and after the 15th part of the month would be billed twice or not at all.
        </p>
    @endif

    <button type="submit" class="btn btn-mm-primary">
        <i class="fas fa-save"></i> Save tax profiles
    </button>
</form>

@push('javascript')
<script>
$(function () {
    // Moon rates do nothing when a profile taxes no moon ore, and an ore's
    // rate does nothing when it is not taxed. Dimmed, still editable.
    function dimMoon(select) {
        var none = $(select).val() === 'none';
        $('.mm-moon-rate[data-profile="' + $(select).data('profile') + '"]').toggleClass('mm-dim', none);
    }

    $('.mm-moon-choice').each(function () { dimMoon(this); }).on('change', function () { dimMoon(this); });

    $('.mm-other-taxed').on('change', function () {
        $(this).closest('.mm-other').toggleClass('mm-dim', !this.checked);
    });
});
</script>
@endpush
@endunless
