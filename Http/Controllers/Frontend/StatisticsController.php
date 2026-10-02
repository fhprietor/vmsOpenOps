<?php

namespace Modules\VmsOpenOps\Http\Controllers\Frontend;

use App\Contracts\Controller;
use App\Models\Enums\PirepState;
use App\Models\Enums\UserState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pagina de estadisticas de operaciones.
 *
 * Arriba van los **totales historicos de la compania**, que se pintan en el HTML y
 * por tanto los ve tambien un invitado (la ruta es publica). Se calculan aqui porque
 * asi no hace falta exponer la API: `GET /api/vmsopenops/stats` sigue detras de auth
 * y es la que alimenta el bloque de abajo.
 *
 * Debajo, los **rankings por periodo**, que carga el JS desde esa API. Solo tienen
 * sentido con sesion iniciada (la vista los esconde a los invitados).
 */
class StatisticsController extends Controller
{
    public const TOTALS_CACHE_KEY = 'vmsopenops.company_totals';

    public const TOTALS_CACHE_MINUTES = 15;

    public function index()
    {
        return view('vmsopenops::frontend.stats.index', [
            'companyTotals' => Cache::remember(
                self::TOTALS_CACHE_KEY,
                now()->addMinutes(self::TOTALS_CACHE_MINUTES),
                fn () => $this->companyTotals()
            ),
        ]);
    }

    /**
     * Totales historicos de la compania.
     *
     * Una sola pasada sobre los PIREPs aceptados (agregados y condicionales en la
     * misma consulta) mas cuatro counts pequenos. Se cachea, no se recalcula por
     * visita: son agregados sobre toda la tabla.
     */
    private function companyTotals(): array
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $monthStart = now()->startOfMonth()->toDateTimeString();

        $agg = DB::table('pireps')
            ->where('state', PirepState::ACCEPTED)
            ->selectRaw('count(*) as pireps')
            ->selectRaw('coalesce(sum(flight_time), 0) as minutes')
            ->selectRaw('coalesce(sum(fuel_used), 0) as fuel_lbs')
            ->selectRaw('coalesce(sum(distance), 0) as distance')
            ->selectRaw('count(distinct user_id) as pilots')
            ->selectRaw('count(distinct arr_airport_id) as destinations')
            ->selectRaw('sum(case when date(submitted_at) = ? then 1 else 0 end) as flights_today', [$today])
            ->selectRaw('sum(case when date(submitted_at) = ? then 1 else 0 end) as flights_yesterday', [$yesterday])
            ->selectRaw('count(distinct case when submitted_at >= ? then user_id end) as pilots_month', [$monthStart])
            ->first();

        $activePilots = DB::table('users')->where('state', UserState::ACTIVE)->count();
        $aircraft = DB::table('aircraft')->count();
        $flights = DB::table('flights')->count();
        $routes = DB::table('flights')->distinct()->count(DB::raw('concat(dpt_airport_id, "-", arr_airport_id)'));
        $hubs = DB::table('airports')->where('hub', 1)->count();

        // El combustible se guarda en libras; se muestra en kg, como la web de referencia.
        $fuelKg = (int) round(((float) $agg->fuel_lbs) * 0.45359237);

        return [
            $this->tile('Pilotos con PIREP', number_format((int) $agg->pilots), 'bi-people-fill', '#26A69A'),
            $this->tile('Pilotos activos', number_format($activePilots), 'bi-person-check-fill', '#4CAF76'),
            $this->tile('Pilotos este mes', number_format((int) $agg->pilots_month), 'bi-calendar-check', '#5C6BC0'),
            $this->tile('Aeronaves', number_format($aircraft), 'bi-airplane-engines', '#7E57C2'),
            $this->tile('Vuelos programados', number_format($flights), 'bi-signpost-2', '#EF6C00'),
            $this->tile('Rutas unicas', number_format($routes), 'bi-diagram-3', '#4A90D9'),
            $this->tile('PIREPs aceptados', number_format((int) $agg->pireps), 'bi-journal-check', '#563A63'),
            $this->tile('Vuelos hoy', number_format((int) $agg->flights_today), 'bi-calendar-day', '#4CAF76'),
            $this->tile('Vuelos ayer', number_format((int) $agg->flights_yesterday), 'bi-calendar-minus', '#607D8B'),
            $this->tile('Horas voladas', $this->formatMinutes((int) $agg->minutes), 'bi-clock-history', '#8D6E63'),
            $this->tile('Destinos', number_format((int) $agg->destinations), 'bi-geo-alt-fill', '#E05252'),
            $this->tile('Combustible', number_format($fuelKg).' kg', 'bi-fuel-pump', '#EF6C00'),
            $this->tile('Distancia total', number_format((int) $agg->distance).' nm', 'bi-rulers', '#4A90D9'),
            $this->tile('Hubs', number_format($hubs), 'bi-building', '#412C4D'),
        ];
    }

    private function tile(string $label, string $value, string $icon, string $color): array
    {
        return compact('label', 'value', 'icon', 'color');
    }

    /**
     * Minutos a H:MM, con separador de miles en las horas (13,976:04).
     */
    private function formatMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return number_format($hours).':'.str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
    }
}
