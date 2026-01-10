@extends('web::layouts.grids.12')

@section('title', 'Theft Incident Details')
@section('page_header', 'Theft Incident Details')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard">

    {{-- Back Button --}}
    <div class="row mb-3">
        <div class="col-12">
            <a href="{{ route('mining-manager.theft.index') }}" class="btn btn-default">
                <i class="fas fa-arrow-left"></i> Back to Incidents List
            </a>
        </div>
    </div>

    {{-- Incident Overview --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle"></i> Incident #{{ $incident->id }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge {{ $incident->getSeverityBadgeClass() }} mr-2">
                            {{ ucfirst($incident->severity) }}
                        </span>
                        <span class="badge {{ $incident->getStatusBadgeClass() }}">
                            {{ ucfirst(str_replace('_', ' ', $incident->status)) }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h5><i class="fas fa-user"></i> Character Information</h5>
                            <table class="table table-sm">
                                <tr>
                                    <td style="width: 40%;"><strong>Character:</strong></td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $incident->character_id }}/portrait?size=32"
                                             class="img-circle"
                                             style="width: 32px; height: 32px; margin-right: 5px;">
                                        {{ $incident->getCharacterName() }}
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Character ID:</strong></td>
                                    <td>{{ $incident->character_id }}</td>
                                </tr>
                                <tr>
                                    <td><strong>Corporation:</strong></td>
                                    <td>
                                        @if($incident->corporation_id)
                                        <img src="https://images.evetech.net/corporations/{{ $incident->corporation_id }}/logo?size=32"
                                             class="img-circle"
                                             style="width: 32px; height: 32px; margin-right: 5px;">
                                        @endif
                                        {{ $incident->getCorporationName() }}
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Registered:</strong></td>
                                    <td>
                                        @if($characterInfo['is_registered'])
                                            <span class="badge badge-success">Yes</span>
                                        @else
                                            <span class="badge badge-warning">No</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-6">
                            <h5><i class="fas fa-chart-line"></i> Financial Details</h5>
                            <table class="table table-sm">
                                <tr>
                                    <td style="width: 40%;"><strong>Ore Value:</strong></td>
                                    <td class="text-right">{{ number_format($incident->ore_value, 2) }} ISK</td>
                                </tr>
                                <tr>
                                    <td><strong>Tax Owed:</strong></td>
                                    <td class="text-right text-danger">{{ number_format($incident->tax_owed, 2) }} ISK</td>
                                </tr>
                                <tr>
                                    <td><strong>Quantity Mined:</strong></td>
                                    <td class="text-right">{{ number_format($incident->quantity_mined, 0) }} units</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <h5><i class="fas fa-calendar"></i> Incident Timeline</h5>
                            <table class="table table-sm">
                                <tr>
                                    <td style="width: 40%;"><strong>Incident Date:</strong></td>
                                    <td>{{ $incident->incident_date->format('Y-m-d H:i:s') }}</td>
                                </tr>
                                <tr>
                                    <td><strong>Mining Period:</strong></td>
                                    <td>
                                        {{ $incident->mining_date_from->format('Y-m-d') }}
                                        to
                                        {{ $incident->mining_date_to->format('Y-m-d') }}
                                    </td>
                                </tr>
                                @if($incident->resolved_at)
                                <tr>
                                    <td><strong>Resolved At:</strong></td>
                                    <td>{{ $incident->resolved_at->format('Y-m-d H:i:s') }}</td>
                                </tr>
                                @endif
                            </table>
                        </div>

                        <div class="col-md-6">
                            <h5><i class="fas fa-info-circle"></i> Additional Information</h5>
                            @if($incident->notes)
                            <div class="callout callout-info">
                                <h6>Notes:</h6>
                                <p>{{ $incident->notes }}</p>
                            </div>
                            @else
                            <p class="text-muted">No notes available.</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Mining History --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i> Mining History During Incident Period
                    </h3>
                </div>
                <div class="card-body table-responsive p-0">
                    @if($miningHistory->count() > 0)
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Ore Type</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Value</th>
                                <th>Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($miningHistory as $record)
                            <tr>
                                <td>{{ $record->date->format('Y-m-d H:i') }}</td>
                                <td>
                                    <img src="https://images.evetech.net/types/{{ $record->type_id }}/icon?size=32"
                                         style="width: 32px; height: 32px; margin-right: 5px;">
                                    {{ $record->type->typeName ?? 'Unknown' }}
                                </td>
                                <td class="text-right">{{ number_format($record->quantity, 0) }}</td>
                                <td class="text-right">{{ number_format($record->value, 2) }} ISK</td>
                                <td>{{ $record->solar_system_name ?? 'Unknown' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-dark">
                                <td colspan="2"><strong>Total</strong></td>
                                <td class="text-right"><strong>{{ number_format($miningHistory->sum('quantity'), 0) }}</strong></td>
                                <td class="text-right"><strong>{{ number_format($miningHistory->sum('value'), 2) }} ISK</strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    @else
                    <div class="text-center p-5">
                        <i class="fas fa-info-circle fa-3x text-info mb-3"></i>
                        <h4>No Mining History Available</h4>
                        <p class="text-muted">No mining records found for this character during the incident period.</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Related Tax Record --}}
    @if($incident->miningTax)
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-file-invoice-dollar"></i> Related Tax Record
                    </h3>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td style="width: 30%;"><strong>Tax Month:</strong></td>
                            <td>{{ $incident->miningTax->month->format('F Y') }}</td>
                        </tr>
                        <tr>
                            <td><strong>Amount Owed:</strong></td>
                            <td>{{ number_format($incident->miningTax->amount_owed, 2) }} ISK</td>
                        </tr>
                        <tr>
                            <td><strong>Amount Paid:</strong></td>
                            <td>{{ number_format($incident->miningTax->amount_paid, 2) }} ISK</td>
                        </tr>
                        <tr>
                            <td><strong>Remaining Balance:</strong></td>
                            <td class="text-danger">{{ number_format($incident->miningTax->getRemainingBalance(), 2) }} ISK</td>
                        </tr>
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td><span class="badge badge-warning">{{ ucfirst($incident->miningTax->status) }}</span></td>
                        </tr>
                    </table>
                    <a href="{{ route('mining-manager.taxes.details', $incident->character_id) }}" class="btn btn-sm btn-primary">
                        <i class="fas fa-eye"></i> View Full Tax Details
                    </a>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Actions --}}
    @can('mining-manager.admin')
    @if($incident->isUnresolved())
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-tasks"></i> Actions
                    </h3>
                </div>
                <div class="card-body">
                    <button type="button" class="btn btn-warning" data-toggle="modal" data-target="#investigateModal">
                        <i class="fas fa-search"></i> Mark as Investigating
                    </button>
                    <button type="button" class="btn btn-success" data-toggle="modal" data-target="#resolveModal">
                        <i class="fas fa-check"></i> Resolve Incident
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Investigate Modal --}}
    <div class="modal fade" id="investigateModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form method="POST" action="{{ route('mining-manager.theft.update-status', $incident->id) }}">
                    @csrf
                    <input type="hidden" name="status" value="investigating">
                    <div class="modal-header">
                        <h5 class="modal-title">Mark as Investigating</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="4" placeholder="Investigation notes...">{{ $incident->notes }}</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Mark as Investigating</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Resolve Modal --}}
    <div class="modal fade" id="resolveModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form method="POST" action="{{ route('mining-manager.theft.resolve', $incident->id) }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Resolve Incident</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Resolution Type</label>
                            <select name="resolution_type" class="form-control" required>
                                <option value="resolved">Resolved</option>
                                <option value="false_alarm">False Alarm</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="4" placeholder="Resolution notes...">{{ $incident->notes }}</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Resolve Incident</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
    @endcan

</div>
@endsection
