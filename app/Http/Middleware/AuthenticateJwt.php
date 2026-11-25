<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class AuthenticateJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('jwt');
        Auth::guard('jwt')->forgetUser()->setRequest($request);

        if ($request->is('api/v1/login') && $request->isMethod('POST')) {
            return $next($request);
        }

        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'Token ausente, inválido o expirado.'], 401);
        }

        $secret = config('jwt.secret');

        if (! is_string($secret) || strlen($secret) < 32) {
            throw new \LogicException('Configure JWT_SECRET con al menos 32 bytes.');
        }

        try {
            $claims = JWT::decode($token, new Key($secret, 'HS256'));

            if (! isset($claims->exp, $claims->iat, $claims->sub) || ! is_string($claims->sub) || $claims->sub === '' || ($claims->iss ?? null) !== config('jwt.issuer') || ($claims->aud ?? null) !== config('jwt.audience')) {
                throw new UnexpectedValueException('Invalid claims');
            }
        } catch (UnexpectedValueException|\DomainException) {
            return response()->json(['message' => 'Token ausente, inválido o expirado.'], 401);
        }

        $user = User::where('soap_username', $claims->sub)->first();

        if (! $user || $user->soap_username !== $claims->sub) {
            return response()->json(['message' => 'Token ausente, inválido o expirado.'], 401);
        }

        $request->setUserResolver(fn (?string $guard = null): User => $user);
        $request->attributes->set('jwt_user', $user);
        $request->attributes->set('jwt_subject', $claims->sub);
        $request->attributes->set('jwt_token', $token);

        return $next($request);
    }
}
