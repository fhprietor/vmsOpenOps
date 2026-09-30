@extends('vmsopenops::layouts.admin')

@section('title', 'Operations Management')

@section('content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header">
            <h3>Operations Requests</h3>
            <div class="float-right">
                <a href="{{ route('admin.vmsopenops.settings') }}" class="btn btn-info">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </div>
        </div>
        <div class="card-body">
            @include('flash::message')
            
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link {{ $type == 'all' ? 'active' : '' }}" 
                       href="{{ route('admin.vmsopenops.index', ['type' => 'all', 'status' => $status]) }}">
                        All
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $type == 'jumpseat' ? 'active' : '' }}" 
                       href="{{ route('admin.vmsopenops.index', ['type' => 'jumpseat', 'status' => $status]) }}">
                        Jumpseats
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $type == 'ferry' ? 'active' : '' }}" 
                       href="{{ route('admin.vmsopenops.index', ['type' => 'ferry', 'status' => $status]) }}">
                        Ferries
                    </a>
                </li>
            </ul>
            
            <ul class="nav nav-pills mb-3">
                <li class="nav-item">
                    <a class="nav-link {{ $status == 'all' ? 'active' : '' }}" 
                       href="{{ route('admin.vmsopenops.index', ['type' => $type, 'status' => 'all']) }}">
                        All Status
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $status == '0' ? 'active' : '' }}" 
                       href="{{ route('admin.vmsopenops.index', ['type' => $type, 'status' => '0']) }}">
                        Pending
                        <span class="badge badge-warning">{{ \Modules\VmsOpenOps\Models\OperationRequest::pending()->count() }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $status == '1' ? 'active' : '' }}" 
                       href="{{ route('admin.vmsopenops.index', ['type' => $type, 'status' => '1']) }}">
                        Approved
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $status == '2' ? 'active' : '' }}" 
                       href="{{ route('admin.vmsopenops.index', ['type' => $type, 'status' => '2']) }}">
                        Rejected
                    </a>
                </li>
            </ul>
            
            @if($requests->count() > 0)
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            要
                                <th>ID</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Pilot</th>
                                <th>Details</th>
                                <th>Distance</th>
                                <th>Cost</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </thead>
                            <tbody>
                                @foreach($requests as $request)
                                 <tr>
                                     <td>{{ $request->id }}</td>
                                     <td>{{ $request->created_at->format('Y-m-d H:i') }}</td>
                                     <td>
                                        <span class="badge badge-{{ $request->operation_type == 'jumpseat' ? 'info' : 'primary' }}">
                                            {{ $request->operation_type_text }}
                                        </span>
                                     </td>
                                     <td>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            @if (optional($request->user)->avatar)
                                                <img src="{{ $request->user->avatar->url }}"
                                                    style="width:30px;height:30px;border-radius:50%;object-fit:cover;">
                                            @else
                                                <img src="{{ public_asset('images/logo.png') }}"
                                                    style="width:30px;height:30px;border-radius:50%;object-fit:contain;background:#1f1c27;padding:2px;">
                                            @endif
                                            <a href="{{ route('admin.users.show', $request->user_id) }}">
                                                {{ $request->user->ident }} - {{ $request->user->name_private }}
                                            </a>
                                        </div>
                                     </td>
                                     <td>
                                        @if($request->operation_type == 'jumpseat')
                                            <small>{{ $request->from_airport_id }} → {{ $request->to_airport_id }}</small>
                                        @else
                                            <small>
                                                <strong>{{ $request->aircraft->registration ?? 'N/A' }}</strong><br>
                                                {{ $request->from_airport_id }} → {{ $request->to_airport_id }}
                                            </small>
                                        @endif
                                     </td>
                                     <td>{{ number_format($request->distance, 2) }} NM</td>
                                     <td>{{ $request->cost_formatted }}</td>
                                     <td>
                                        <span class="badge {{ $request->status_badge_class }}">
                                            {{ $request->status_text }}
                                        </span>
                                        @if($request->status == 1 && $request->approver)
                                            <small class="d-block">by {{ $request->approver->ident }}</small>
                                        @endif
                                     </td>
                                     <td>
                                        @if($request->status == 0)
                                            <button type="button" class="btn btn-sm btn-success" onclick="$('#approveModal{{ $request->id }}').modal('show')">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="$('#rejectModal{{ $request->id }}').modal('show')">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        @else
                                            @if($request->admin_notes)
                                                <button type="button" class="btn btn-sm btn-info" onclick="$('#notesModal{{ $request->id }}').modal('show')">
                                                    <i class="fas fa-sticky-note"></i> Notes
                                                </button>
                                            @endif
                                        @endif
                                     </td>
                                 </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $requests->links() }}

                    {{-- Modales fuera de la tabla para evitar problemas de z-index con Bootstrap 3 --}}
                    @foreach($requests as $request)
                        @if($request->status == 0)
                        {{-- Modal: Aprobar --}}
                        <div class="modal fade" id="approveModal{{ $request->id }}" tabindex="-1" role="dialog">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <form action="{{ route('admin.vmsopenops.approve', $request->id) }}" method="POST">
                                        @csrf
                                        <div class="modal-header">
                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            <h4 class="modal-title">Aprobar {{ $request->operation_type_text }} #{{ $request->id }}</h4>
                                        </div>
                                        <div class="modal-body">
                                            <p><strong>{{ $request->user->ident }}</strong> — {{ $request->from_airport_id }} → {{ $request->to_airport_id }}</p>
                                            @if($request->operation_type == 'ferry')
                                                <p style="margin:0;font-size:13px;">Aeronave: <strong>{{ $request->aircraft->registration ?? 'N/A' }}</strong> &nbsp;·&nbsp; {{ number_format($request->distance, 2) }} NM &nbsp;·&nbsp; {{ $request->cost_formatted }}</p>
                                            @else
                                                <p style="margin:0;font-size:13px;">{{ number_format($request->distance, 2) }} NM &nbsp;·&nbsp; {{ $request->cost_formatted }}</p>
                                            @endif
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                                            <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Aprobar</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        {{-- Modal: Rechazar --}}
                        <div class="modal fade" id="rejectModal{{ $request->id }}" tabindex="-1" role="dialog">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <form action="{{ route('admin.vmsopenops.reject', $request->id) }}" method="POST">
                                        @csrf
                                        <div class="modal-header">
                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            <h4 class="modal-title">Rechazar {{ $request->operation_type_text }} #{{ $request->id }}</h4>
                                        </div>
                                        <div class="modal-body">
                                            <p><strong>{{ $request->user->ident }}</strong> — {{ $request->from_airport_id }} → {{ $request->to_airport_id }}</p>
                                            <div class="form-group">
                                                <label>Motivo (opcional)</label>
                                                <textarea name="admin_notes" class="form-control" rows="3" placeholder="Explica el motivo del rechazo..."></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                                            <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Rechazar</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        @elseif($request->admin_notes)
                        <div class="modal fade" id="notesModal{{ $request->id }}" tabindex="-1" role="dialog">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                        <h4 class="modal-title">Notas — {{ $request->operation_type_text }} #{{ $request->id }}</h4>
                                    </div>
                                    <div class="modal-body">{{ $request->admin_notes }}</div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif
                    @endforeach
                @else
                    <div class="alert alert-info">No operation requests found.</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
$(document).ready(function () {
    // Move modals to <body> so the Bootstrap 3 backdrop z-index doesn't get trapped
    // inside the admin panel's stacking context
    $('.modal[id^="approveModal"], .modal[id^="rejectModal"], .modal[id^="notesModal"]').appendTo('body');

    $(document).on('shown.bs.modal', '.modal', function () {
        $(this).find('textarea:first').focus();
    });
});
</script>
@endsection