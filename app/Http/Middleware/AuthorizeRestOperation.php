<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeRestOperation
{
    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $middleware = match ($request->user()?->role) {
            'administrador' => IsAdministrator::class,
            'encargado_laboratorio' => CanManageSanctions::class,
            'almacenista' => CanProcessReplacements::class,
            'solicitante' => CanViewSanctions::class,
            default => null,
        };

        if ($middleware === null) {
            return response()->json(['error' => 'Permisos insuficientes para ejecutar este método'], 403);
        }

        return app($middleware)->handle($request, $next, $operation);
    }
}
