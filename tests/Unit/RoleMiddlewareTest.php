<?php

namespace Tests\Unit;

use App\Http\Middleware\CanManageSanctions;
use App\Http\Middleware\CanProcessReplacements;
use App\Http\Middleware\CanViewSanctions;
use App\Http\Middleware\IsAdministrator;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    public static function permissions(): iterable
    {
        $profiles = [
            [IsAdministrator::class, 'administrador', ['login', 'registrarSancion', 'consultarSancionesSolicitante', 'registrarReposicion', 'consultarReposicion']],
            [CanManageSanctions::class, 'encargado_laboratorio', ['login', 'registrarSancion', 'consultarSancionesSolicitante']],
            [CanProcessReplacements::class, 'almacenista', ['login', 'registrarReposicion', 'consultarReposicion']],
            [CanViewSanctions::class, 'solicitante', ['login', 'consultarSancionesSolicitante', 'consultarReposicion']],
        ];
        foreach ($profiles as [$middleware, $role, $allowed]) {
            foreach (['login', 'registrarSancion', 'consultarSancionesSolicitante', 'registrarReposicion', 'consultarReposicion', 'completarReposicion', 'unknown'] as $operation) {
                yield [$middleware, $role, $operation, in_array($operation, $allowed, true)];
            }
            yield [$middleware, 'wrong-role', 'login', false];
            yield [$middleware, null, 'login', false];
        }
    }

    #[DataProvider('permissions')]
    public function test_role_and_operation_are_checked_before_the_next_callback(string $middleware, ?string $role, string $operation, bool $allowed): void
    {
        $request = Request::create('/test');
        $user = $role === null ? null : User::factory()->make(['role' => $role]);
        $request->setUserResolver(fn () => $user);
        $called = false;

        $response = app($middleware)->handle($request, function () use (&$called) {
            $called = true;

            return response()->json(['ok' => true]);
        }, $operation);

        $this->assertSame($allowed, $called);
        $this->assertSame($allowed ? 200 : 403, $response->getStatusCode());
        if (! $allowed) {
            $this->assertSame(['error' => 'Permisos insuficientes para ejecutar este método'], $response->getData(true));
        }
    }
}
