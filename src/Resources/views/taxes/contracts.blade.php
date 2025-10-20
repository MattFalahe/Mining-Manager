@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.tax_contracts'))
@section('page_header', trans('mining-manager::taxes.tax_contracts'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="tax-contracts">
    
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.manage_contracts') }}</h3>
                    <div class="card-tools">
                        <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#generateContractModal">
                            <i class="fas fa-plus"></i> {{ trans('mining-manager::taxes.generate_contracts') }}
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-dark table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::taxes.contract_id') }}</th>
                                <th>{{ trans('mining-manager::taxes.character') }}</th>
                                <th>{{ trans('mining-manager::taxes.amount') }}</th>
                                <th>{{ trans('mining-manager::taxes.status') }}</th>
                                <th>{{ trans('mining-manager::taxes.created') }}</th>
                                <th>{{ trans('mining-manager::taxes.expires') }}</th>
                                <th class="text-center">{{ trans('mining-manager::taxes.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($contracts ?? [] as $contract)
                            <tr>
                                <td>{{ $contract->contract_id }}</td>
                                <td>{{ $contract->character->name ?? 'Unknown' }}</td>
                                <td>{{ number_format($contract->amount, 0) }} ISK</td>
                                <td>
                                    @switch($contract->status)
                                        @case('outstanding')
                                            <span class="badge badge-warning">{{ trans('mining-manager::taxes.outstanding') }}</span>
                                            @break
                                        @case('completed')
                                            <span class="badge badge-success">{{ trans('mining-manager::taxes.completed') }}</span>
                                            @break
                                        @case('expired')
                                            <span class="badge badge-danger">{{ trans('mining-manager::taxes.expired') }}</span>
                                            @break
                                    @endswitch
                                </td>
                                <td>{{ $contract->created_at->format('Y-m-d') }}</td>
                                <td>{{ $contract->expires_at->format('Y-m-d') }}</td>
                                <td class="text-center">
                                    <a href="#" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted">{{ trans('mining-manager::taxes.no_contracts') }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="modal fade" id="generateContractModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('mining-manager::taxes.generate_contracts') }}</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <p>{{ trans('mining-manager::taxes.generate_contracts_help') }}</p>
                <form id="generateContractForm">
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.month') }}</label>
                        <input type="month" class="form-control" name="month" required>
                    </div>
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.expiry_days') }}</label>
                        <input type="number" class="form-control" name="expiry_days" value="7" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('mining-manager::taxes.cancel') }}</button>
                <button type="button" class="btn btn-success" onclick="generateContracts()">{{ trans('mining-manager::taxes.generate') }}</button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
function generateContracts() {
    $('#generateContractModal').modal('hide');
    toastr.success('{{ trans("mining-manager::taxes.contracts_generated") }}');
}
</script>
@endpush
@endsection
