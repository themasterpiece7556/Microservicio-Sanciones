<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanViewSanctions
{
    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'solicitante' || ! in_array($operation, ['login', 'consultarSancionesSolicitante', 'consultarReposicion'], true)) {
            return response()->json(['error' => 'Permisos insuficientes para ejecutar este método'], 403);
        }

        return $next($request);
    }
}
