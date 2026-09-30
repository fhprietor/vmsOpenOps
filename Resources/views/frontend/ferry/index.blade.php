@extends('vmsopenops::layouts.frontend')

@section('title', 'My Ferry Requests')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-header">
            <h3>My Ferry Requests</h3>
            <div class="float-right">
                @if(!$pendingRequest)
                    <a href="{{ route('vmsopenops.ferry.create') }}" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Ferry Request
                    </a>
                @else
                    <button class="btn btn-secondary" disabled>
                        <i class="fas fa-hourglass-half"></i> Pending Request Active
                    </button>
                @endif
            </div>
        </div>
        <div class="card-body">
            @include('flash::message')
            
            @if($pendingRequest)
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>You have a pending ferry request!</strong>
                    You cannot create a new request until the current one is processed or cancelled.
                    <hr>
                    <div class="small">
                        <strong>Pending Request Details:</strong><br>
                        Aircraft: {{ $pendingRequest->aircraft->registration ?? 'N/A' }}<br>
                        From: {{ $pendingRequest->from_airport_id }} → To: {{ $pendingRequest->to_airport_id }}<br>
                        Distance: {{ number_format($pendingRequest->distance, 2) }} NM<br>
                        Cost: {{ $pendingRequest->cost_formatted }}<br>
                        Created: {{ $pendingRequest->created_at->format('Y-m-d H:i') }}
                    </div>
                </div>
            @endif
            
            @if($requests->count() > 0)
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            要
                                <th>Date</th>
                                <th>Aircraft</th>
                                <th>From → To</th>
                                <th>Distance</th>
                                <th>Cost</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </thead>
                            <tbody>
                                @foreach($requests as $request)
                                <tr>
                                    <td>{{ $request->created_at->format('Y-m-d H:i') }}</td>
                                    <td>
                                        <strong>{{ $request->aircraft->registration ?? 'N/A' }}</strong><br>
                                        <small>{{ $request->subfleet->name ?? 'Unknown' }}</small>
                                    </td>
                                    <td>{{ $request->from_airport_id }} → {{ $request->to_airport_id }}</td>
                                    <td>{{ number_format($request->distance, 2) }} NM</td>
                                    <td>{{ $request->cost_formatted }}</td>
                                    <td>
                                        <span class="badge badge-{{ $request->type == 0 ? 'warning' : 'info' }}">
                                            {{ $request->type_text }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge {{ $request->status_badge_class }}">
                                            {{ $request->status_text }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($request->status == 0)
                                            <form action="{{ route('vmsopenops.ferry.cancel', $request->id) }}" method="POST" style="display: inline;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger" data-vh-confirm="¿Cancelar esta solicitud? Esta acción no se puede deshacer.">
                                                    Cancel
                                                </button>
                                            </form>
                                        @endif
                                        @if($request->status == 2 && $request->admin_notes)
                                            <button type="button" class="btn btn-sm btn-info" data-toggle="modal" data-target="#notesModal{{ $request->id }}">
                                                Notes
                                            </button>
                                            <div class="modal fade" id="notesModal{{ $request->id }}" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Admin Notes</h5>
                                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                        </div>
                                                        <div class="modal-body">
                                                            {{ $request->admin_notes }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $requests->links() }}
                @else
                    <div class="alert alert-info">
                        You haven't made any ferry requests yet.
                        <a href="{{ route('vmsopenops.ferry.create') }}">Request your first ferry</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection