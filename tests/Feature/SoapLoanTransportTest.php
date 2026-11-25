<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use App\Services\SoapLoanService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[RequiresPhpExtension('soap')]
class SoapLoanTransportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ?Process $server = null;

    private string $capture;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->capture = tempnam(sys_get_temp_dir(), 'sanciones-soap-');
        $this->server = new Process([PHP_BINARY, '-S', $address, base_path('tests/soap-router.php')], base_path(), ['SOAP_TEST_CAPTURE' => $this->capture]);
        $this->server->start();

        $ready = false;
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $probe = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.05);
            if ($probe !== false) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(50000);
        }
        $this->assertTrue($ready, $this->server->getErrorOutput());
        config(['soap.wsdl' => base_path('tests/soap.wsdl'), 'soap.endpoint' => 'http://'.$address, 'jwt.secret' => str_repeat('s', 32)]);
    }

    protected function tearDown(): void
    {
        $this->server?->stop(0.1);
        if (isset($this->capture) && is_file($this->capture)) {
            unlink($this->capture);
        }
        parent::tearDown();
    }

    private function capturedXml(): DOMXPath
    {
        $xml = new DOMDocument;
        $this->assertTrue($xml->loadXML(file_get_contents($this->capture)));
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('wsse', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd');

        return $xpath;
    }

    public function test_native_soap_sends_the_verified_jwt_in_ws_security_and_reads_the_loan_list(): void
    {
        $token = app(JwtService::class)->issue('ana<&');
        $soap = new SoapLoanService('ana<&', $token);

        $this->assertSame([['id' => 10, 'solicitante_id' => 25]], $soap->prestamos());
        $xml = $this->capturedXml();
        $this->assertSame('ana<&', $xml->evaluate('string(/soap:Envelope/soap:Header/wsse:Security/wsse:UsernameToken/wsse:Username)'));
        $this->assertSame($token, $xml->evaluate('string(/soap:Envelope/soap:Header/wsse:Security/wsse:UsernameToken/wsse:Password)'));
        $this->assertSame('consultarPrestamos', $xml->evaluate('local-name(/soap:Envelope/soap:Body/*[1])'));
    }

    public function test_login_uses_positional_credentials_without_a_ws_security_header(): void
    {
        $soap = new SoapLoanService;

        $this->assertTrue($soap->login('ana<&', 'password<&')->exito);
        $xml = $this->capturedXml();
        $this->assertSame(0, $xml->query('/soap:Envelope/soap:Header/wsse:Security')->length);
        $this->assertSame('ana<&', $xml->evaluate('string(/soap:Envelope/soap:Body/*[local-name()="login"]/username)'));
        $this->assertSame('password<&', $xml->evaluate('string(/soap:Envelope/soap:Body/*[local-name()="login"]/password)'));
    }

    public function test_existing_equipment_calls_keep_their_arguments_and_authenticated_header(): void
    {
        $token = app(JwtService::class)->issue('administrador');
        $soap = new SoapLoanService('administrador', $token);

        $this->assertTrue($soap->consultarEquipo(8)->exito);
        $this->assertTrue($soap->actualizarEstadoEquipo(8, 'Equipo<&', 'Laptop', 'Disponible')->exito);
        $xml = $this->capturedXml();
        $this->assertSame('actualizarEquipo', $xml->evaluate('local-name(/soap:Envelope/soap:Body/*[1])'));
        $this->assertSame('8', $xml->evaluate('string(/soap:Envelope/soap:Body/*[1]/id)'));
        $this->assertSame('Equipo<&', $xml->evaluate('string(/soap:Envelope/soap:Body/*[1]/nombre)'));
        $this->assertSame('Laptop', $xml->evaluate('string(/soap:Envelope/soap:Body/*[1]/tipo)'));
        $this->assertSame('Disponible', $xml->evaluate('string(/soap:Envelope/soap:Body/*[1]/estado)'));
        $this->assertSame($token, $xml->evaluate('string(/soap:Envelope/soap:Header/wsse:Security/wsse:UsernameToken/wsse:Password)'));
    }

    public function test_rest_login_and_sanction_use_native_soap_end_to_end(): void
    {
        User::factory()->create(['soap_username' => 'ana<&', 'role' => 'encargado_laboratorio']);

        $login = $this->postJson('/api/v1/login', ['username' => 'ana<&', 'password' => 'password<&'])->assertOk();
        $token = $login->json('token');
        $this->withToken($token)->postJson('/api/v1/laboratorio/sanciones', [
            'prestamo_id' => 10,
            'solicitante_id' => 25,
            'tipo' => 'Dano',
            'descripcion' => 'Prueba con transporte SOAP real local',
        ])->assertCreated()->assertJsonPath('datos.estado', 'Activa');

        $this->assertDatabaseHas('sanciones', ['prestamo_id' => 10, 'solicitante_id' => 25, 'estado' => 'Activa']);
        $xml = $this->capturedXml();
        $this->assertSame($token, $xml->evaluate('string(/soap:Envelope/soap:Header/wsse:Security/wsse:UsernameToken/wsse:Password)'));
    }
}
