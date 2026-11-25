<?php

namespace App\Providers;

use App\Models\User;
use App\Services\SoapLoanService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SoapLoanService::class, function (Application $app): SoapLoanService {
            $request = $app['request'];

            return new SoapLoanService(
                $request->attributes->get('jwt_subject', ''),
                $request->attributes->get('jwt_token', ''),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::viaRequest('jwt', fn (Request $request): ?User => $request->attributes->get('jwt_user'));

        Gate::define('consultar-prestamos', function (User $user, string $operation): bool {
            return match ($user->role) {
                'administrador' => in_array($operation, ['registrarSancion', 'consultarSancionesSolicitante', 'registrarReposicion', 'consultarReposicion'], true),
                'encargado_laboratorio' => in_array($operation, ['registrarSancion', 'consultarSancionesSolicitante'], true),
                'solicitante' => $operation === 'consultarSancionesSolicitante',
                default => false,
            };
        });
    }
}
