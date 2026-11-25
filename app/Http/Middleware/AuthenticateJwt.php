<?php

namespace App\Http\Middleware;

use App\Services\SoapLoanService;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class AuthenticateJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $secret = config('jwt.secret');

        if (! is_string($secret) || strlen($secret) < 32) {
            throw new \LogicException('Configure JWT_SECRET con al menos 32 bytes.');
        }

        try {
            if (! $token) {
                throw new UnexpectedValueException('Missing token');
            }

            $claims = JWT::decode($token, new Key($secret, 'HS256'));

            if (! isset($claims->exp, $claims->iat, $claims->sub) || ! is_string($claims->sub) || $claims->sub === '' || ($claims->iss ?? null) !== config('jwt.issuer') || ($claims->aud ?? null) !== config('jwt.audience')) {
                throw new UnexpectedValueException('Invalid claims');
            }
        } catch (UnexpectedValueException|\DomainException) {
            return response()->json(['message' => 'Token ausente, inválido o expirado.'], 401);
        }

        $request->attributes->set('jwt_subject', $claims->sub);
        app()->scoped(SoapLoanService::class, fn () => new SoapLoanService($claims->sub, $token));

        return $next($request);
    }
}
