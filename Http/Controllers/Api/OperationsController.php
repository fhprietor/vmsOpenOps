<?php

namespace Modules\VmsOpenOps\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Airport;
use App\Models\Aircraft;
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
use Modules\VmsOpenOps\Models\OperationRequest;
use Modules\VmsOpenOps\Notifications\OperationRequested;

class OperationsController extends Controller
{
    protected $financeService;
    
    public function __construct(FinanceService $financeService)
    {
        $this->financeService = $financeService;
        // Eliminar o comentar la línea del middleware
        // $this->middleware('auth');  // Ya no es necesario, se maneja en las rutas
    }
    
    /**
     * Get user's operations requests
     */
    public function index(Request $request)
    {
        $type = $request->get('type', 'all');
        
        $query = OperationRequest::forUser(Auth::id())
            ->with(['fromAirport', 'toAirport', 'aircraft', 'subfleet']);
        
        if ($type !== 'all') {
            $query->where('operation_type', $type);
        }
        
        $requests = $query->orderByDesc('created_at')->get();
        
        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }
    
    /**
     * Check if user has pending requests
     */
    public function checkPending(Request $request)
    {
        $userId = Auth::id();
        $type = $request->get('type', null);
        
        $hasPending = OperationRequest::hasPendingRequest($userId, $type);
        $pendingCount = OperationRequest::getPendingCount($userId, $type);
        
        return response()->json([
            'success' => true,
            'data' => [
                'has_pending' => $hasPending,
                'pending_count' => $pendingCount,
                'pending_requests' => OperationRequest::pendingForUser($userId, $type)
                    ->with(['fromAirport', 'toAirport', 'aircraft'])
                    ->get()
            ]
        ]);
    }
    
    /**
     * Get user's current balance
     */
    public function getUserBalance()
    {
        $user = Auth::user();
        $balance = $user->journal->balance ?? new Money(0);
        
        return response()->json([
            'success' => true,
            'balance' => $balance->getValue(),
            'balance_formatted' => $balance->money->formatForHumans(),
        ]);
    }
    
    /**
     * Preview jumpseat cost
     */
    public function previewJumpseat(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'to_airport_id' => 'required|exists:airports,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        $fromAirport = Airport::find($user->curr_airport_id);
        $toAirport = Airport::find($request->to_airport_id);
        
        if (!$fromAirport) {
            return response()->json([
                'success' => false,
                'message' => 'User current airport not found'
            ], 400);
        }
        
        $isSameAirport = $fromAirport->id === $toAirport->id;
        $distance = $isSameAirport ? 0 : $this->calculateDistance(
            $fromAirport->lat,
            $fromAirport->lon,
            $toAirport->lat,
            $toAirport->lon
        );
        
        $costPerNm = setting('vms_open_ops_jumpseat_cost_per_nm', 250);
        $cost = new Money($distance * $costPerNm);
        $userBalance = $user->journal->balance ?? new Money(0);
        
        return response()->json([
            'success' => true,
            'data' => [
                'from_airport' => [
                    'id' => $fromAirport->id,
                    'name' => $fromAirport->name,
                ],
                'to_airport' => [
                    'id' => $toAirport->id,
                    'name' => $toAirport->name,
                ],
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
                'is_same_airport' => $isSameAirport,
            ]
        ]);
    }


    public function previewFerry(Request $request)
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
        if ($costPerNm < 100) {
            $costPerNm = $costPerNm * 100;
        }
        $cost = new Money($distance * $costPerNm);
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
    /**
     * Create a jumpseat request via API
     */
    public function storeJumpseat(Request $request)
    {
        if (!setting('vms_open_ops_jumpseat_enabled', true)) {
            return response()->json(['success' => false, 'message' => 'Jumpseat operations are disabled'], 403);
        }
        
        $requireReason = setting('vms_open_ops_require_reason', true);
        $maxReasonLength = setting('vms_open_ops_max_reason_length', 500);
        
        $rules = [
            'to_airport_id' => 'required|exists:airports,id',
            'type' => 'required|in:0,1',
        ];
        
        if ($requireReason) {
            $rules['reason'] = 'required|string|max:' . $maxReasonLength;
        } else {
            $rules['reason'] = 'nullable|string|max:' . $maxReasonLength;
        }
        
        $validator = Validator::make($request->all(), $rules);
        
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        
        $user = Auth::user();
        $fromAirport = Airport::find($user->curr_airport_id);
        $toAirport = Airport::find($request->to_airport_id);
        
        if (!$fromAirport) {
            return response()->json(['success' => false, 'message' => 'Current airport not found'], 400);
        }
        
        if ($fromAirport->id === $toAirport->id) {
            return response()->json(['success' => false, 'message' => 'You are already at this airport'], 400);
        }
        
        $distance = $this->calculateDistance(
            $fromAirport->lat,
            $fromAirport->lon,
            $toAirport->lat,
            $toAirport->lon
        );
        
        $costPerNm = setting('vms_open_ops_jumpseat_cost_per_nm', 250);
        $cost = new Money($distance * $costPerNm);
        
        $userBalance = $user->journal->balance ?? new Money(0);
        $isImmediate = $request->type == 1;
        $hasFunds = $userBalance->getValue() >= $cost->getValue();
        
        try {
            $operation = DB::transaction(function () use ($user, $fromAirport, $toAirport, $distance, $cost, $request, $isImmediate, $hasFunds) {
                $existing = OperationRequest::where('user_id', $user->id)
                    ->where('operation_type', 'jumpseat')
                    ->where('status', 0)
                    ->lockForUpdate()
                    ->first();
                
                if ($existing) {
                    throw new \Exception('You already have a pending jumpseat request.');
                }
                
                $operation = OperationRequest::create([
                    'operation_type' => 'jumpseat',
                    'user_id' => $user->id,
                    'from_airport_id' => $fromAirport->id,
                    'to_airport_id' => $toAirport->id,
                    'distance' => $distance,
                    'cost' => $cost->getValue(),
                    'reason' => $request->reason,
                    'type' => $request->type,
                    'status' => 0,
                ]);
                
                if ($isImmediate && $hasFunds) {
                    $this->financeService->debitFromJournal(
                        $user->journal,
                        $cost,
                        $operation,
                        "Jumpseat: {$fromAirport->id} → {$toAirport->id} ({$distance} NM)",
                        null,
                        null
                    );
                    
                    $user->curr_airport_id = $toAirport->id;
                    $user->save();
                    
                    $operation->status = 1;
                    $operation->approved_by = $user->id;
                    $operation->approved_at = now();
                    $operation->save();
                }
                
                return $operation;
            });
            
            if ($isImmediate && $hasFunds) {
                return response()->json([
                    'success' => true,
                    'message' => 'Jumpseat completed successfully!',
                    'data' => [
                        'request' => $operation->load(['fromAirport', 'toAirport']),
                        'new_location' => $toAirport->id
                    ]
                ]);
            }
            
            Notification::send($operation, new OperationRequested($operation));
            
            return response()->json([
                'success' => true,
                'message' => $isImmediate ? 'Insufficient funds. Request submitted for approval.' : 'Jumpseat request submitted for approval.',
                'data' => ['request' => $operation->load(['fromAirport', 'toAirport'])]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 409);
        }
    }
    
    /**
     * Create a ferry request via API
     */
    public function storeFerry(Request $request)
    {
        if (!setting('vms_open_ops_ferry_enabled', true)) {
            return response()->json(['success' => false, 'message' => 'Ferry operations are disabled'], 403);
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
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        
        $user = Auth::user();
        $aircraft = Aircraft::with(['airport', 'subfleet.ranks'])->find($request->aircraft_id);
        
        if (!$aircraft) {
            return response()->json(['success' => false, 'message' => 'Aircraft not found'], 404);
        }
        
        if ($aircraft->status != AircraftState::PARKED) {
            return response()->json(['success' => false, 'message' => 'Aircraft not available for ferry'], 400);
        }
        
        $userAirport = Airport::find($user->curr_airport_id);
        
        if ($aircraft->airport_id === $userAirport->id) {
            return response()->json(['success' => false, 'message' => 'Aircraft already at your airport'], 400);
        }
        
        $requireCertification = setting('vms_open_ops_ferry_require_certification', true);
        if ($requireCertification) {
            $isCertified = $aircraft->subfleet->ranks->contains('id', $user->rank_id);
            if (!$isCertified) {
                return response()->json(['success' => false, 'message' => 'You are not certified for this aircraft'], 403);
            }
        }
        
        $distance = $this->calculateDistance(
            $userAirport->lat,
            $userAirport->lon,
            $aircraft->airport->lat,
            $aircraft->airport->lon
        );
        
        $costPerNm = setting('vms_open_ops_ferry_cost_per_nm', 500);
        $cost = new Money($distance * $costPerNm * 100);
        
        $userBalance = $user->journal->balance ?? new Money(0);
        $isImmediate = $request->type == 1;
        $hasFunds = $userBalance->getValue() >= $cost->getValue();
        
        try {
            $operation = DB::transaction(function () use ($user, $aircraft, $userAirport, $distance, $cost, $request, $isImmediate, $hasFunds) {
                $existing = OperationRequest::where('user_id', $user->id)
                    ->where('operation_type', 'ferry')
                    ->where('status', 0)
                    ->lockForUpdate()
                    ->first();
                
                if ($existing) {
                    throw new \Exception('You already have a pending ferry request.');
                }
                
                $freshAircraft = Aircraft::with('airport')->find($aircraft->id);
                if ($freshAircraft->status != AircraftState::PARKED) {
                    throw new \Exception('Aircraft no longer available');
                }
                
                $operation = OperationRequest::create([
                    'operation_type' => 'ferry',
                    'user_id' => $user->id,
                    'from_airport_id' => $freshAircraft->airport_id,
                    'to_airport_id' => $userAirport->id,
                    'aircraft_id' => $freshAircraft->id,
                    'subfleet_id' => $freshAircraft->subfleet_id,
                    'aircraft_distance' => $distance,
                    'distance' => $distance,
                    'cost' => $cost->getValue(),
                    'reason' => $request->reason,
                    'type' => $request->type,
                    'status' => 0,
                ]);
                
                if ($isImmediate && $hasFunds) {
                    $this->financeService->debitFromJournal(
                        $user->journal,
                        $cost,
                        $operation,
                        "Ferry: {$freshAircraft->registration} ({$freshAircraft->airport_id} → {$userAirport->id})",
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
                return response()->json([
                    'success' => true,
                    'message' => 'Ferry completed successfully!',
                    'data' => ['request' => $operation->load(['aircraft', 'fromAirport', 'toAirport'])]
                ]);
            }
            
            Notification::send($operation, new OperationRequested($operation));
            
            return response()->json([
                'success' => true,
                'message' => $isImmediate ? 'Insufficient funds. Request submitted for approval.' : 'Ferry request submitted for approval.',
                'data' => ['request' => $operation->load(['aircraft', 'fromAirport', 'toAirport'])]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 409);
        }
    }
    
    /**
     * Cancel a pending request
     */
    public function cancel($id)
    {
        $operation = OperationRequest::where('id', $id)
            ->where('user_id', Auth::id())
            ->where('status', 0)
            ->first();
            
        if (!$operation) {
            return response()->json([
                'success' => false,
                'message' => 'Request not found or cannot be cancelled'
            ], 404);
        }
        
        $operation->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Request cancelled successfully'
        ]);
    }
    
    /**
     * Calculate distance between two points (nautical miles)
     */
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
    
    /**
     * Get available aircraft for ferry
     */
    public function getAvailableAircraft(Request $request)
    {
        $request->validate([
            'subfleet_id' => 'required|exists:subfleets,id',
        ]);
        
        $user = Auth::user();
        $userAirport = Airport::find($user->curr_airport_id);
        
        if (!$userAirport) {
            return response()->json([
                'success' => false,
                'message' => 'User airport not found'
            ], 400);
        }
        
        $subfleet = Subfleet::with(['ranks'])->find($request->subfleet_id);
        
        $requireCertification = setting('vms_open_ops_ferry_require_certification', true);
        if ($requireCertification) {
            $isCertified = $subfleet->ranks->contains('id', $user->rank_id);
            if (!$isCertified) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not certified to operate this aircraft type'
                ], 403);
            }
        }
        
        // Obtener aeronaves en tierra, activas, que no estén en el aeropuerto actual
        $aircraft = Aircraft::where('subfleet_id', $subfleet->id)
            ->where('state', AircraftState::PARKED)
            ->where('status', AircraftStatus::ACTIVE)
            ->where('airport_id', '!=', $userAirport->id)
            ->with('airport')
            ->get();
        
        if ($aircraft->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No aircraft available for this type'
            ], 404);
        }
        
        $costPerNm = setting('vms_open_ops_ferry_cost_per_nm', 500);
        if ($costPerNm < 100) {
            $costPerNm = $costPerNm * 100;
        }
        
        $aircraftData = [];
        
        foreach ($aircraft as $ac) {
            $distance = $this->calculateDistance(
                $userAirport->lat,
                $userAirport->lon,
                $ac->airport->lat,
                $ac->airport->lon
            );
            
            $cost = new Money($distance * $costPerNm);
            
            $aircraftData[] = [
                'id' => $ac->id,
                'registration' => $ac->registration,
                'name' => $ac->name,
                'current_airport' => $ac->airport_id,
                'distance' => $distance,
                'distance_formatted' => number_format($distance, 2) . ' NM',
                'cost' => $cost->getValue(),
                'cost_formatted' => $cost->money->formatForHumans(),
            ];
        }
        
        usort($aircraftData, function ($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });
        
        return response()->json([
            'success' => true,
            'aircraft' => $aircraftData
        ]);
    }
}
