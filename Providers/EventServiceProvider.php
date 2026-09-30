<?php

namespace Modules\VmsOpenOps\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];
    
    public function boot()
    {
        parent::boot();
    }
}