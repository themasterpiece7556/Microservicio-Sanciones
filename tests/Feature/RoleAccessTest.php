<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReposicionController;
use App\Http\Controllers\Api\SancionController;
use App\Models\Reposicion;
use App\Models\Sancion;
use App\Services\SoapLoanService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function endpoints(): array
    {
        return [
            ['POST', '/api/v1/admin/sanciones', ['administrador']],
            ['GET', '/api/v1/admin/solicitantes/25/sanciones', ['administrador']],
            ['POST', '/api/v1/admin/reposiciones', ['administrador']],
            ['GET', '/api/v1/admin/reposiciones/{id}', ['administrador']],
            ['POST', '/api/v1/laboratorio/sanciones', ['encargado_laboratorio']],
            ['GET', '/api/v1/laboratorio/solicitantes/25/sanciones', ['encargado_laboratorio']],
            ['POST', '/api/v1/almacen/reposiciones', ['almacenista']],
            ['GET', '/api/v1/almacen/reposiciones/{id}', ['almacenista']],
            ['GET', '/api/v1/solicitante/solicitantes/25/sanciones', ['solicitante']],
            ['GET', '/api/v1/solicitante/reposiciones/{id}', ['solicitante']],
            ['POST', '/api/v1/sanciones', ['administrador', 'encargado_laboratorio']],
            ['GET', '/api/v1/solicitantes/25/sanciones', ['administrador', 'encargado_laboratorio', 'solicitante']],
            ['POST', '/api/v1/reposiciones', ['administrador', 'almacenista']],
            ['PUT', '/api/v1/reposiciones/1/completar', []],
        ];
    }

    public static function deniedMatrix(): iterable
    {
        foreach (self::endpoints() as [$method, $path, $allowed]) {
            foreach (['administrador', 'encargado_laboratorio', 'almacenista', 'solicitante', 'unknown'] as $role) {
                if (! in_array($role, $allowed, true)) {
                    yield [$role, $method, str_replace('{id}', '1', $path)];
                }
            }
        }
    }

    public static function allowedMatrix(): iterable
    {
        foreach (self::endpoints() as [$method, $path, $allowed]) {
            foreach ($allowed as $role) {
                yield [$role, $method, $path];
            }
        }
    }

    #[DataProvider('deniedMatrix')]
    public function test_forbidden_roles_never_reach_controllers_or_soap(string $role, string $method, string $path): void
    {
        $this->withRole($role, 25);
        $this->mock(SancionController::class)->shouldNotReceive('store', 'consultarPorSolicitante');
        $this->mock(ReposicionController::class)->shouldNotReceive('store', 'show');
        $this->mock(SoapLoanService::class)->shouldNotReceive('consultarPrestamos', 'consultarEquipo', 'actualizarEstadoEquipo', 'login');

        $this->json($method, $path)->assertForbidden()->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método']);
        $this->assertDatabaseCount('sanciones', 0);
        $this->assertDatabaseCount('reposiciones', 0);
    }

    #[DataProvider('allowedMatrix')]
    public function test_allowed_routes_execute_the_business_action(string $role, string $method, string $path): void
    {
        $this->withRole($role, 25);
        $sancion = Sancion::factory()->create(['solicitante_id' => 25]);
        $reposicion = Reposicion::factory()->create(['sancion_id' => $sancion->id, 'solicitante_id' => 25]);
        $path = str_replace('{id}', (string) $reposicion->id, $path);
        $isSanction = str_contains($path, '/sanciones');
        $payload = $isSanction
            ? ['prestamo_id' => 10, 'solicitante_id' => 25, 'tipo' => 'Dano', 'descripcion' => 'Prueba']
            : ['sancion_id' => $sancion->id, 'solicitante_id' => 25, 'equipo_id' => 8, 'costo_estimado' => 100];
        if ($isSanction) {
            $this->partialMock(SoapLoanService::class)->shouldReceive('consultarPrestamos')->once()->andReturn((object) ['exito' => true, 'datos' => [['id' => 10, 'solicitante_id' => 25]]]);
        } else {
            $this->mock(SoapLoanService::class)->shouldNotReceive('consultarPrestamos', 'consultarEquipo', 'actualizarEstadoEquipo');
        }

        $this->json($method, $path, $payload)->assertStatus($method === 'POST' ? 201 : 200);
        $this->assertDatabaseCount('sanciones', $isSanction && $method === 'POST' ? 2 : 1);
        $this->assertDatabaseCount('reposiciones', ! $isSanction && $method === 'POST' ? 2 : 1);
    }

    public function test_all_existing_http_routes_except_login_require_a_valid_bearer(): void
    {
        config(['jwt.secret' => str_repeat('s', 32)]);
        foreach (self::endpoints() as [$method, $path]) {
            $this->json($method, str_replace('{id}', '1', $path))->assertUnauthorized();
            $this->withToken('invalid')->json($method, str_replace('{id}', '1', $path))->assertUnauthorized();
            $this->flushHeaders();
        }
        $this->get('/')->assertUnauthorized();
        $this->get('/up')->assertUnauthorized();
    }

    public function test_role_errors_are_json_without_accept_header_and_ignore_wss_headers(): void
    {
        $this->withRole('almacenista');
        $this->mock(SoapLoanService::class)->shouldNotReceive('consultarPrestamos');

        $this->withHeaders(['X-WSS-Username' => 'administrador', 'X-WSS-Token' => 'forged'])->post('/api/v1/sanciones')->assertForbidden()->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método']);
    }

    public function test_ids_larger_than_a_php_integer_return_not_found_without_soap(): void
    {
        $this->withRole('administrador');
        $this->mock(SoapLoanService::class)->shouldNotReceive('consultarPrestamos');
        $hugeId = str_repeat('9', 30);

        $this->getJson('/api/v1/admin/reposiciones/'.$hugeId)->assertNotFound();
        $this->getJson('/api/v1/admin/solicitantes/'.$hugeId.'/sanciones')->assertNotFound();
    }
}
