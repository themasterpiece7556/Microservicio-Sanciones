<?php

namespace Tests\Feature;

use App\Models\Reposicion;
use App\Models\Sancion;
use App\Services\SoapLoanService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReposicionApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_warehouse_creates_replacements_locally_without_soap(): void
    {
        $this->withRole('almacenista');
        $sancion = Sancion::factory()->create(['solicitante_id' => 25]);
        $this->mock(SoapLoanService::class)->shouldNotReceive('consultarPrestamos', 'consultarEquipo', 'actualizarEstadoEquipo', 'login');

        $this->postJson('/api/v1/reposiciones', ['sancion_id' => $sancion->id, 'solicitante_id' => 25, 'equipo_id' => 8, 'costo_estimado' => 150000, 'estado' => 'Completada'])->assertCreated()->assertJsonPath('datos.estado', 'Pendiente');
        $this->assertDatabaseHas('reposiciones', ['sancion_id' => $sancion->id, 'equipo_id' => 8, 'solicitante_id' => 25, 'estado' => 'Pendiente']);
    }

    public function test_replacement_applicant_must_match_the_sanction(): void
    {
        $this->withRole('administrador');
        $sancion = Sancion::factory()->create(['solicitante_id' => 25]);

        $this->postJson('/api/v1/reposiciones', ['sancion_id' => $sancion->id, 'solicitante_id' => 26, 'equipo_id' => 8, 'costo_estimado' => 1])->assertUnprocessable()->assertJsonValidationErrors('solicitante_id');
        $this->assertDatabaseCount('reposiciones', 0);
    }

    public function test_replacement_fields_are_validated(): void
    {
        $this->withRole('almacenista');
        $sancion = Sancion::factory()->create(['solicitante_id' => 25]);
        $payload = ['sancion_id' => $sancion->id, 'solicitante_id' => 25, 'equipo_id' => 8, 'costo_estimado' => 1];

        foreach ([['sancion_id' => 999], ['equipo_id' => 0], ['costo_estimado' => -1], ['costo_estimado' => 100000000]] as $invalid) {
            $this->postJson('/api/v1/reposiciones', array_replace($payload, $invalid))->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        }
        $this->assertDatabaseCount('reposiciones', 0);
    }

    public function test_applicant_can_read_only_their_replacement_without_soap(): void
    {
        $this->withRole('solicitante', 25);
        $own = Reposicion::factory()->create(['solicitante_id' => 25, 'sancion_id' => Sancion::factory()->create(['solicitante_id' => 25])->id]);
        $other = Reposicion::factory()->create(['solicitante_id' => 26, 'sancion_id' => Sancion::factory()->create(['solicitante_id' => 26])->id]);
        $this->mock(SoapLoanService::class)->shouldNotReceive('consultarPrestamos');

        $this->getJson('/api/v1/solicitante/reposiciones/'.$own->id)->assertOk()->assertJsonPath('datos.id', $own->id)->assertJsonStructure(['datos' => ['sancion']]);
        $this->getJson('/api/v1/solicitante/reposiciones/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/solicitante/reposiciones/999')->assertNotFound();
    }

    public function test_inconsistent_historical_records_do_not_expose_another_applicants_sanction(): void
    {
        $this->withRole('solicitante', 25);
        $foreign = Sancion::factory()->create(['solicitante_id' => 26, 'descripcion' => 'Información privada']);
        $inconsistent = Reposicion::factory()->create(['sancion_id' => $foreign->id, 'solicitante_id' => 25]);

        $this->getJson('/api/v1/solicitante/reposiciones/'.$inconsistent->id)->assertNotFound()->assertJsonMissing(['descripcion' => 'Información privada']);
    }
}
