<?php

namespace App\Providers;

use App\Services\Events\EventBusPublisher;
use App\Services\Events\LogEventBusPublisher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventBusPublisher::class, LogEventBusPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
