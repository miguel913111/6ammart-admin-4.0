<?php

namespace Modules\RideShare\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\RideShare\Interface\UserManagement\Service\DriverLevelService;
use Modules\RideShare\Interface\UserManagement\Service\DriverLevelServiceInterface;

class RideShareServiceProvider extends ServiceProvider
{
    /**
     * Register the RideShare module services.
     */
    public function register(): void
    {
        $this->app->bind(DriverLevelServiceInterface::class, DriverLevelService::class);
    }

    /**
     * Bootstrap the module services.
     */
    public function boot(): void
    {
        //
    }
}
