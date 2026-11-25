<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JwtService;
use App\Services\SoapLoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class LoginController extends Controller
{
    public function __invoke(Request $request, SoapLoanService $soap, JwtService $jwt): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        try {
            $result = $soap->login($credentials['username'], $credentials['password']);
        } catch (Throwable) {
            return response()->json(['message' => 'No se pudo autenticar con el servicio SOAP.'], 502);
        }

        if (! isset($result->exito) || ! is_bool($result->exito)) {
            return response()->json(['message' => 'Respuesta de autenticación SOAP inválida.'], 502);
        }

        if (! $result->exito) {
            return response()->json(['message' => 'Credenciales incorrectas.'], 401);
        }

        return response()->json([
            'token' => $jwt->issue($credentials['username']),
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl'),
        ])->header('Cache-Control', 'no-store');
    }
}
