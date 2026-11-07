@php
    // Kept in hours, shown in days.
    $spacingHours = (int) ($notificationSettings['min_refinery_spacing_hours'] ?? \MiningManager\Services\Moon\MoonPlannerService::DEFAULT_REFINERY_SPACING_HOURS);
    $spacingDays = rtrim(rtrim(number_format($spacingHours / 24, 2, '.', ''), '0'), '.');
@endphp

<form method="POST" action="{{ route('mining-manager.settings.update-planner') }}">
    @csrf

    <h4>
        <i class="fas fa-calendar-check"></i>
        Moon Planner
    </h4>
    <hr>

    <p>
        How the <a href="{{ route('mining-manager.moon.planner') }}">Moon Extraction Planner</a> spaces your pulls, and when its
        reminders go out. Every rule here warns rather than blocks: a pull that breaks one can still be saved once you confirm it.
        Who receives the reminders is set under Webhooks, and their switches and role pings under Notifications.
    </p>

    {{-- Gap between moons --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-calendar-check"></i>
                Gap between moons
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Chunks not mined promptly can be wasted, so the planner warns when two moons' chunks arrive closer together than this.
                Smaller corporations may want a wider gap, such as 36 hours, to give a small crew time to clear each belt; larger
                groups can tighten it, to 12 hours for example.
            </p>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="min_extraction_gap_hours">Minimum gap between arrivals</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="min_extraction_gap_hours" name="min_extraction_gap_hours"
                               min="1" max="168" step="1"
                               value="{{ old('min_extraction_gap_hours', $notificationSettings['min_extraction_gap_hours'] ?? 24) }}">
                        <div class="input-group-append"><span class="input-group-text">hours</span></div>
                    </div>
                    @error('min_extraction_gap_hours')
                        <small class="invalid-feedback d-block">{{ $message }}</small>
                    @enderror
                    <small class="form-text text-muted">Default 24 hours, between 1 and 168.</small>
                </div>
                <div class="form-group col-md-8">
                    <small class="form-text text-muted mt-md-4">
                        <i class="fas fa-info-circle"></i>
                        Placing or moving a pull within this window of another arrival asks you to confirm, and Auto-fill uses it to
                        spread the pulls it projects.
                    </small>
                </div>
            </div>
        </div>
    </div>

    {{-- Time between pulls on one refinery --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-ruler-horizontal"></i>
                Time between pulls on one refinery
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                An extraction takes at least 6 days, and the next one can only be started once the chunk is in, so the default of
                <strong>7 days</strong>, arrival to arrival, leaves a day to start it. Anything closer is flagged when you plan or move a
                pull, in the blueprint preview and on the planner, and Auto-fill leaves it out.
            </p>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="min_refinery_spacing_days">Minimum time between pulls</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="min_refinery_spacing_days" name="min_refinery_spacing_days"
                               min="6" max="56" step="any"
                               value="{{ old('min_refinery_spacing_days', $spacingDays) }}">
                        <div class="input-group-append"><span class="input-group-text">days</span></div>
                    </div>
                    @error('min_refinery_spacing_days')
                        <small class="invalid-feedback d-block">{{ $message }}</small>
                    @enderror
                    <small class="form-text text-muted">Between 6 and 56 days.</small>
                </div>
                <div class="form-group col-md-8">
                    <small class="form-text text-muted mt-md-4">
                        <i class="fas fa-info-circle"></i>
                        Counted in hours: at 7 days, a pull on the 1st at 17:00 leaves the 8th at 17:00 clear, and the 7th at 17:00
                        gets the warning.
                    </small>
                </div>
            </div>
        </div>
    </div>

    {{-- Reminders --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-hourglass-half"></i>
                Reminders
            </h5>
        </div>
        <div class="card-body">
            <div class="small text-muted mb-3">
                Both reminders skip a refinery that cannot pull a chunk:
                <ul class="mb-1 pl-3">
                    <li>no moon drill fitted, or the drill has been unfitted</li>
                    <li>unanchored, destroyed or handed over, so it is gone from your corporation's structures</li>
                    <li>reported destroyed by the game, even before SeAT's structure list catches up</li>
                    <li>unanchoring with no extraction running. While one is still running, it is treated as usual.</li>
                </ul>
            </div>

            <h6>Moon Not Rescheduled</h6>
            <p class="text-muted">
                Goes out when a refinery's chunk arrived this long ago and no new extraction has been started on it since. It stops when
                an extraction starts, and waits while the drill is offline, or while the next planned pull is more than 56 days ahead and
                cannot be set up yet.
            </p>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="moon_not_rescheduled_hours">Remind after the drill has been idle for</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="moon_not_rescheduled_hours" name="moon_not_rescheduled_hours"
                               min="1" max="336" step="1"
                               value="{{ old('moon_not_rescheduled_hours', $notificationSettings['moon_not_rescheduled_hours'] ?? 48) }}">
                        <div class="input-group-append"><span class="input-group-text">hours</span></div>
                    </div>
                    @error('moon_not_rescheduled_hours')
                        <small class="invalid-feedback d-block">{{ $message }}</small>
                    @enderror
                    <small class="form-text text-muted">
                        Counted from the chunk's arrival, or from when the next planned pull can be started if it was more than 56 days
                        ahead. Default 48 hours.
                    </small>
                </div>
                <div class="form-group col-md-8">
                    <div class="custom-control custom-switch mt-md-4">
                        <input type="checkbox" class="custom-control-input" id="moon_not_rescheduled_repeat" name="moon_not_rescheduled_repeat" value="1"
                               {{ old('moon_not_rescheduled_repeat', $notificationSettings['moon_not_rescheduled_repeat'] ?? true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="moon_not_rescheduled_repeat">
                            Remind again every that many hours until an extraction starts
                        </label>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <h6>Moons Need Planning</h6>
            <p class="text-muted">
                One message listing every refinery with fewer pulls planned ahead than the number below, counted the same way as the
                planner's "Not planned" badge, fewest planned and richest moons first. A long list is cut short with a count of the rest,
                and always ends with the total. It is only sent while something falls short, and a refinery whose drill is offline stays
                on the list, marked as such.
            </p>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="planned_ahead_target">Pulls each refinery should have planned</label>
                    <input type="number" class="form-control" id="planned_ahead_target" name="planned_ahead_target"
                           min="1" max="10" step="1"
                           value="{{ old('planned_ahead_target', $notificationSettings['planned_ahead_target'] ?? 1) }}">
                    @error('planned_ahead_target')
                        <small class="invalid-feedback d-block">{{ $message }}</small>
                    @enderror
                    <small class="form-text text-muted">Default 1: every refinery has its next pull planned.</small>
                </div>
                <div class="form-group col-md-4">
                    <label for="schedule_needs_filling_hours">Send the list at most every</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="schedule_needs_filling_hours" name="schedule_needs_filling_hours"
                               min="1" max="336" step="1"
                               value="{{ old('schedule_needs_filling_hours', $notificationSettings['schedule_needs_filling_hours'] ?? 24) }}">
                        <div class="input-group-append"><span class="input-group-text">hours</span></div>
                    </div>
                    @error('schedule_needs_filling_hours')
                        <small class="invalid-feedback d-block">{{ $message }}</small>
                    @enderror
                    <small class="form-text text-muted">Default 24 hours. Checked with every extraction import.</small>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-mm-primary">
        <i class="fas fa-save"></i> Save moon planner
    </button>
</form>
