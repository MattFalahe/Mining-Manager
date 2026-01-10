{{-- Dashboard Settings Tab --}}
<h4>
    <i class="fas fa-tachometer-alt"></i>
    Dashboard Settings
</h4>
<hr>

<div class="info-banner">
    <i class="fas fa-info-circle"></i>
    Configure dashboard display settings including corporation filters for leaderboards.
</div>

<form action="{{ route('mining-manager.settings.update-dashboard') }}" method="POST">
    @csrf
    @method('POST')

    {{-- Hidden field for corporation context --}}
    @if(isset($selectedCorporationId) && $selectedCorporationId)
    <input type="hidden" name="selected_corporation_id" value="{{ $selectedCorporationId }}">
    @endif

    {{-- Leaderboard Corporation Filter --}}
    <div class="card bg-dark mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-trophy"></i>
                Top Miners Leaderboard Corporation Filter
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Control which corporations are shown in the dashboard "Top Miners" leaderboard.
                This affects the rankings displayed on the main dashboard for all users.
            </p>

            {{-- Filter Type Selection --}}
            <div class="form-group">
                <label for="dashboard_leaderboard_corporation_filter">
                    <i class="fas fa-filter"></i> Filter Type
                </label>
                <select name="dashboard_leaderboard_corporation_filter"
                        id="dashboard_leaderboard_corporation_filter"
                        class="form-control">
                    <option value="all" {{ old('dashboard_leaderboard_corporation_filter', ($settings['dashboard']['dashboard_leaderboard_corporation_filter'] ?? 'all')) == 'all' ? 'selected' : '' }}>
                        Show All Corporations
                    </option>
                    <option value="specific" {{ old('dashboard_leaderboard_corporation_filter', ($settings['dashboard']['dashboard_leaderboard_corporation_filter'] ?? 'all')) == 'specific' ? 'selected' : '' }}>
                        Show Specific Corporations Only
                    </option>
                </select>
                <small class="form-text text-muted">
                    Choose whether to show all miners or filter by specific corporations.
                </small>
            </div>

            {{-- Corporation Selection (shown when 'specific' is selected) --}}
            <div id="corporation_selection_container" style="display: none;">
                <div class="form-group">
                    <label for="dashboard_leaderboard_corporation_ids">
                        <i class="fas fa-building"></i> Select Corporations
                    </label>
                    <select name="dashboard_leaderboard_corporation_ids[]"
                            id="dashboard_leaderboard_corporation_ids"
                            class="form-control"
                            multiple
                            size="5">
                        @php
                            $selectedCorps = json_decode(($settings['dashboard']['dashboard_leaderboard_corporation_ids'] ?? '[]'), true);
                        @endphp
                        @foreach($corporations as $corp)
                        <option value="{{ $corp->corporation_id }}"
                                {{ in_array($corp->corporation_id, $selectedCorps) ? 'selected' : '' }}>
                            [{{ $corp->ticker }}] {{ $corp->name }}
                        </option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">
                        Hold Ctrl (Cmd on Mac) to select multiple corporations. Only miners from these corporations will appear in the leaderboard.
                    </small>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Note:</strong> The following corporations have mining activity:
                    <ul class="mb-0 mt-2">
                        <li>Brothers of Tyr (98186730)</li>
                        <li>Cosmos Collective (98664079)</li>
                        <li>Mercurialis Inc. (144534752)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="action-buttons">
        <button type="submit" class="btn btn-success">
            <i class="fas fa-save"></i>
            {{ trans('mining-manager::settings.save_changes') }}
        </button>
        <button type="button" class="btn btn-secondary" id="resetDashboard">
            <i class="fas fa-undo"></i>
            {{ trans('mining-manager::settings.reset') }}
        </button>
    </div>
</form>

@push('javascript')
<script>
$(document).ready(function() {
    // Show/hide corporation selection based on filter type
    function toggleCorporationSelection() {
        var filterType = $('#dashboard_leaderboard_corporation_filter').val();
        if (filterType === 'specific') {
            $('#corporation_selection_container').slideDown();
        } else {
            $('#corporation_selection_container').slideUp();
        }
    }

    // Initial state
    toggleCorporationSelection();

    // On change
    $('#dashboard_leaderboard_corporation_filter').on('change', function() {
        toggleCorporationSelection();
    });

    // Reset button
    $('#resetDashboard').on('click', function() {
        if (confirm('Are you sure you want to reset dashboard settings to defaults?')) {
            $('#dashboard_leaderboard_corporation_filter').val('all');
            $('#dashboard_leaderboard_corporation_ids').val([]);
            toggleCorporationSelection();
        }
    });
});
</script>
@endpush
