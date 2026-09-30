<?php

namespace Modules\VmsOpenOps\Http\Controllers\Frontend;

use App\Contracts\Controller;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Enums\AircraftState;
use App\Models\Enums\AircraftStatus;
use App\Models\Subfleet;
use App\Services\FinanceService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Laracasts\Flash\Flash;
use Modules\VmsOpenOps\Models\OperationRequest;
use Modules\VmsOpenOps\Notifications\OperationRequested;

class FerryController extends Controller
{
    protected $financeService;
    
    public function __construct(FinanceService $financeService)
    {
        $this->financeService = $financeService;
        $this->middleware('auth');
    }
    
    public function index()
    {
        $userId = Auth::id();
        $pendingRequest = OperationRequest::getPendingRequest($userId, 'ferry');
        
        $requests = OperationRequest::forUser($userId)
            ->ferry()
            ->with(['aircraft', 'fromAirport', 'toAirport', 'subfleet'])
            ->orderByDesc('created_at')
            ->paginate(20);
            
        return view('vmsopenops::frontend.ferry.index', compact('requests', 'pendingRequest'));
    }
    
    public function create()
    {
        if (!setting('vms_open_ops_ferry_enabled', true)) {
            Flash::error('Ferry operations are currently disabled.');
            return redirect()->route('frontend.dashboard.index');
        }
        
        $userId = Auth::id();
        $pendingRequest = OperationRequest::getPendingRequest($userId, 'ferry');
        
        if ($pendingRequest) {
            Flash::warning('You already have a pending ferry request. Please wait for it to be processed or cancel it before creating a new one.');
            return redirect()->route('vmsopenops.ferry.index');
        }
        
        $user = Auth::user();
        $userAirport = Airport::find($user->curr_airport_id);
        
        if (!$userAirport) {
            Flash::error('Your current airport location is not set.');
            return redirect()->route('frontend.dashboard.index');
        }
        
        $requireCertification = setting('vms_open_ops_ferry_require_certification', true);
        
        // Obtener subfleets donde:
        // 1. Tengan aeronaves en tierra (PARKED) en otros aeropuertos
        // 2. El piloto esté certificado (si aplica)
        $subfleets = Subfleet::whereHas('aircraft', function ($query) use ($userAirport) {
            $query->where('state', AircraftState::PARKED)
                  ->where('status', AircraftStatus::ACTIVE)  // Corregido
                  ->where('airport_id', '!=', $userAirport->id);
        });
        
        if ($requireCertification) {
            $subfleets->whereHas('ranks', function ($query) use ($user) {
                $query->where('rank_id', $user->rank_id);
            });
        }
        
        $subfleets = $subfleets->orderBy('name')->get();
        
        if ($subfleets->isEmpty()) {
            Flash::warning('No aircraft types available for ferry. Either no aircraft are on ground at other airports, or you are not certified to operate them.');
            return redirect()->route('frontend.dashboard.index');
        }
        
        $balance = $user->journal->balance ?? new Money(0);
        $costPerNm = setting('vms_open_ops_ferry_cost_per_nm', 500);
        $requireReason = setting('vms_open_ops_require_reason', true);
        $maxReasonLength = setting('vms_open_ops_max_reason_length', 500);
        
        return view('vmsopenops::frontend.ferry.create', compact(
            'user',
            'userAirport',
            'subfleets',
            'balance',
            'costPerNm',
            'requireReason',
            'maxReasonLength'
        ));
    }
    
    public function preview(Request $request)
    {
        $request->validate([
            'aircraft_id' => 'required|exists:aircraft,id',
        ]);
        
        $user = Auth::user();
        $aircraft = Aircraft::with('airport')->find($request->aircraft_id);
        $userAirport = Airport::find($user->curr_airport_id);
        
        if (!$userAirport || !$aircraft) {
            return response()->json([
                'success' => false,
                'message' => 'Airport or aircraft not found'
            ], 400);
        }
        
        $distance = $this->calculateDistance(
            $userAirport->lat,
            $userAirport->lon,
            $aircraft->airport->lat,
            $aircraft->airport->lon
        );
        
        $costPerNm = setting('vms_open_ops_ferry_cost_per_nm', 500);
        $costCents = max((int) round($distance * $costPerNm), $this->getMinFerryCostCents($aircraft));
        $cost = new Money($costCents);
        $userBalance = $user->journal->balance ?? new Money(0);

        return response()->json([
            'success' => true,
            'data' => [
                'aircraft_id' => $aircraft->id,
                'registration' => $aircraft->registration,
                'current_airport' => $aircraft->airport_id,
                'distance' => [
                    'value' => $distance,
                    'formatted' => number_format($distance, 2) . ' NM'
                ],
                'cost' => [
                    'value' => $cost->getValue(),
                    'formatted' => $cost->money->formatForHumans(),
                ],
                'user_balance' => [
                    'value' => $userBalance->getValue(),
                    'formatted' => $userBalance->money->formatForHumans(),
                ],
                'can_pay_immediately' => $userBalance->getValue() >= $cost->getValue(),
                'is_same_airport' => $aircraft->airport_id === $userAirport->id,
            ]
        ]);
    }
    
    public function store(Request $request)
    {
        if (!setting('vms_open_ops_ferry_enabled', true)) {
            Flash::error('Ferry operations are currently disabled.');
            return redirect()->route('frontend.dashboard.index');
        }
        
        $requireReason = setting('vms_open_ops_require_reason', true);
        $maxReasonLength = setting('vms_open_ops_max_reason_length', 500);
        
        $rules = [
            'aircraft_id' => 'required|exists:aircraft,id',
            'type' => 'required|in:0,1',
        ];
        
        if ($requireReason) {
            $rules['reason'] = 'required|string|max:' . $maxReasonLength;
        } else {
            $rules['reason'] = 'nullable|string|max:' . $maxReasonLength;
        }
        
        $validator = Validator::make($request->all(), $rules);
        
        if ($validator->fails()) {
            Flash::error('Please correct the errors below.');
            return redirect()->back()->withErrors($validator)->withInput();
        }
        
        $user = Auth::user();
        $aircraft = Aircraft::with(['airport', 'subfleet.ranks'])->find($request->aircraft_id);
        
        if (!$aircraft) {
            Flash::error('Aircraft not found.');
            return redirect()->route('vmsopenops.ferry.create');
        }
        
        // LOG PARA DEPURAR - Mostrar el estado actual de la aeronave
        \Log::info('Aircraft status check:', [
            'aircraft_id' => $aircraft->id,
            'registration' => $aircraft->registration,
            'status' => $aircraft->status,
            'status_name' => $aircraft->status == 0 ? 'PARKED' : ($aircraft->status == 1 ? 'IN_USE' : 'IN_AIR'),
            'current_airport' => $aircraft->airport_id,
        ]);
        
        // Verificar que la aeronave está en tierra
        if ($aircraft->state != AircraftState::PARKED) {
            Flash::error('This aircraft is not on ground.');
            return redirect()->route('vmsopenops.ferry.create');
        }

        // Verificar que la aeronave está activa (no en mantenimiento)
        if ($aircraft->status != AircraftStatus::ACTIVE) {
            Flash::error('This aircraft is not available for ferry (status: ' . ($aircraft->status == 'M' ? 'maintenance' : $aircraft->status) . ').');
            return redirect()->route('vmsopenops.ferry.create');
        }
        
        $userAirport = Airport::find($user->curr_airport_id);
        
        if (!$userAirport) {
            Flash::error('Your current airport not found.');
            return redirect()->route('vmsopenops.ferry.create');
        }
        
        // Verificar que no está en el mismo aeropuerto
        if ($aircraft->airport_id === $userAirport->id) {
            Flash::error('This aircraft is already at your current airport.');
            return redirect()->route('vmsopenops.ferry.create');
        }
        
        // Verificar certificación del piloto
        $requireCertification = setting('vms_open_ops_ferry_require_certification', true);
        if ($requireCertification) {
            $isCertified = $aircraft->subfleet->ranks->contains('id', $user->rank_id);
            if (!$isCertified) {
                Flash::error('You are not certified to operate this aircraft type.');
                return redirect()->route('vmsopenops.ferry.create');
            }
        }
        
        // Calcular distancia y costo
        $distance = $this->calculateDistance(
            $userAirport->lat,
            $userAirport->lon,
            $aircraft->airport->lat,
            $aircraft->airport->lon
        );
        
        $costPerNm = setting('vms_open_ops_ferry_cost_per_nm', 500);
        $costCents = max((int) round($distance * $costPerNm), $this->getMinFerryCostCents($aircraft));
        $cost = new Money($costCents);

        $userBalance = $user->journal->balance ?? new Money(0);
        $isImmediate = $request->type == 1;
        $hasFunds = $userBalance->getValue() >= $cost->getValue();
        
        try {
            $operation = DB::transaction(function () use ($user, $aircraft, $userAirport, $distance, $cost, $request, $isImmediate, $hasFunds) {
                // Verificar solicitud pendiente
                $existing = OperationRequest::where('user_id', $user->id)
                    ->where('operation_type', 'ferry')
                    ->where('status', 0)
                    ->lockForUpdate()
                    ->first();
                
                if ($existing) {
                    throw new \Exception('You already have a pending ferry request. Please cancel it first.');
                }
                
                $freshAircraft = Aircraft::with('airport')->find($aircraft->id);

                \Log::info('Fresh aircraft status check:', [
                    'aircraft_id' => $freshAircraft->id,
                    'registration' => $freshAircraft->registration,
                    'status' => $freshAircraft->status,
                    'current_airport' => $freshAircraft->airport_id,
                ]);
                
                if ($freshAircraft->state != AircraftState::PARKED) {
                    throw new \Exception('This aircraft is no longer on ground.');
                }
                
                if ($freshAircraft->status != AircraftStatus::ACTIVE) {
                    throw new \Exception('This aircraft is no longer available (status: ' . $freshAircraft->status . ').');
                }
                
                if ($freshAircraft->airport_id != $aircraft->airport_id) {
                    throw new \Exception('Aircraft location has changed. Please refresh and try again.');
                }
                
                // Crear la solicitud
                $operation = OperationRequest::create([
                    'operation_type' => 'ferry',
                    'user_id' => $user->id,
                    'from_airport_id' => $freshAircraft->airport_id,
                    'to_airport_id' => $userAirport->id,
                    'aircraft_id' => $freshAircraft->id,
                    'subfleet_id' => $freshAircraft->subfleet_id,
                    'aircraft_distance' => $distance,
                    'distance' => $distance,
                    'cost' => (int) $cost->getAmount(),
                    'reason' => $request->reason,
                    'type' => $request->type,
                    'status' => 0,
                ]);
                
                // Pago inmediato si tiene fondos
                if ($isImmediate && $hasFunds) {
                    $this->financeService->debitFromJournal(
                        $user->journal,
                        $cost,
                        $operation,
                        "Ferry: {$freshAircraft->registration} ({$freshAircraft->airport_id} → {$userAirport->id}) - {$distance} NM",
                        null,
                        null
                    );
                    
                    $freshAircraft->airport_id = $userAirport->id;
                    $freshAircraft->save();
                    
                    $operation->status = 1;
                    $operation->approved_by = $user->id;
                    $operation->approved_at = now();
                    $operation->save();
                }
                
                return $operation;
            });
            
            if ($isImmediate && $hasFunds) {
                Flash::success("Aircraft {$aircraft->registration} has been ferried to {$userAirport->id}!");
                return redirect()->route('frontend.dashboard.index');
            }
            
            Notification::send($operation, new OperationRequested($operation));
            
            $message = $isImmediate 
                ? 'Insufficient funds. Your request has been submitted for approval.'
                : 'Your ferry request has been submitted for approval.';
            
            Flash::info($message);
            return redirect()->route('vmsopenops.ferry.index');
            
        } catch (\Exception $e) {
            \Log::error('Ferry request failed: ' . $e->getMessage());
            Flash::error($e->getMessage());
            return redirect()->route('vmsopenops.ferry.create');
        }
    }
    
    public function cancel($id)
    {
        $operation = OperationRequest::where('id', $id)
            ->where('user_id', Auth::id())
            ->where('operation_type', 'ferry')
            ->where('status', 0)
            ->first();
            
        if (!$operation) {
            Flash::error('Request not found or cannot be cancelled.');
            return redirect()->route('vmsopenops.ferry.index');
        }
        
        $operation->delete();
        Flash::success('Request cancelled successfully.');
        
        return redirect()->route('vmsopenops.ferry.index');
    }
    
    private function getMinFerryCostCents(Aircraft $aircraft): int
    {
        $mtowKg = null;
        if ($aircraft->mtow) {
            $mtowKg = $aircraft->mtow->toUnit('kg');
        }

        if (!$mtowKg) {
            return (int) setting('vms_open_ops_ferry_min_cost_medium', 50000);
        }

        if ($mtowKg <= 7000) {
            return (int) setting('vms_open_ops_ferry_min_cost_light', 20000);
        }

        if ($mtowKg <= 136000) {
            return (int) setting('vms_open_ops_ferry_min_cost_medium', 50000);
        }

        return (int) setting('vms_open_ops_ferry_min_cost_heavy', 100000);
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 3440.07;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        
        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return round($earthRadius * $c, 2);
    }
}