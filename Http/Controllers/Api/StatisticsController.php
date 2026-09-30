<?php

namespace Modules\VmsOpenOps\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Enums\PirepState;
use App\Models\Pirep;
use App\Models\User;
use App\Models\Aircraft;
use App\Models\Subfleet;
use App\Models\Airport;
use App\Models\PirepFare;
use App\Models\Enums\FareType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class StatisticsController extends Controller
{
    public function getData(Request $request)
    {
        $period = $request->get('period', 'month');
        $days = $request->get('days', 30);
        $year = $request->get('year', null);
        $month = $request->get('month', null);

        $startDate = null;
        $endDate = now();

        if ($year && $month) {
            $startDate = now()->setYear($year)->setMonth($month)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
        } elseif ($year && !$month) {
            // Año completo
            $startDate = now()->setYear($year)->startOfYear();
            $endDate = now()->setYear($year)->endOfYear();
        } elseif ($period === 'week') {
            $startDate = now()->subDays(7);
        } elseif ($period === 'quarter') {
            $startDate = now()->subMonths(3);
        } elseif ($period === 'semester') {
            $startDate = now()->subMonths(6);
        } elseif ($period === 'year') {
            $startDate = now()->subDays(365);
        } else {
            $days = max(1, (int)$days);
            $startDate = now()->subDays($days);
        }

        $cacheKey = 'vms_stats_' . md5(($startDate ? $startDate->toDateString() : 'all') . '_' . $endDate->toDateString() . '_' . $year . '_' . $month);
        $data = Cache::remember($cacheKey, 300, function () use ($startDate, $endDate, $year, $month) {
            return $this->computeStats($startDate, $endDate, $year, $month);
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function computeStats($startDate, $endDate, $year, $month)
    {
        // Base query para PIREPs aceptados
        $pirepsQuery = Pirep::where('pireps.state', PirepState::ACCEPTED);
        
        if ($startDate && $endDate) {
            $pirepsQuery->whereBetween('pireps.submitted_at', [$startDate, $endDate]);
        } elseif ($year && $month) {
            $pirepsQuery->whereYear('pireps.submitted_at', $year)->whereMonth('pireps.submitted_at', $month);
        } elseif ($year && !$month) {
            // Año completo
            $pirepsQuery->whereYear('pireps.submitted_at', $year);
        }

        // 1. Top 5 pilotos por landing score promedio
        $topLandingScore = (clone $pirepsQuery)
            ->select('user_id', DB::raw('AVG(landing_rate) as avg_landing_rate'))
            ->whereNotNull('landing_rate')
            ->where('landing_rate', '!=', 0)
            ->whereHas('user')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 0')
            ->orderBy('avg_landing_rate', 'desc')
            ->with('user')
            ->limit(5)
            ->get()
            ->filter(function ($item) { return $item->user !== null; })
            ->map(function ($item) {
                return [
                    'pilot' => $item->user->ident . ' - ' . $item->user->name_private,
                    'pilot_id' => $item->user->id,
                    'avg_landing_rate' => round($item->avg_landing_rate),
                ];
            });

        // 2. Top 5 pilotos por millas voladas
        $topMiles = (clone $pirepsQuery)
            ->select('user_id', DB::raw('SUM(distance) as total_distance'))
            ->whereHas('user')
            ->groupBy('user_id')
            ->orderBy('total_distance', 'desc')
            ->with('user')
            ->limit(5)
            ->get()
            ->filter(function ($item) { return $item->user !== null; })
            ->map(function ($item) {
                return [
                    'pilot' => $item->user->ident . ' - ' . $item->user->name_private,
                    'pilot_id' => $item->user->id,
                    'total_distance' => round($item->total_distance, 0),
                ];
            });

        // 3. Top 5 pilotos por número de vuelos
        $topFlightsCount = (clone $pirepsQuery)
            ->select('user_id', DB::raw('COUNT(*) as flight_count'))
            ->whereHas('user')
            ->groupBy('user_id')
            ->orderBy('flight_count', 'desc')
            ->with('user')
            ->limit(5)
            ->get()
            ->filter(function ($item) { return $item->user !== null; })
            ->map(function ($item) {
                return [
                    'pilot' => $item->user->ident . ' - ' . $item->user->name_private,
                    'pilot_id' => $item->user->id,
                    'flight_count' => $item->flight_count,
                ];
            });

        // 4. Top 5 pilotos por score promedio
        $topScore = (clone $pirepsQuery)
            ->select('user_id', DB::raw('AVG(score) as avg_score'), DB::raw('COUNT(*) as flight_count'))
            ->whereNotNull('score')
            ->where('score', '>', 0)
            ->whereHas('user')
            ->groupBy('user_id')
            ->orderBy('avg_score', 'desc')
            ->with('user')
            ->limit(5)
            ->get()
            ->filter(fn($item) => $item->user !== null)
            ->map(fn($item) => [
                'pilot'        => $item->user->ident . ' - ' . $item->user->name_private,
                'pilot_id'     => $item->user->id,
                'avg_score'    => round($item->avg_score, 1),
                'flight_count' => $item->flight_count,
            ]);

        // 5. Top 5 rutas
        $topRoutes = (clone $pirepsQuery)
            ->select('dpt_airport_id', 'arr_airport_id', DB::raw('COUNT(*) as route_count'))
            ->groupBy('dpt_airport_id', 'arr_airport_id')
            ->orderBy('route_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'route' => $item->dpt_airport_id . ' → ' . $item->arr_airport_id,
                    'count' => $item->route_count,
                ];
            });

        // 5. Top 5 subfleets más usados
        $topSubfleets = (clone $pirepsQuery)
            ->join('aircraft', 'pireps.aircraft_id', '=', 'aircraft.id')
            ->join('subfleets', 'aircraft.subfleet_id', '=', 'subfleets.id')
            ->select('subfleets.id', 'subfleets.name', 'subfleets.type', DB::raw('COUNT(*) as flight_count'))
            ->groupBy('subfleets.id', 'subfleets.name', 'subfleets.type')
            ->orderBy('flight_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'subfleet' => $item->name,
                    'subfleet_type' => $item->type,
                    'flight_count' => $item->flight_count,
                ];
            });

        // 6. Top 5 aviones más usados (con tipo)
        $topAircraftFlights = (clone $pirepsQuery)
            ->join('aircraft', 'pireps.aircraft_id', '=', 'aircraft.id')
            ->join('subfleets', 'aircraft.subfleet_id', '=', 'subfleets.id')
            ->select('aircraft.registration', 'subfleets.type', DB::raw('COUNT(*) as flight_count'))
            ->groupBy('aircraft.registration', 'subfleets.type')
            ->orderBy('flight_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'aircraft_registration' => $item->registration,
                    'aircraft' => $item->registration,
                    'aircraft_type' => $item->type,
                    'flight_count' => $item->flight_count,
                ];
            });

        // 7. Top 5 aviones con más millas voladas (con tipo)
        $topAircraftMiles = (clone $pirepsQuery)
            ->join('aircraft', 'pireps.aircraft_id', '=', 'aircraft.id')
            ->join('subfleets', 'aircraft.subfleet_id', '=', 'subfleets.id')
            ->select('aircraft.registration', 'subfleets.type', DB::raw('SUM(distance) as total_distance'))
            ->groupBy('aircraft.registration', 'subfleets.type')
            ->orderBy('total_distance', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'aircraft_registration' => $item->registration,
                    'aircraft' => $item->registration,
                    'aircraft_type' => $item->type,
                    'total_distance' => round($item->total_distance, 0),
                ];
            });

        // 8. Top 5 aeropuertos de llegada
        $topArrAirports = (clone $pirepsQuery)
            ->select('arr_airport_id', DB::raw('COUNT(*) as arrival_count'))
            ->groupBy('arr_airport_id')
            ->orderBy('arrival_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'airport_id' => $item->arr_airport_id,
                    'airport' => $item->arr_airport_id . ' - ' . ($item->arr_airport->name ?? ''),
                    'arrival_count' => $item->arrival_count,
                ];
            });

        // 9. Top 5 aeropuertos de salida
        $topDptAirports = (clone $pirepsQuery)
            ->select('dpt_airport_id', DB::raw('COUNT(*) as departure_count'))
            ->groupBy('dpt_airport_id')
            ->orderBy('departure_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'airport_id' => $item->dpt_airport_id,
                    'airport' => $item->dpt_airport_id . ' - ' . ($item->dpt_airport->name ?? ''),
                    'departure_count' => $item->departure_count,
                ];
            });

        // 10. Estadísticas generales
        $stats = (clone $pirepsQuery)
            ->select(
                DB::raw('COUNT(*) as total_pireps'),
                DB::raw('SUM(flight_time) as total_block_time'),
                DB::raw('AVG(flight_time) as avg_block_time'),
                DB::raw('SUM(block_fuel) as total_fuel_burn'),
                DB::raw('AVG(block_fuel) as avg_fuel_burn'),
                DB::raw('SUM(distance) as total_distance'),
                DB::raw('AVG(distance) as avg_distance'),
                DB::raw('AVG(landing_rate) as avg_landing_rate')
            )
            ->first();

        // 11. Estadísticas de pasajeros y carga (desde pirep_fares)
        // Obtener los IDs de los PIREPs en el período
        $pirepIds = (clone $pirepsQuery)->pluck('id')->toArray();

        $pax_amount = 0;
        $pax_avg = 0;
        $cgo_amount = 0;
        $cgo_avg = 0;

        if (!empty($pirepIds)) {
            // Pasajeros (type = FareType::PASSENGER = 0)
            $paxStats = PirepFare::where('type', FareType::PASSENGER)
                ->whereIn('pirep_id', $pirepIds)
                ->selectRaw('SUM(count) as total, AVG(count) as average')
                ->first();
            
            $pax_amount = $paxStats->total ?? 0;
            $pax_avg = round($paxStats->average ?? 0, 1);
            
            // Carga (type = FareType::CARGO = 1)
            $cgoStats = PirepFare::where('type', FareType::CARGO)
                ->whereIn('pirep_id', $pirepIds)
                ->selectRaw('SUM(count) as total, AVG(count) as average')
                ->first();
            
            $cgo_amount = $cgoStats->total ?? 0;
            $cgo_avg = round($cgoStats->average ?? 0, 1);
        }

        // Calcular valores derivados
        $totalHours = $stats->total_block_time / 60;
        $avgFuelBurnPerHour = $totalHours > 0 ? $stats->total_fuel_burn / $totalHours : 0;
        $avgDistancePerHour = $totalHours > 0 ? $stats->total_distance / $totalHours : 0;

        return [
            'top_landing_score' => $topLandingScore,
            'top_miles' => $topMiles,
            'top_flights_count' => $topFlightsCount,
            'top_score' => $topScore,
            'top_routes' => $topRoutes,
            'top_subfleets' => $topSubfleets,
            'top_aircraft_flights' => $topAircraftFlights,
            'top_aircraft_miles' => $topAircraftMiles,
            'top_arr_airports' => $topArrAirports,
            'top_dpt_airports' => $topDptAirports,
            'stats' => [
                'total_pireps' => $stats->total_pireps ?? 0,
                'total_block_time' => $this->formatMinutes($stats->total_block_time ?? 0),
                'avg_block_time' => $this->formatMinutes($stats->avg_block_time ?? 0),
                'total_fuel_burn' => round($stats->total_fuel_burn ?? 0),
                'avg_fuel_burn' => round($stats->avg_fuel_burn ?? 0),
                'total_distance' => round($stats->total_distance ?? 0),
                'avg_distance' => round($stats->avg_distance ?? 0, 1),
                'avg_fuel_burn_per_hour' => round($avgFuelBurnPerHour, 0),
                'avg_distance_per_hour' => round($avgDistancePerHour, 1),
                'avg_landing_rate' => round($stats->avg_landing_rate ?? 0),
                'total_passengers' => $pax_amount,
                'avg_passengers' => $pax_avg,
                'total_freight' => $cgo_amount,
                'avg_freight' => $cgo_avg,
                'avg_score' => 0,
            ],
        ];
    }   

    private function formatMinutes($minutes)
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return $hours . 'h ' . $mins . 'm';
    }
}
