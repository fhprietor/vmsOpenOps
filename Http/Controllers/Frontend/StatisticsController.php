<?php

namespace Modules\VmsOpenOps\Http\Controllers\Frontend;

use App\Contracts\Controller;

/**
 * Pagina de estadisticas de operaciones.
 *
 * Solo sirve la vista: los datos los carga el JS desde la API
 * (GET /api/vmsopenops/stats -> Api\StatisticsController@getData), que es la
 * unica implementacion viva. Aqui vivia un getData() sin ruta que ademas
 * consultaba columnas inexistentes (pireps.passengers / pireps.cargo); se ha
 * eliminado junto a sus helpers.
 */
class StatisticsController extends Controller
{
    public function index()
    {
        return view('vmsopenops::frontend.stats.index');
    }
}
