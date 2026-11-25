<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanProcessReplacements
{
    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'almacenista' || ! in_array($operation, ['login', 'registrarReposicion', 'consultarReposicion'], true)) {
            return response()->json(['error' => 'Permisos insuficientes para ejecutar este método'], 403);
        }

        return $next($request);
    }
}
