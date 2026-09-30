@extends('vmsopenops::layouts.admin')

@section('title', 'Operations Settings')

@section('content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header">
            <h3>Operations Settings</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.vmsopenops.update-settings') }}">
                @csrf
                
                <h4>Jumpseat Settings</h4>
                <hr>
                
                <div class="form-group">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="jumpseat_enabled" name="jumpseat_enabled" value="1" {{ setting('vms_open_ops_jumpseat_enabled', true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="jumpseat_enabled">Enable Jumpseat Operations</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Jumpseat Cost per Nautical Mile (cents)</label>
                    <input type="number" name="jumpseat_cost_per_nm" class="form-control" value="{{ setting('vms_open_ops_jumpseat_cost_per_nm', 250) }}" min="0">
                    <small class="text-muted">Example: 250 = $2.50 per NM</small>
                </div>

                <div class="form-group">
                    <label>Jumpseat Minimum Cost (cents)</label>
                    <input type="number" name="jumpseat_min_cost" class="form-control" value="{{ setting('vms_open_ops_jumpseat_min_cost', 5000) }}" min="0">
                    <small class="text-muted">Floor applied regardless of distance. Example: 5000 = $50.00</small>
                </div>

                <h4 class="mt-4">Ferry Settings</h4>
                <hr>
                
                <div class="form-group">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="ferry_enabled" name="ferry_enabled" value="1" {{ setting('vms_open_ops_ferry_enabled', true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="ferry_enabled">Enable Ferry Operations</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Ferry Cost per Nautical Mile (cents)</label>
                    <input type="number" name="ferry_cost_per_nm" class="form-control" value="{{ setting('vms_open_ops_ferry_cost_per_nm', 500) }}" min="0">
                    <small class="text-muted">Example: 500 = $5.00 per NM</small>
                </div>

                <div class="form-group">
                    <label>Ferry Minimum Cost — Light aircraft (MTOW ≤ 7,000 kg) (cents)</label>
                    <input type="number" name="ferry_min_cost_light" class="form-control" value="{{ setting('vms_open_ops_ferry_min_cost_light', 20000) }}" min="0">
                    <small class="text-muted">Example: 20000 = $200.00</small>
                </div>

                <div class="form-group">
                    <label>Ferry Minimum Cost — Medium aircraft (MTOW 7,001–136,000 kg, e.g. A320) (cents)</label>
                    <input type="number" name="ferry_min_cost_medium" class="form-control" value="{{ setting('vms_open_ops_ferry_min_cost_medium', 50000) }}" min="0">
                    <small class="text-muted">Example: 50000 = $500.00</small>
                </div>

                <div class="form-group">
                    <label>Ferry Minimum Cost — Heavy aircraft (MTOW > 136,000 kg) (cents)</label>
                    <input type="number" name="ferry_min_cost_heavy" class="form-control" value="{{ setting('vms_open_ops_ferry_min_cost_heavy', 100000) }}" min="0">
                    <small class="text-muted">Example: 100000 = $1,000.00</small>
                </div>

                <div class="form-group">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="ferry_require_certification" name="ferry_require_certification" value="1" {{ setting('vms_open_ops_ferry_require_certification', true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="ferry_require_certification">Require Aircraft Certification</label>
                    </div>
                    <small class="text-muted">Pilots must be certified on the subfleet to request a ferry</small>
                </div>
                
                <h4 class="mt-4">General Settings</h4>
                <hr>
                
                <div class="form-group">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="require_reason" name="require_reason" value="1" {{ setting('vms_open_ops_require_reason', true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="require_reason">Require Reason for Operations</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Maximum Reason Length (characters)</label>
                    <input type="number" name="max_reason_length" class="form-control" value="{{ setting('vms_open_ops_max_reason_length', 500) }}" min="10" max="1000">
                </div>
                
                <div class="form-group">
                    <label>Discord Staff Webhook URL</label>
                    <input type="url" name="discord_staff_webhook" class="form-control" value="{{ \App\Models\Setting::where('key', 'vms_open_ops.discord_staff_webhook')->first()->value ?? '' }}">
                    <small class="text-muted">URL del webhook de Discord para notificaciones de staff (solicitudes de jumpseat/ferry). Si se deja vacío, se usará el webhook general de Discord.</small>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                <a href="{{ route('admin.vmsopenops.index') }}" class="btn btn-secondary">Back to Requests</a>
            </form>
        </div>
    </div>
</div>
@endsection
