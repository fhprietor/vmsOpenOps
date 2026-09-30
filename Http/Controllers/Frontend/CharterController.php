<?php

namespace Modules\VmsOpenOps\Http\Controllers\Frontend;

use App\Contracts\Controller;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Bid;
use App\Models\Enums\AircraftState;
use App\Models\Enums\AircraftStatus;
use App\Models\Enums\FlightType;
use App\Models\Flight;
use App\Models\Subfleet;
use App\Services\FinanceService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laracasts\Flash\Flash;

class CharterController extends Controller
{
    protected $financeService;
    
    public function __construct(FinanceService $financeService)
    {
        $this->financeService = $financeService;
        $this->middleware('auth');
    }
    
    /**
     * Show charter flight creation form
     */
    public function create()
    {
        $user = Auth::user();
        $userAirport = Airport::find($user->curr_airport_id);
        
        if (!$userAirport) {
            Flash::error('Your current airport location is not set.');
            return redirect()->route('frontend.dashboard.index');
        }
        
        // Get available aircraft at current airport
        $aircraft = Aircraft::where('airport_id', $userAirport->id)
            ->where('state', AircraftState::PARKED)
            ->where('status', AircraftStatus::ACTIVE)
            ->whereHas('subfleet.ranks', function ($query) use ($user) {
                $query->where('rank_id', $user->rank_id);
            })
            ->with('subfleet')
            ->get();
        
        if ($aircraft->isEmpty()) {
            Flash::warning('No aircraft available at your current airport for charter flights.');
            return redirect()->route('frontend.dashboard.index');
        }
        
        // Flight types with their route codes
        $flightTypes = [
            'CH' => ['name' => 'Charter Passenger', 'type' => FlightType::CHARTER_PAX_ONLY],
            'CA' => ['name' => 'Charter Cargo', 'type' => FlightType::CHARTER_CARGO_MAIL],
            //'VP' => ['name' => 'VIP / Special', 'type' => FlightType::VIP],
            'PS' => ['name' => 'Positioning', 'type' => FlightType::POSITIONING],
            'FR' => ['name' => 'Ferry', 'type' => FlightType::CHARTER_SPECIAL],
            //'TR' => ['name' => 'Training', 'type' => FlightType::TRAINING],
            //'AM' => ['name' => 'Ambulance', 'type' => FlightType::AMBULANCE],
            //'GA' => ['name' => 'General Aviation', 'type' => FlightType::GENERAL_AVIATION],
            //'AX' => ['name' => 'Air Taxi', 'type' => FlightType::AIR_TAXI],
            //'MS' => ['name' => 'Mail Service', 'type' => FlightType::MAIL_SERVICE],
            //'MI' => ['name' => 'Military', 'type' => FlightType::MILITARY],
            //'OT' => ['name' => 'Other', 'type' => FlightType::OTHER],
        ];
        
        return view('vmsopenops::frontend.charter.create', compact(
            'user',
            'userAirport',
            'aircraft',
            'flightTypes'
        ));
    }
    
    /**
     * Preview flight details (AJAX)
     */
    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'to_airport_id' => 'required|exists:airports,id',
            'aircraft_id' => 'required|exists:aircraft,id',
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
        $aircraft = Aircraft::with('subfleet')->find($request->aircraft_id);
        
        if (!$fromAirport || !$toAirport || !$aircraft) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data provided'
            ], 400);
        }
        
        $distance = $this->calculateDistance(
            $fromAirport->lat, $fromAirport->lon,
            $toAirport->lat, $toAirport->lon
        );
        
        // Calcular flight time en minutos (asumiendo 450 knots = 8.33 NM por minuto)
        $flightTimeMinutes = round($distance / 8.33);
        if ($flightTimeMinutes < 1) {
            $flightTimeMinutes = 1;
        }
        $hours = floor($flightTimeMinutes / 60);
        $minutes = $flightTimeMinutes % 60;
        $flightTimeFormatted = $hours . 'h ' . $minutes . 'm';
        
        return response()->json([
            'success' => true,
            'data' => [
                'distance' => [
                    'value' => $distance,
                    'formatted' => number_format($distance, 2)
                ],
                'flight_time' => [
                    'value' => $flightTimeMinutes,
                    'formatted' => $flightTimeFormatted
                ],
            ]
        ]);
    }
        
    /**
     * Create charter flight
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'to_airport_id' => 'required|exists:airports,id',
            'aircraft_id' => 'required|exists:aircraft,id',
            'route_code' => 'required|string|size:2',
            'flight_type' => 'required|string',
            'level' => 'nullable|integer|min:1000|max:50000',
            'scheduled_departure' => 'required|date',
        ]);
        
        if ($validator->fails()) {
            Flash::error('Please correct the errors below.');
            return redirect()->back()->withErrors($validator)->withInput();
        }
        
        $user = Auth::user();
        $fromAirport = Airport::find($user->curr_airport_id);
        $toAirport = Airport::find($request->to_airport_id);
        $aircraft = Aircraft::with('subfleet')->find($request->aircraft_id);
        
        if (!$fromAirport || !$toAirport || !$aircraft) {
            Flash::error('Invalid data provided.');
            return redirect()->route('vmsopenops.charter.create');
        }
        
        // Verify aircraft is still available
        $existingBid = Bid::where('aircraft_id', $aircraft->id)->first();
        if ($existingBid) {
            Flash::error('This aircraft is no longer available. Please select another aircraft.');
            return redirect()->route('vmsopenops.charter.create');
        }
        
        // Calculate distance and flight time
        $distance = $this->calculateDistance(
            $fromAirport->lat, $fromAirport->lon,
            $toAirport->lat, $toAirport->lon
        );
        
        // Calcular flight time en minutos (asumiendo 450 knots = 8.33 NM por minuto)
        // flight_time en la base de datos está en minutos
        $flightTimeMinutes = round($distance / 8.33);
        if ($flightTimeMinutes < 1) {
            $flightTimeMinutes = 1; // Mínimo 1 minuto
        }
        
        $level = $request->level ?? mt_rand(28000, 41000);
        
        // Generate callsign: Airline ICAO + Route Code + Pilot ID
        $airline = $user->airline;
        $callsign = $user->id . $request->route_code;
        
        // Procesar fecha y hora de salida
        try {
            $scheduledDeparture = Carbon::parse($request->scheduled_departure);
        } catch (\Exception $e) {
            $scheduledDeparture = Carbon::now('UTC')->addMinutes(30);
        }
        
        $dptTime = $scheduledDeparture->format('H:i:s');
        $dptDate = $scheduledDeparture->format('Y-m-d');
        
        DB::beginTransaction();
        try {
            // Create the flight
            $flight = Flight::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'airline_id' => $user->airline_id,
                'flight_number' => $user->id,
                'callsign' => $callsign,
                'route_code' => $request->route_code,
                'route_leg' => null,
                'dpt_airport_id' => $fromAirport->id,
                'arr_airport_id' => $toAirport->id,
                'alt_airport_id' => null,
                'dpt_time' => $dptTime,
                'arr_time' => null,
                'distance' => $distance,
                'flight_time' => $flightTimeMinutes,
                'level' => $level,
                'flight_type' => $request->flight_type,
                'active' => false,
                'visible' => false,
                'days' => 127,
                'start_date' => $dptDate,
                'end_date' => null,
                'notes' => "Charter flight created by {$user->ident} - {$user->name_private}. Route code: {$request->route_code}",
            ]);
            
            // Attach subfleet to flight
            $flight->subfleets()->attach($aircraft->subfleet_id);
            
            // Create bid
            $bid = Bid::create([
                'flight_id' => $flight->id,
                'user_id' => $user->id,
                'aircraft_id' => $aircraft->id,
            ]);
            
            DB::commit();
            
            Flash::success("Charter flight {$callsign} created successfully! Scheduled departure: {$scheduledDeparture->format('Y-m-d H:i:s')} UTC. Flight time: {$flightTimeMinutes} minutes.");
            return redirect()->route('frontend.flights.bids');
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Charter flight creation failed: ' . $e->getMessage());
            Flash::error('Failed to create charter flight: ' . $e->getMessage());
            return redirect()->route('vmsopenops.charter.create');
        }
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
}