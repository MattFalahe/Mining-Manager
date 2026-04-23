{{-- Pending tax period switch banner (renders only if a switch is queued).
     Included here so every tax page gets the warning automatically via the
     shared tab-navigation partial — no per-page include needed. --}}
@include('mining-manager::taxes.partials._pending_period_switch_banner')

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/tax') && !Request::is('*/tax/*') ? 'active' : '' }}" href="{{ route('mining-manager.taxes.index') }}">
                    <i class="fas fa-chart-pie"></i> {{ trans('mining-manager::menu.tax_overview') }}
                </a>
            </li>
            @if(($features['tax_tracking'] ?? true) && auth()->user()?->can('mining-manager.admin'))
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/tax/calculate') ? 'active' : '' }}" href="{{ route('mining-manager.taxes.calculate') }}">
                    <i class="fas fa-calculator"></i> {{ trans('mining-manager::menu.calculate_taxes') }}
                </a>
            </li>
            @endif
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/tax/my-taxes') ? 'active' : '' }}" href="{{ route('mining-manager.taxes.my-taxes') }}">
                    <i class="fas fa-receipt"></i> {{ trans('mining-manager::menu.my_taxes') }}
                </a>
            </li>
            @if($features['tax_codes'] ?? true)
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/tax/codes') ? 'active' : '' }}" href="{{ route('mining-manager.taxes.codes') }}">
                    <i class="fas fa-barcode"></i> {{ trans('mining-manager::menu.tax_codes') }}
                </a>
            </li>
            @endif
            @if($features['wallet_verification'] ?? true)
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/tax/wallet') ? 'active' : '' }}" href="{{ route('mining-manager.taxes.wallet') }}">
                    <i class="fas fa-wallet"></i> {{ trans('mining-manager::menu.wallet_verification') }}
                </a>
            </li>
            @endif
        </ul>
    </div>

    {{-- Scope context banner --}}
    @if($isAdmin ?? false)
        <div class="alert alert-info alert-dismissible mb-0" style="border-radius: 0; margin-top: -1px;">
            <i class="fas fa-shield-alt"></i>
            <strong>{{ trans('mining-manager::taxes.admin_view') }}</strong> &mdash;
            {{ trans('mining-manager::taxes.admin_view_desc') }}
        </div>
    @elseif($isDirector ?? false)
        <div class="alert alert-{{ ($viewAll ?? false) ? 'warning' : 'info' }} alert-dismissible mb-0" style="border-radius: 0; margin-top: -1px;">
            <form action="{{ route('mining-manager.taxes.toggle-view') }}" method="POST" style="display: inline;">
                @csrf
                @if($viewAll ?? false)
                    <i class="fas fa-building"></i>
                    <strong>{{ trans('mining-manager::taxes.viewing_all_corp_data') }}</strong>
                    <button type="submit" class="btn btn-sm btn-default ml-2">
                        <i class="fas fa-user"></i> {{ trans('mining-manager::taxes.switch_to_my_data') }}
                    </button>
                @else
                    <i class="fas fa-user"></i>
                    <strong>{{ trans('mining-manager::taxes.viewing_my_data') }}</strong>
                    <button type="submit" class="btn btn-sm btn-default ml-2">
                        <i class="fas fa-building"></i> {{ trans('mining-manager::taxes.switch_to_all_data') }}
                    </button>
                @endif
            </form>
        </div>
    @else
        <div class="alert alert-info mb-0" style="border-radius: 0; margin-top: -1px;">
            <i class="fas fa-user"></i>
            <strong>{{ trans('mining-manager::taxes.your_account') }}</strong> &mdash;
            {{ trans('mining-manager::taxes.your_account_desc') }}
        </div>
    @endif

    <div class="card-body">
