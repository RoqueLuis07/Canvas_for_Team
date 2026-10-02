<?php

namespace App\Providers;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Services\Canvas\HttpCanvasClient;
use App\Services\Canvas\NullCanvasClient;
use App\Services\Teams\HttpTeamsClient;
use App\Services\Teams\NullTeamsClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sin credenciales configuradas (local/demo/tests), se usa un
        // cliente simulado que no hace llamadas externas — así el flujo
        // académico completo (inscripción → curso → matriculación → alta)
        // se puede ejercitar de punta a punta sin depender de Canvas/Azure.
        $this->app->bind(CanvasClient::class, fn () => config('services.canvas.access_token')
            ? app(HttpCanvasClient::class)
            : app(NullCanvasClient::class));

        $this->app->bind(TeamsClient::class, fn () => (
            config('services.azure.tenant_id') && config('services.azure.client_secret')
        ) ? app(HttpTeamsClient::class) : app(NullTeamsClient::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
