<?php

namespace App\Services;

use SoapClient;
use SoapHeader;
use SoapVar;
use stdClass;

class SoapLoanService
{
    protected SoapClient $client;

    public function __construct(private string $username = '', private string $token = '') {}

    private function connect(): void
    {
        if (isset($this->client)) {
            return;
        }

        if (! config('soap.wsdl') || ! config('soap.endpoint')) {
            throw new \LogicException('Configure SOAP_WSDL_URL y SOAP_SERVICE_URL.');
        }

        $this->client = new SoapClient(config('soap.wsdl'), [
            'location' => config('soap.endpoint'),
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'connection_timeout' => config('soap.timeout'),
            'stream_context' => stream_context_create(['http' => ['timeout' => config('soap.timeout')]]),
        ]);

        if ($this->token !== '') {
            $this->attachWssHeader($this->username, $this->token);
        }
    }

    public function login(string $username, string $password): object
    {
        $this->connect();

        return (object) $this->client->__soapCall('login', [$username, $password]);
    }

    private function attachWssHeader(string $username, string $token): void
    {
        $wssNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

        $usernameNode = new SoapVar($username, XSD_STRING, null, null, 'Username', $wssNs);
        $passwordNode = new SoapVar($token, XSD_STRING, null, null, 'Password', $wssNs);

        $usernameToken = new stdClass;
        $usernameToken->Username = $usernameNode;
        $usernameToken->Password = $passwordNode;

        $usernameTokenNode = new SoapVar($usernameToken, SOAP_ENC_OBJECT, null, null, 'UsernameToken', $wssNs);

        $security = new stdClass;
        $security->UsernameToken = $usernameTokenNode;

        $securityNode = new SoapVar($security, SOAP_ENC_OBJECT, null, null, 'Security', $wssNs);

        $header = new SoapHeader($wssNs, 'Security', $securityNode);
        $this->client->__setSoapHeaders([$header]);
    }

    public function consultarPrestamos()
    {
        $this->connect();

        return $this->client->__soapCall('consultarPrestamos', []);
    }

    public function consultarEquipo(int $equipoId)
    {
        $this->connect();

        return $this->client->__soapCall('consultarEquipo', ['id' => $equipoId]);
    }

    public function actualizarEstadoEquipo(int $equipoId, string $nombre, string $tipo, string $estado)
    {
        $this->connect();

        return $this->client->__soapCall('actualizarEquipo', [
            'id' => $equipoId,
            'nombre' => $nombre,
            'tipo' => $tipo,
            'estado' => $estado,
        ]);
    }
}
