<?php

namespace Tests\Feature;

use App\Services\JwtService;
use App\Services\SoapLoanService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\RequiresEnvironmentVariable;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('soap')]
#[RequiresEnvironmentVariable('RUN_LIVE_SOAP_TESTS', '1')]
class LiveSoapContractTest extends TestCase
{
    public function test_live_login_rejects_an_unknown_user_without_a_token(): void
    {
        $soap = new SoapLoanService;

        $response = $soap->login('codex_verificacion_usuario_inexistente', 'invalid-test-credential');

        $this->assertFalse($response->exito);
    }

    public function test_live_loan_endpoint_accepts_the_shared_jwt_and_returns_the_list_contract(): void
    {
        $token = app(JwtService::class)->issue('codex_verificacion');
        $soap = new SoapLoanService('codex_verificacion', $token);

        $loans = $soap->prestamos();

        $this->assertIsArray($loans);
        $this->assertTrue(array_is_list($loans));
    }

    public function test_login_text_inside_another_operation_does_not_bypass_authentication(): void
    {
        $xml = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:p="urn:PrestamoEquipos"><soap:Body><p:consultarPrestamos><nota>login</nota></p:consultarPrestamos></soap:Body></soap:Envelope>';

        $response = Http::timeout(10)->withBody($xml, 'text/xml')->post(config('soap.endpoint'));

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('Client.Authentication', $response->body());
    }

    public function test_additional_body_in_another_namespace_cannot_bypass_authentication(): void
    {
        $xml = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:p="urn:PrestamoEquipos" xmlns:x="urn:other"><soap:Body><p:login><username>unknown</username><password>invalid</password></p:login></soap:Body><x:Body><p:consultarPrestamos/></x:Body></soap:Envelope>';

        $response = Http::timeout(10)->withBody($xml, 'text/xml')->post(config('soap.endpoint'));

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('Client.Authentication', $response->body());
    }
}
