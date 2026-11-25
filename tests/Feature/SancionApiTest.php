<?php

namespace Tests\Feature;

use App\Models\Sancion;
use App\Services\SoapLoanService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SancionApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function mockLoans(array $loans): void
    {
        $this->partialMock(SoapLoanService::class)->shouldReceive('consultarPrestamos')->once()->andReturn((object) ['exito' => true, 'datos' => $loans]);
    }

    private function payload(): array
    {
        return ['prestamo_id' => 10, 'solicitante_id' => 25, 'tipo' => 'Dano', 'descripcion' => 'Equipo golpeado', 'dias_sancion' => 5, 'monto_multa' => '40000.00'];
    }

    public function test_laboratory_creates_a_sanction_for_a_confirmed_loan(): void
    {
        $this->withRole('encargado_laboratorio');
        $this->travelTo('2026-11-24 12:00:00');
        $this->mockLoans([(object) ['id' => 10, 'solicitante_id' => 25]]);

        $this->postJson('/api/v1/sanciones', $this->payload())->assertCreated()->assertJsonPath('datos.estado', 'Activa');

        $this->assertDatabaseHas('sanciones', ['prestamo_id' => 10, 'solicitante_id' => 25, 'monto_multa' => 40000, 'fecha_inicio' => '2026-11-24 12:00:00', 'fecha_fin' => '2026-11-29 12:00:00']);
    }

    public function test_zero_days_has_no_end_date_and_ignores_client_state(): void
    {
        $this->withRole('administrador');
        $this->mockLoans([['id' => 10, 'solicitante_id' => 25]]);

        $this->postJson('/api/v1/sanciones', array_replace($this->payload(), ['dias_sancion' => 0, 'estado' => 'Cancelada']))->assertCreated()->assertJsonPath('datos.fecha_fin', null)->assertJsonPath('datos.estado', 'Activa');
        $this->assertDatabaseCount('sanciones', 1);
    }

    public function test_invalid_values_do_not_reach_soap_or_persist(): void
    {
        $this->withRole('administrador');
        $this->mock(SoapLoanService::class)->shouldNotReceive('consultarPrestamos');

        foreach ([['dias_sancion' => -1], ['monto_multa' => -1], ['monto_multa' => 100000000], ['tipo' => 'otro'], ['prestamo_id' => 0]] as $invalid) {
            $this->postJson('/api/v1/sanciones', array_replace($this->payload(), $invalid))->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        }
        $this->assertDatabaseCount('sanciones', 0);
    }

    public function test_loan_must_belong_to_the_requested_applicant(): void
    {
        $this->withRole('administrador');
        $this->mockLoans([['id' => 10, 'solicitante_id' => 999]]);

        $this->postJson('/api/v1/sanciones', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('prestamo_id');
        $this->assertDatabaseCount('sanciones', 0);
    }

    public function test_soap_failures_do_not_create_a_sanction(): void
    {
        $this->withRole('administrador');
        $this->partialMock(SoapLoanService::class)->shouldReceive('consultarPrestamos')->once()->andThrow(new \RuntimeException('upstream failure'));

        $this->postJson('/api/v1/sanciones', $this->payload())->assertStatus(502)->assertJsonMissingPath('exception');
        $this->assertDatabaseCount('sanciones', 0);
    }

    public function test_applicant_cannot_read_another_applicant_or_trigger_soap(): void
    {
        $this->withRole('solicitante', 25);
        $this->mock(SoapLoanService::class)->shouldNotReceive('consultarPrestamos');

        $this->getJson('/api/v1/solicitantes/26/sanciones')->assertNotFound();
    }

    public function test_applicant_receives_only_their_sanctions_and_filtered_loans(): void
    {
        $this->withRole('solicitante', 25);
        $own = Sancion::factory()->create(['solicitante_id' => 25]);
        Sancion::factory()->create(['solicitante_id' => 26]);
        $this->mockLoans([['id' => 10, 'solicitante_id' => 25, 'solicitante_nombre' => 'Ana'], ['id' => 11, 'solicitante_id' => 26, 'solicitante_nombre' => 'Secreto']]);

        $this->getJson('/api/v1/solicitantes/25/sanciones')->assertOk()->assertJsonCount(1, 'datos.sanciones')->assertJsonPath('datos.sanciones.0.id', $own->id)->assertJsonCount(1, 'datos.prestamos_soap')->assertJsonMissing(['solicitante_nombre' => 'Secreto']);
    }

    public function test_malformed_soap_responses_fail_closed(): void
    {
        $this->withRole('administrador');
        $this->partialMock(SoapLoanService::class)->shouldReceive('consultarPrestamos')->andReturn((object) ['exito' => true, 'datos' => (object) ['token' => 'wrong-contract']]);

        $this->postJson('/api/v1/sanciones', $this->payload())->assertStatus(502);
        $this->assertDatabaseCount('sanciones', 0);
    }

    public function test_days_cannot_produce_a_date_outside_the_mysql_timestamp_range(): void
    {
        $this->withRole('administrador');
        $this->travelTo('2026-11-24 12:00:00');
        $this->mock(SoapLoanService::class)->shouldNotReceive('consultarPrestamos');

        $this->postJson('/api/v1/sanciones', array_replace($this->payload(), ['dias_sancion' => 5000]))->assertUnprocessable()->assertJsonValidationErrors('dias_sancion');
        $this->assertDatabaseCount('sanciones', 0);
    }

    public function test_sanction_can_end_at_the_last_mysql_timestamp(): void
    {
        $this->withRole('administrador');
        $this->travelTo('2038-01-18 03:14:07');
        $this->mockLoans([['id' => 10, 'solicitante_id' => 25]]);

        $this->postJson('/api/v1/sanciones', array_replace($this->payload(), ['dias_sancion' => 1]))->assertCreated();
        $this->assertDatabaseHas('sanciones', ['fecha_fin' => '2038-01-19 03:14:07']);
    }
}
