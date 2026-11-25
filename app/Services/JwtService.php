<?php

namespace App\Services;

use Firebase\JWT\JWT;
use LogicException;

class JwtService
{
    public function issue(string $username): string
    {
        $secret = config('jwt.secret');
        $ttl = config('jwt.ttl');

        if (! is_string($secret) || strlen($secret) < 32 || ! is_int($ttl) || $ttl < 1) {
            throw new LogicException('Configure JWT_SECRET con al menos 32 bytes y JWT_TTL positivo.');
        }

        $issuedAt = time();

        return JWT::encode([
            'sub' => $username,
            'username' => $username,
            'iss' => config('jwt.issuer'),
            'aud' => config('jwt.audience'),
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttl,
        ], $secret, 'HS256');
    }
}
