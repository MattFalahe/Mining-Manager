@php
    $holdMinutes = (int) old('past_hold_minutes', $eventSettings['past_hold_minutes'] ?? 60);
    $managersCanCreate = (bool) old('managers_can_create', $eventSettings['managers_can_create'] ?? true);
    $leadHours = (int) old('manager_lead_hours', $eventSettings['manager_lead_hours'] ?? 24);
    $approvalRule = (string) old('approval_rule', $eventSettings['approval_rule'] ?? '100');
    $approvalChoices = [
        'never' => 'Never: every event is approved when it is saved',
        '25' => 'When it cuts tax by 25% or more',
        '50' => 'When it cuts tax by 50% or more',
        '75' => 'When it cuts tax by 75% or more',
        '100' => 'When it is tax free (default)',
        'always' => 'Always',
    ];
@endphp

<form method="POST" action="{{ route('mining-manager.settings.update-events') }}">
    @csrf

    <h4>
        <i class="fas fa-calendar-day"></i>
        Events
    </h4>
    <hr>

    <p>
        Who can set up <a href="{{ route('mining-manager.events.index') }}">mining events</a>, and how an event waits before it counts.
        Directors and admins can set up any event, including one for days that have passed, and never need approval. These settings
        apply to the whole install. Messages for the directors go to webhooks with <strong>Event Review</strong> ticked, under Webhooks.
    </p>

    {{-- Past events --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-hourglass-half"></i>
                Events for days that have passed
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                An event set up after its days have passed is held before it applies: until then it can be changed or deleted, and the
                Event Review webhooks hear about it. When the hold ends its discount goes on those days, Event Completed goes out with
                its figures, and it locks. A day already on an issued bill keeps what it was billed, so an event can only reach back to
                the first day not billed yet.
            </p>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="past_hold_minutes">Hold before it applies</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="past_hold_minutes" name="past_hold_minutes"
                               min="0" max="2880" step="1" value="{{ $holdMinutes }}">
                        <div class="input-group-append"><span class="input-group-text">minutes</span></div>
                    </div>
                    @error('past_hold_minutes')
                        <small class="invalid-feedback d-block">{{ $message }}</small>
                    @enderror
                    <small class="form-text text-muted">Default 60 minutes, between 0 and 2880 (two days). 0 applies it at the next minute.</small>
                </div>
                <div class="form-group col-md-8">
                    <small class="form-text text-muted mt-md-4">
                        <i class="fas fa-info-circle"></i>
                        The hold always ends half an hour before the bill run that could bill its days, so an event is never held past it.
                        Too close to that run, the event cannot be set up at all.
                    </small>
                </div>
            </div>
        </div>
    </div>

    {{-- Moon managers --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-user-astronaut"></i>
                Moon managers' events
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Someone with the Moon Manager role can set up events that start far enough ahead. They cannot set up one that raises
                tax, or one for days that have passed. An event that needs approval is not announced and counts nothing until a
                director approves it on the event page; if nobody does before it starts, it does not run. Changing an approved event's
                rules so that it needs approval sends it back.
            </p>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" class="custom-control-input" id="managers_can_create" name="managers_can_create" value="1" {{ $managersCanCreate ? 'checked' : '' }}>
                <label class="custom-control-label" for="managers_can_create">Moon managers can set up events</label>
                <small class="form-text text-muted mt-0">Off, only directors and admins can. Events already waiting for approval still wait.</small>
            </div>

            <div class="mm-settings-dim" @if (!$managersCanCreate) style="opacity: 0.55;" @endif>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="manager_lead_hours">Earliest start</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="manager_lead_hours" name="manager_lead_hours"
                                   min="1" max="720" step="1" value="{{ $leadHours }}">
                            <div class="input-group-append"><span class="input-group-text">hours ahead</span></div>
                        </div>
                        @error('manager_lead_hours')
                            <small class="invalid-feedback d-block">{{ $message }}</small>
                        @enderror
                        <small class="form-text text-muted">How long after saving it the event can start. Default 24 hours, between 1 and 720.</small>
                    </div>
                    <div class="form-group col-md-8">
                        <label for="approval_rule">Needs a director's approval</label>
                        <select class="form-control" id="approval_rule" name="approval_rule">
                            @foreach ($approvalChoices as $value => $label)
                                <option value="{{ $value }}" {{ $approvalRule === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('approval_rule')
                            <small class="invalid-feedback d-block">{{ $message }}</small>
                        @enderror
                        <small class="form-text text-muted">Every other event a moon manager sets up is approved as it is saved.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-mm-primary">
        <i class="fas fa-save"></i> Save event settings
    </button>
</form>

@push('javascript')
<script>
$(function () {
    // Dim the moon managers' settings, never the switch, while it is off.
    $('#managers_can_create').on('change', function () {
        $('.mm-settings-dim').css('opacity', this.checked ? '' : '0.55');
    });
});
</script>
@endpush
