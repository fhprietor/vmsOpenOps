<?php

namespace Modules\VmsOpenOps\Http\Controllers\Frontend;

use App\Contracts\Controller;
use App\Models\Enums\PirepState;
use App\Models\Pirep;
use App\Models\User;
use App\Models\Aircraft;
use App\Models\Subfleet;
use App\Models\Airport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class StatisticsController extends Controller
{
    public function index()
    {
        return view('vmsopenops::frontend.stats.index');
    }

    public function getData(Request $request)
    {
        // Validar período
        $period = $request->get('period', 'month'); // week, month, quarter, semester, year, custom
        $days = $request->get('days', 30);
        $year = $request->get('year', null);
        $month = $request->get('month', null);

        // Construir condición de fecha
        $startDate = null;
        $endDate = now();

        if ($year && $month) {
            $startDate = now()->setYear($year)->setMonth($month)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
        } elseif ($period === 'week') {
            $startDate = now()->subDays(7);
        } elseif ($period === 'quarter') {
            $startDate = now()->subMonths(3);
        } elseif ($period === 'semester') {
            $startDate = now()->subMonths(6);
        } elseif ($period === 'year') {
            $startDate = now()->subDays(365);
        } else { // month or custom days
            $days = max(1, (int)$days);
            $startDate = now()->subDays($days);
        }

        // Cachear resultados por unos minutos para evitar consultas repetitivas
        $cacheKey = 'vms_stats_' . md5($startDate ? $startDate->toDateString() : 'all' . '_' . $endDate->toDateString() . '_' . $year . '_' . $month);
        $data = Cache::remember($cacheKey, 300, function () use ($startDate, $endDate, $year, $month) {
            return $this->computeStats($startDate, $endDate, $year, $month);
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function computeStats($startDate, $endDate, $year, $month)
    {
        // Base query para PIREPs aceptados
        $pirepsQuery = Pirep::where('state', PirepState::ACCEPTED);
        if ($startDate) {
            $pirepsQuery->whereBetween('submitted_at', [$startDate, $endDate]);
        } elseif ($year && $month) {
            $pirepsQuery->whereYear('submitted_at', $year)->whereMonth('submitted_at', $month);
        }

        // 1. Top 5 pilotos por landing score promedio
        $topLandingScore = (clone $pirepsQuery)
            ->select('user_id', DB::raw('AVG(landing_rate) as avg_landing_rate'))
            ->whereNotNull('landing_rate')
            ->groupBy('user_id')
            ->orderBy('avg_landing_rate', 'asc') // menores números son mejores (ej: -150 es mejor que -300)
            ->with('user')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'pilot' => $item->user->ident . ' - ' . $item->user->name_private,
                    'avg_landing_rate' => round($item->avg_landing_rate),
                ];
            });

        // 2. Top 5 pilotos por millas voladas (distance)
        $topMiles = (clone $pirepsQuery)
            ->select('user_id', DB::raw('SUM(distance) as total_distance'))
            ->groupBy('user_id')
            ->orderBy('total_distance', 'desc')
            ->with('user')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'pilot' => $item->user->ident . ' - ' . $item->user->name_private,
                    'total_distance' => round($item->total_distance, 0),
                ];
            });

        // 3. Top 5 pilotos por número de vuelos
        $topFlightsCount = (clone $pirepsQuery)
            ->select('user_id', DB::raw('COUNT(*) as flight_count'))
            ->groupBy('user_id')
            ->orderBy('flight_count', 'desc')
            ->with('user')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'pilot' => $item->user->ident . ' - ' . $item->user->name_private,
                    'flight_count' => $item->flight_count,
                ];
            });

        // 4. Top 5 rutas (dpt_airport_id -> arr_airport_id)
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

        // 5. Top 5 subfleets más usados (por número de vuelos)
        $topSubfleets = (clone $pirepsQuery)
            ->join('aircraft', 'pireps.aircraft_id', '=', 'aircraft.id')
            ->join('subfleets', 'aircraft.subfleet_id', '=', 'subfleets.id')
            ->select('subfleets.id', 'subfleets.name', DB::raw('COUNT(*) as flight_count'))
            ->groupBy('subfleets.id', 'subfleets.name')
            ->orderBy('flight_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'subfleet' => $item->name,
                    'flight_count' => $item->flight_count,
                ];
            });

        // 6. Top 5 aviones más usados (por número de vuelos)
        $topAircraftFlights = (clone $pirepsQuery)
            ->join('aircraft', 'pireps.aircraft_id', '=', 'aircraft.id')
            ->select('aircraft.id', 'aircraft.registration', DB::raw('COUNT(*) as flight_count'))
            ->groupBy('aircraft.id', 'aircraft.registration')
            ->orderBy('flight_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'aircraft' => $item->registration,
                    'flight_count' => $item->flight_count,
                ];
            });

        // 7. Top 5 aviones con más millas voladas
        $topAircraftMiles = (clone $pirepsQuery)
            ->join('aircraft', 'pireps.aircraft_id', '=', 'aircraft.id')
            ->select('aircraft.id', 'aircraft.registration', DB::raw('SUM(distance) as total_distance'))
            ->groupBy('aircraft.id', 'aircraft.registration')
            ->orderBy('total_distance', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'aircraft' => $item->registration,
                    'total_distance' => round($item->total_distance, 0),
                ];
            });

        // 8. Top 5 aeropuertos de llegada
        $topArrAirports = (clone $pirepsQuery)
            ->select('arr_airport_id', DB::raw('COUNT(*) as arrival_count'))
            ->groupBy('arr_airport_id')
            ->orderBy('arrival_count', 'desc')
            ->with('arr_airport')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'airport' => $item->arr_airport_id . ' - ' . ($item->arr_airport->name ?? ''),
                    'arrival_count' => $item->arrival_count,
                ];
            });

        // 9. Top 5 aeropuertos de salida
        $topDptAirports = (clone $pirepsQuery)
            ->select('dpt_airport_id', DB::raw('COUNT(*) as departure_count'))
            ->groupBy('dpt_airport_id')
            ->orderBy('departure_count', 'desc')
            ->with('dpt_airport')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'airport' => $item->dpt_airport_id . ' - ' . ($item->dpt_airport->name ?? ''),
                    'departure_count' => $item->departure_count,
                ];
            });

        // 10. Estadísticas generales de PIREPs
        $stats = (clone $pirepsQuery)
            ->select(
                DB::raw('COUNT(*) as total_pireps'),
                DB::raw('SUM(passengers) as total_passengers'),
                DB::raw('AVG(passengers) as avg_passengers'),
                DB::raw('SUM(cargo) as total_freight'),
                DB::raw('AVG(cargo) as avg_freight'),
                DB::raw('SUM(flight_time) as total_block_time'),
                DB::raw('AVG(flight_time) as avg_block_time'),
                DB::raw('SUM(block_fuel) as total_fuel_burn'),
                DB::raw('AVG(block_fuel) as avg_fuel_burn'),
                DB::raw('SUM(distance) as total_distance'),
                DB::raw('AVG(distance) as avg_distance'),
                DB::raw('AVG(landing_rate) as avg_landing_rate'),
                DB::raw('AVG(score) as avg_score')
            )
            ->first();

        // Calcular Avg. Fuel Burn/Hour (total_fuel / total_block_time)
        $avgFuelBurnPerHour = $stats->total_block_time > 0 ? $stats->total_fuel_burn / ($stats->total_block_time / 60) : 0;
        $avgDistancePerHour = $stats->total_block_time > 0 ? $stats->total_distance / ($stats->total_block_time / 60) : 0;

        return [
            'top_landing_score' => $topLandingScore,
            'top_miles' => $topMiles,
            'top_flights_count' => $topFlightsCount,
            'top_routes' => $topRoutes,
            'top_subfleets' => $topSubfleets,
            'top_aircraft_flights' => $topAircraftFlights,
            'top_aircraft_miles' => $topAircraftMiles,
            'top_arr_airports' => $topArrAirports,
            'top_dpt_airports' => $topDptAirports,
            'stats' => [
                'total_pireps' => $stats->total_pireps ?? 0,
                'total_passengers' => round($stats->total_passengers ?? 0),
                'avg_passengers' => round($stats->avg_passengers ?? 0, 1),
                'total_freight' => round($stats->total_freight ?? 0),
                'avg_freight' => round($stats->avg_freight ?? 0, 1),
                'total_block_time' => $this->formatMinutes($stats->total_block_time ?? 0),
                'avg_block_time' => $this->formatMinutes($stats->avg_block_time ?? 0),
                'total_fuel_burn' => round($stats->total_fuel_burn ?? 0),
                'avg_fuel_burn' => round($stats->avg_fuel_burn ?? 0),
                'total_distance' => round($stats->total_distance ?? 0),
                'avg_distance' => round($stats->avg_distance ?? 0),
                'avg_fuel_burn_per_hour' => round($avgFuelBurnPerHour, 0),
                'avg_distance_per_hour' => round($avgDistancePerHour, 0),
                'avg_landing_rate' => round($stats->avg_landing_rate ?? 0),
                'avg_score' => round($stats->avg_score ?? 0, 1),
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