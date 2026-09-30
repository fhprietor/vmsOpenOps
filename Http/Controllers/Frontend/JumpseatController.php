<?php

namespace Modules\VmsOpenOps\Http\Controllers\Frontend;

use App\Contracts\Controller;
use App\Models\Airport;
use App\Models\User;
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

class JumpseatController extends Controller
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
        $pendingRequest = OperationRequest::getPendingRequest($userId, 'jumpseat');
        
        $requests = OperationRequest::forUser($userId)
            ->jumpseat()
            ->with(['fromAirport', 'toAirport'])
            ->orderByDesc('created_at')
            ->paginate(20);
            
        return view('vmsopenops::frontend.jumpseat.index', compact('requests', 'pendingRequest'));
    }
    
    public function create()
    {
        if (!setting('vms_open_ops_jumpseat_enabled', true)) {
            Flash::error('Jumpseat operations are currently disabled.');
            return redirect()->route('frontend.dashboard.index');
        }
        
        $userId = Auth::id();
        $pendingRequest = OperationRequest::getPendingRequest($userId, 'jumpseat');
        
        if ($pendingRequest) {
            Flash::warning('You already have a pending jumpseat request. Please wait for it to be processed or cancel it before creating a new one.');
            return redirect()->route('vmsopenops.jumpseat.index');
        }
        
        $user = Auth::user();
        
        // Obtener el balance del piloto
        $balance = $user->journal->balance ?? new Money(0);
        
        $costPerNm = setting('vms_open_ops_jumpseat_cost_per_nm', 250);
        $requireReason = setting('vms_open_ops_require_reason', true);
        $maxReasonLength = setting('vms_open_ops_max_reason_length', 500);
        
        return view('vmsopenops::frontend.jumpseat.create', compact(
            'user',
            'balance',
            'costPerNm',
            'requireReason',
            'maxReasonLength'
        ));
    }
    
    public function store(Request $request)
    {
        if (!setting('vms_open_ops_jumpseat_enabled', true)) {
            Flash::error('Jumpseat operations are currently disabled.');
            return redirect()->route('frontend.dashboard.index');
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
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }
            Flash::error('Please correct the errors below.');
            return redirect()->back()->withErrors($validator)->withInput();
        }
        
        $user = Auth::user();
        $fromAirport = Airport::find($user->curr_airport_id);
        $toAirport = Airport::find($request->to_airport_id);
        
        if (!$fromAirport) {
            $errorMsg = 'Current airport not found.';
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $errorMsg], 400);
            }
            Flash::error($errorMsg);
            return redirect()->route('vmsopenops.jumpseat.create');
        }
        
        if ($fromAirport->id === $toAirport->id) {
            $errorMsg = 'You are already at this airport.';
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $errorMsg], 400);
            }
            Flash::error($errorMsg);
            return redirect()->route('vmsopenops.jumpseat.create');
        }
        
        $distance = $this->calculateDistance(
            $fromAirport->lat,
            $fromAirport->lon,
            $toAirport->lat,
            $toAirport->lon
        );
        
        $costPerNm  = setting('vms_open_ops_jumpseat_cost_per_nm', 250);
        $minCents   = (int) setting('vms_open_ops_jumpseat_min_cost', 5000);
        $costCents  = max((int) round($distance * $costPerNm), $minCents);
        $cost       = new Money($costCents);

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
                    throw new \Exception('You already have a pending jumpseat request. Please cancel it first.');
                }
                
                $operation = OperationRequest::create([
                    'operation_type' => 'jumpseat',
                    'user_id' => $user->id,
                    'from_airport_id' => $fromAirport->id,
                    'to_airport_id' => $toAirport->id,
                    'distance' => $distance,
                    'cost' => (int) $cost->getAmount(),
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
                $successMsg = "Jumpseat completed! You are now at {$toAirport->id}.";
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => $successMsg,
                        'request' => $operation->load(['fromAirport', 'toAirport'])
                    ]);
                }
                Flash::success($successMsg);
                return redirect()->route('frontend.dashboard.index');
            }
            
            Notification::send($operation, new OperationRequested($operation));
            
            $message = $isImmediate 
                ? 'Insufficient funds. Your request has been submitted for approval.'
                : 'Your jumpseat request has been submitted for approval.';
            
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'request' => $operation->load(['fromAirport', 'toAirport'])
                ]);
            }
            
            Flash::info($message);
            return redirect()->route('vmsopenops.jumpseat.index');
            
        } catch (\Exception $e) {
            \Log::error('Jumpseat request failed: ' . $e->getMessage());
            
            $errorMsg = $e->getMessage();
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $errorMsg], 409);
            }
            Flash::error($errorMsg);
            return redirect()->route('vmsopenops.jumpseat.index');
        }
    }
    
    public function cancel($id)
    {
        $operation = OperationRequest::where('id', $id)
            ->where('user_id', Auth::id())
            ->where('operation_type', 'jumpseat')
            ->where('status', 0)
            ->first();
            
        if (!$operation) {
            Flash::error('Request not found or cannot be cancelled.');
            return redirect()->route('vmsopenops.jumpseat.index');
        }
        
        $operation->delete();
        Flash::success('Request cancelled successfully.');
        
        return redirect()->route('vmsopenops.jumpseat.index');
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

    /**
     * AJAX preview for jumpseat cost
     */
    public function preview(Request $request)
    {
        try {
            $request->validate([
                'to_airport_id' => 'required|exists:airports,id',
            ]);
            
            $user = Auth::user();
            $fromAirport = Airport::find($user->curr_airport_id);
            $toAirport = Airport::find($request->to_airport_id);
            
            if (!$fromAirport) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current airport not found'
                ], 400);
            }
            
            $distance = $this->calculateDistance(
                $fromAirport->lat, $fromAirport->lon,
                $toAirport->lat, $toAirport->lon
            );
            
            $costPerNm = setting('vms_open_ops_jumpseat_cost_per_nm', 250);
            $minCents  = (int) setting('vms_open_ops_jumpseat_min_cost', 5000);
            $costCents = max((int) round($distance * $costPerNm), $minCents);
            $cost      = new Money($costCents);
            $userBalance = $user->journal->balance ?? new Money(0);
            
            return response()->json([
                'success' => true,
                'data' => [
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
                    'is_same_airport' => $fromAirport->id === $toAirport->id,
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Preview error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}