<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanManageSanctions
{
    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'encargado_laboratorio' || ! in_array($operation, ['login', 'registrarSancion', 'consultarSancionesSolicitante'], true)) {
            return response()->json(['error' => 'Permisos insuficientes para ejecutar este método'], 403);
        }

        return $next($request);
    }
}
