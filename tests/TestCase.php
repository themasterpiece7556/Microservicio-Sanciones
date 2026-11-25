<?php

namespace Tests;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function withRole(string $role, ?int $solicitanteId = null): User
    {
        config(['jwt.secret' => str_repeat('s', 32)]);
        $user = User::factory()->create([
            'role' => $role,
            'soap_username' => fake()->unique()->userName(),
            'solicitante_id' => $solicitanteId,
        ]);
        $this->withToken(app(JwtService::class)->issue($user->soap_username));

        return $user;
    }
}
