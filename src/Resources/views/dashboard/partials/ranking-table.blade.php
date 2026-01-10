@if(empty($miners))
    <div class="text-center text-muted p-4">No data available</div>
@else
<div class="table-responsive">
    <table class="table table-dark table-striped table-hover">
        <thead>
            <tr>
                <th style="width: 50px">{{ trans('mining-manager::dashboard.rank') }}</th>
                <th>{{ trans('mining-manager::dashboard.character') }}</th>
                <th>{{ trans('mining-manager::dashboard.corporation') }}</th>
                <th class="text-right">{{ trans('mining-manager::dashboard.value') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($miners as $index => $miner)
            <tr @if($miner['main_character_id'] == auth()->user()->main_character_id) style="background: rgba(26, 188, 156, 0.08) !important; border-left: 3px solid #1abc9c;" @endif>
                <td>
                    @if($index < 3)
                        <span class="badge badge-{{ $index == 0 ? 'warning' : ($index == 1 ? 'secondary' : 'bronze') }}">
                            #{{ $index + 1 }}
                        </span>
                    @else
                        <span class="text-muted">#{{ $index + 1 }}</span>
                    @endif
                </td>
                <td>
                    <img src="https://images.evetech.net/characters/{{ $miner['main_character_id'] }}/portrait?size=32"
                         class="img-circle"
                         style="width: 32px; height: 32px;">
                    <strong>{{ $miner['character_name'] }}</strong>
                    @if(!$miner['is_registered'])
                        <span class="badge badge-warning" title="Character not registered in SeAT">
                            <i class="fas fa-exclamation-triangle"></i> Not Registered
                        </span>
                    @endif
                    @if(isset($miner['alt_count']) && $miner['alt_count'] > 0)
                        <span class="badge badge-info" title="Total includes {{ $miner['alt_count'] }} alt character(s)">
                            <i class="fas fa-users"></i> +{{ $miner['alt_count'] }} alts
                        </span>
                    @endif
                </td>
                <td>{{ $miner['corporation_name'] ?? 'Unknown Corporation' }}</td>
                <td class="text-right">
                    <strong>{{ number_format($miner['total_value'], 0) }}</strong>
                    <small class="text-muted">ISK</small>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
