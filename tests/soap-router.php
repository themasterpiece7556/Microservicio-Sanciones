<?php

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/xml');
    readfile(__DIR__.'/soap.wsdl');

    return;
}

file_put_contents(getenv('SOAP_TEST_CAPTURE'), file_get_contents('php://input'));

class SoapTransportFixture
{
    public function login(string $username, string $password): array
    {
        return ['exito' => $username === 'ana<&' && $password === 'password<&', 'datos' => []];
    }

    public function consultarPrestamos(): array
    {
        return ['exito' => true, 'datos' => [['id' => 10, 'solicitante_id' => 25]]];
    }

    public function consultarEquipo(int $id): array
    {
        return ['exito' => true, 'datos' => [['id' => $id, 'solicitante_id' => 25]]];
    }

    public function actualizarEquipo(int $id, string $nombre, string $tipo, string $estado): array
    {
        return ['exito' => true, 'datos' => [['id' => $id, 'solicitante_id' => 25]]];
    }
}

$server = new SoapServer(__DIR__.'/soap.wsdl', ['cache_wsdl' => WSDL_CACHE_NONE]);
$server->setClass(SoapTransportFixture::class);
$server->handle();
