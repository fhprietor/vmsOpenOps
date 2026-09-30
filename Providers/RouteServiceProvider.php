<?php

namespace Modules\VmsOpenOps\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected $namespace = 'Modules\VmsOpenOps\Http\Controllers';
    
    public function before(Router $router)
    {
        //
    }
    
    public function map(Router $router)
    {
        $this->registerWebRoutes();
        $this->registerAdminRoutes();
        $this->registerApiRoutes();
    }
    
    protected function registerWebRoutes(): void
    {
        Route::group([
            'as' => 'vmsopenops.',
            'prefix' => 'vmsopenops',
            'namespace' => $this->namespace . '\Frontend',
            'middleware' => ['web'],
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../Http/Routes/web.php');
        });
    }
    
    protected function registerAdminRoutes(): void
    {
        Route::group([
            'as' => 'admin.vmsopenops.',
            'prefix' => 'admin/vmsopenops',
            'namespace' => $this->namespace . '\Admin',
            'middleware' => ['web', 'ability:admin,admin-access'],
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../Http/Routes/admin.php');
        });
    }
    
    protected function registerApiRoutes(): void
    {
        Route::group([
            'as' => 'api.vmsopenops.',
            'prefix' => 'api/vmsopenops',
            'namespace' => $this->namespace . '\Api',
            'middleware' => ['web', 'auth'],  // Cambiado de 'api' a 'web' y agregado 'auth'
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../Http/Routes/api.php');
        });
    }
}