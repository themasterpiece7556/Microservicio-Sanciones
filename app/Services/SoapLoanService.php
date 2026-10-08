<?php

namespace App\Services;

use SoapClient;
use SoapHeader;
use SoapVar;
use stdClass;

class SoapLoanService
{
    protected SoapClient $client;

    public function __construct(string $username, string $token)
    {
        $wsdl = env('SOAP_WSDL_URL');
        $endpoint = env('SOAP_SERVICE_URL');

        $this->client = new SoapClient($wsdl, [
            'location'   => $endpoint,
            'trace'      => 1,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);

        $this->attachWssHeader($username, $token);
    }

    /**
     * Construye y adjunta el encabezado WS-Security XML
     */
    private function attachWssHeader(string $username, string $token): void
    {
        $wssNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

        $usernameNode = new SoapVar($username, XSD_STRING, null, null, 'Username', $wssNs);
        $passwordNode = new SoapVar($token, XSD_STRING, null, null, 'Password', $wssNs);

        $usernameToken = new stdClass();
        $usernameToken->Username = $usernameNode;
        $usernameToken->Password = $passwordNode;

        $usernameTokenNode = new SoapVar($usernameToken, SOAP_ENC_OBJECT, null, null, 'UsernameToken', $wssNs);

        $security = new stdClass();
        $security->UsernameToken = $usernameTokenNode;

        $securityNode = new SoapVar($security, SOAP_ENC_OBJECT, null, null, 'Security', $wssNs);

        $header = new SoapHeader($wssNs, 'Security', $securityNode);
        $this->client->__setSoapHeaders([$header]);
    }

    public function consultarPrestamos()
    {
        return $this->client->__soapCall('consultarPrestamos', []);
    }

    public function actualizarEstadoEquipo(int $equipoId, string $nombre, string $tipo, string $estado)
    {
        return $this->client->__soapCall('actualizarEquipo', [
            'id'     => $equipoId,
            'nombre' => $nombre,
            'tipo'   => $tipo,
            'estado' => $estado
        ]);
    }
}