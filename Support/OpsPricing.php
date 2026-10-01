<?php

namespace Modules\VmsOpenOps\Support;

use App\Models\Aircraft;

/**
 * Calculo de precios de las operaciones, en centavos.
 *
 * Fuente unica para el frontend y la API: antes cada controlador lo calculaba a
 * su manera y el ferry por API llegaba a cobrar 100 veces mas que el frontend.
 *
 * Reglas (las que ya aplicaba el frontend, que es el camino que cobra):
 *  - jumpseat: distancia(NM) x precio/NM, con un minimo global.
 *  - ferry:    distancia(NM) x precio/NM, con un minimo segun MTOW de la
 *              aeronave (ligera / media / pesada).
 */
class OpsPricing
{
    /**
     * Coste del jumpseat en centavos
     */
    public static function jumpseatCostCents(float $distanceNm): int
    {
        $costPerNm = (int) setting('vms_open_ops_jumpseat_cost_per_nm', config('vmsopenops.jumpseat.cost_per_nm'));
        $minCost = (int) setting('vms_open_ops_jumpseat_min_cost', config('vmsopenops.jumpseat.min_cost'));

        return max((int) round($distanceNm * $costPerNm), $minCost);
    }

    /**
     * Coste del ferry en centavos
     */
    public static function ferryCostCents(float $distanceNm, ?Aircraft $aircraft = null): int
    {
        $costPerNm = (int) setting('vms_open_ops_ferry_cost_per_nm', config('vmsopenops.ferry.cost_per_nm'));

        return max((int) round($distanceNm * $costPerNm), self::ferryMinCostCents($aircraft));
    }

    /**
     * Minimo del ferry segun el MTOW de la aeronave (centavos)
     */
    public static function ferryMinCostCents(?Aircraft $aircraft = null): int
    {
        $mtowKg = null;
        if ($aircraft && $aircraft->mtow) {
            $mtowKg = $aircraft->mtow->toUnit('kg');
        }

        if (!$mtowKg) {
            return (int) setting('vms_open_ops_ferry_min_cost_medium', config('vmsopenops.ferry.min_cost_medium'));
        }

        if ($mtowKg <= 7000) {
            return (int) setting('vms_open_ops_ferry_min_cost_light', config('vmsopenops.ferry.min_cost_light'));
        }

        if ($mtowKg <= 136000) {
            return (int) setting('vms_open_ops_ferry_min_cost_medium', config('vmsopenops.ferry.min_cost_medium'));
        }

        return (int) setting('vms_open_ops_ferry_min_cost_heavy', config('vmsopenops.ferry.min_cost_heavy'));
    }
}
