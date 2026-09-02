{{-- Corporation Filter Dropdown --}}
<div class="form-group">
    <label for="corporation_id">
        <i class="fas fa-building"></i> {{ trans('mining-manager::analytics.corporation') ?? 'Corporation' }}
    </label>
    <select class="form-control" id="corporation_id" name="corporation_id">
        {{-- Own corporation first, because that is the default and the question
             most people are asking. All Corporations stays available as the
             deliberate second choice. --}}
        @if(isset($userCorporationId) && $userCorporationId && isset($corporations[$userCorporationId]))
            <option value="{{ $userCorporationId }}"
                {{ (isset($corporationId) && $corporationId == $userCorporationId) ? 'selected' : '' }}>
                {{ $corporations[$userCorporationId] }} (My Corporation)
            </option>
        @endif
        <option value="" {{ empty($corporationId) ? 'selected' : '' }}>All Corporations</option>
        @foreach($corporations as $corpId => $corpName)
            @if(!isset($userCorporationId) || $corpId != $userCorporationId)
                <option value="{{ $corpId }}" {{ (isset($corporationId) && $corporationId == $corpId) ? 'selected' : '' }}>
                    {{ $corpName }}
                </option>
            @endif
        @endforeach
    </select>
</div>
