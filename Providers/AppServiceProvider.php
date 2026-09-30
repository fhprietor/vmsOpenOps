<?php

namespace Modules\VmsOpenOps\Providers;

use App\Contracts\Modules\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    protected $defer = false;
    
    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->registerLinks();
        
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
    
    public function register()
    {
        //
    }
    
    public function registerLinks(): void
    {
        $moduleSvc = app('App\Services\ModuleService');
        
        $moduleSvc->addFrontendLink('Jumpseat', '/vmsopenops/jumpseat', '', $logged_in = true);
        $moduleSvc->addFrontendLink('Ferry', '/vmsopenops/ferry', '', $logged_in = true);
        $moduleSvc->addFrontendLink('Charter', '/vmsopenops/charter/create', '', $logged_in = true);
        $moduleSvc->addAdminLink('Operations', '/admin/vmsopenops', 'pe-7s-rocket');
        $moduleSvc->addFrontendLink('Statistics', '/vmsopenops/stats', '', $logged_in = true);
    }
    
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__ . '/../Config/config.php' => config_path('vmsopenops.php'),
        ], 'vmsopenops');
        
        $this->mergeConfigFrom(__DIR__ . '/../Config/config.php', 'vmsopenops');
    }
    
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/vmsopenops');
        $sourcePath = __DIR__ . '/../Resources/views';
        
        $this->publishes([$sourcePath => $viewPath], 'views');
        
        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/vmsopenops';
        }, \Config::get('view.paths')), [$sourcePath]), 'vmsopenops');
    }
    
    public function registerTranslations()
    {
        $langPath = resource_path('lang/modules/vmsopenops');
        
        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, 'vmsopenops');
        } else {
            $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'vmsopenops');
        }
    }
}