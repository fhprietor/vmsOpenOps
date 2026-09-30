<?php

namespace Modules\VmsOpenOps\Http\Controllers\Admin;

use App\Contracts\Controller;
use App\Models\Enums\AircraftState;
use App\Models\Enums\AircraftStatus;
use App\Services\FinanceService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laracasts\Flash\Flash;
use Modules\VmsOpenOps\Models\OperationRequest;
use Modules\VmsOpenOps\Notifications\OperationApproved;

class OperationsController extends Controller
{
    protected $financeService;
    
    public function __construct(FinanceService $financeService)
    {
        $this->financeService = $financeService;
        $this->middleware('ability:admin,admin-access');
    }
    
    public function index(Request $request)
    {
        $type = $request->get('type', 'all');
        $status = $request->get('status', 'all');
        
        $query = OperationRequest::with(['user', 'aircraft', 'fromAirport', 'toAirport', 'subfleet']);
        
        if ($type !== 'all') {
            $query->where('operation_type', $type);
        }
        
        if ($status !== 'all') {
            $query->where('status', (int) $status);
        }
        
        $requests = $query->orderByDesc('created_at')->paginate(30);
        
        return view('vmsopenops::admin.index', compact('requests', 'type', 'status'));
    }
    
public function approve($id, Request $request)
{
    $operation = OperationRequest::findOrFail($id);
    
    if ($operation->status != 0) {
        Flash::error('This request has already been processed.');
        return redirect()->route('admin.vmsopenops.index');
    }
    
    $user = $operation->user;
    $cost = new Money($operation->cost);
    
    DB::beginTransaction();
    try {
        $description = $operation->operation_type == 'jumpseat'
            ? "Jumpseat: {$operation->from_airport_id} → {$operation->to_airport_id} ({$operation->distance} NM)"
            : "Ferry: {$operation->aircraft->registration} ({$operation->from_airport_id} → {$operation->to_airport_id}) - {$operation->distance} NM";
        
        // En las aprobaciones de admin, NUNCA se debita dinero del piloto
        // El costo lo asume la aerolínea
        \Log::info('Admin approving operation - no charge to pilot', [
            'operation_id' => $operation->id,
            'operation_type' => $operation->operation_type,
            'user_id' => $user->id,
            'cost' => $cost->getValue()
        ]);
        
        if ($operation->operation_type == 'jumpseat') {
            // Mover al piloto
            $user->curr_airport_id = $operation->to_airport_id;
            $user->save();
        } else {
            // FERRY: Verificar que la aeronave está disponible
            $aircraft = $operation->aircraft;
            
            if (!$aircraft) {
                throw new \Exception('Aircraft not found.');
            }
            
            \Log::info('Admin approving ferry:', [
                'aircraft_id' => $aircraft->id,
                'registration' => $aircraft->registration,
                'state' => $aircraft->state,
                'status' => $aircraft->status,
                'current_airport' => $aircraft->airport_id,
                'target_airport' => $operation->to_airport_id,
            ]);
            
            // Verificar que la aeronave no esté en vuelo
            if ($aircraft->state == AircraftState::IN_AIR) {
                throw new \Exception('Aircraft is currently in flight and cannot be ferried.');
            }
            
            // Verificar que no esté ya en el aeropuerto destino
            if ($aircraft->airport_id == $operation->to_airport_id) {
                throw new \Exception('Aircraft is already at the destination airport.');
            }
            
            // Mover la aeronave
            $aircraft->airport_id = $operation->to_airport_id;
            $aircraft->save();
        }
        
        $operation->status = 1;
        $operation->approved_by = Auth::id();
        $operation->approved_at = now();
        $operation->save();
        
        DB::commit();
        
        Notification::send($operation, new OperationApproved($operation));
        
        Flash::success('Operation approved successfully.');
        return redirect()->route('admin.vmsopenops.index');
        
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('Operation approval failed: ' . $e->getMessage());
        Flash::error('Failed to approve operation: ' . $e->getMessage());
        return redirect()->route('admin.vmsopenops.index');
    }
}

    public function reject($id, Request $request)
    {
        $request->validate([
            'admin_notes' => 'nullable|string|max:500',
        ]);
        
        $operation = OperationRequest::findOrFail($id);
        
        if ($operation->status != 0) {
            Flash::error('This request has already been processed.');
            return redirect()->route('admin.vmsopenops.index');
        }
        
        $operation->status = 2;
        $operation->approved_by = Auth::id();
        $operation->approved_at = now();
        $operation->admin_notes = $request->admin_notes;
        $operation->save();
        
        Notification::send($operation, new OperationApproved($operation));
        
        Flash::warning('Operation rejected.');
        return redirect()->route('admin.vmsopenops.index');
    }
    
    public function settings()
    {
        return view('vmsopenops::admin.settings');
    }
    
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'jumpseat_enabled' => 'required|boolean',
            'jumpseat_cost_per_nm' => 'required|integer|min:0',
            'jumpseat_min_cost' => 'required|integer|min:0',
            'ferry_enabled' => 'required|boolean',
            'ferry_cost_per_nm' => 'required|integer|min:0',
            'ferry_min_cost_light' => 'required|integer|min:0',
            'ferry_min_cost_medium' => 'required|integer|min:0',
            'ferry_min_cost_heavy' => 'required|integer|min:0',
            'ferry_require_certification' => 'required|boolean',
            'require_reason' => 'required|boolean',
            'max_reason_length' => 'required|integer|min:10|max:1000',
            'discord_staff_webhook' => 'nullable|url',
        ]);
        
        // Debug - registrar lo que recibimos
        \Log::info('Saving settings', $validated);
        
        try {
            // Jumpseat settings
            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.jumpseat.enabled'],
                [
                    'value' => $validated['jumpseat_enabled'] ? 'true' : 'false',
                    'name' => 'Enable Jumpseat Operations',
                    'group' => 'VmsOpenOps',
                    'type' => 'bool',
                    'description' => 'Enable jumpseat operations for pilots'
                ]
            );
            
            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.jumpseat.cost_per_nm'],
                [
                    'value' => (string) $validated['jumpseat_cost_per_nm'],
                    'name' => 'Jumpseat Cost per NM',
                    'group' => 'VmsOpenOps',
                    'type' => 'int',
                    'description' => 'Cost per nautical mile for jumpseat (in cents)'
                ]
            );

            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.jumpseat.min_cost'],
                [
                    'value' => (string) $validated['jumpseat_min_cost'],
                    'name' => 'Jumpseat Minimum Cost',
                    'group' => 'VmsOpenOps',
                    'type' => 'int',
                    'description' => 'Minimum cost for a jumpseat request (in cents)'
                ]
            );

            // Ferry settings
            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.ferry.enabled'],
                [
                    'value' => $validated['ferry_enabled'] ? 'true' : 'false',
                    'name' => 'Enable Ferry Operations',
                    'group' => 'VmsOpenOps',
                    'type' => 'bool',
                    'description' => 'Enable ferry operations for aircraft'
                ]
            );
            
            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.ferry.cost_per_nm'],
                [
                    'value' => (string) $validated['ferry_cost_per_nm'],
                    'name' => 'Ferry Cost per NM',
                    'group' => 'VmsOpenOps',
                    'type' => 'int',
                    'description' => 'Cost per nautical mile for ferry (in cents)'
                ]
            );

            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.ferry.min_cost_light'],
                [
                    'value' => (string) $validated['ferry_min_cost_light'],
                    'name' => 'Ferry Minimum Cost (Light)',
                    'group' => 'VmsOpenOps',
                    'type' => 'int',
                    'description' => 'Minimum ferry cost for light aircraft MTOW ≤ 7,000 kg (in cents)'
                ]
            );

            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.ferry.min_cost_medium'],
                [
                    'value' => (string) $validated['ferry_min_cost_medium'],
                    'name' => 'Ferry Minimum Cost (Medium)',
                    'group' => 'VmsOpenOps',
                    'type' => 'int',
                    'description' => 'Minimum ferry cost for medium aircraft MTOW 7,001–136,000 kg (in cents)'
                ]
            );

            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.ferry.min_cost_heavy'],
                [
                    'value' => (string) $validated['ferry_min_cost_heavy'],
                    'name' => 'Ferry Minimum Cost (Heavy)',
                    'group' => 'VmsOpenOps',
                    'type' => 'int',
                    'description' => 'Minimum ferry cost for heavy aircraft MTOW > 136,000 kg (in cents)'
                ]
            );

            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.ferry.require_certification'],
                [
                    'value' => $validated['ferry_require_certification'] ? 'true' : 'false',
                    'name' => 'Require Aircraft Certification',
                    'group' => 'VmsOpenOps',
                    'type' => 'bool',
                    'description' => 'Require pilot to be certified on subfleet for ferry'
                ]
            );
            
            // Common settings
            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.require_reason'],
                [
                    'value' => $validated['require_reason'] ? 'true' : 'false',
                    'name' => 'Require Reason',
                    'group' => 'VmsOpenOps',
                    'type' => 'bool',
                    'description' => 'Require reason for all operations'
                ]
            );
            
            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.max_reason_length'],
                [
                    'value' => (string) $validated['max_reason_length'],
                    'name' => 'Max Reason Length',
                    'group' => 'VmsOpenOps',
                    'type' => 'int',
                    'description' => 'Maximum characters for operation reason'
                ]
            );
            
            // Discord Staff Webhook setting (NUEVO)
            \App\Models\Setting::updateOrCreate(
                ['key' => 'vms_open_ops.discord_staff_webhook'],
                [
                    'value' => $validated['discord_staff_webhook'] ?? '',
                    'name' => 'Discord Staff Webhook URL',
                    'group' => 'VmsOpenOps',
                    'type' => 'string',
                    'description' => 'Discord webhook URL for staff notifications (jumpseat/ferry requests)'
                ]
            );
            
            \Log::info('Settings saved successfully');
            Flash::success('Settings updated successfully.');
            
        } catch (\Exception $e) {
            \Log::error('Error saving settings: ' . $e->getMessage());
            Flash::error('Error saving settings: ' . $e->getMessage());
        }
        
        return redirect()->route('admin.vmsopenops.settings');
    }
}