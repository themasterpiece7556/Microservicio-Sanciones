<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SoapLoanService;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Exception;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $username = $request->username;
        $password = $request->password;

        try {
            // Instanciamos el servicio SOAP sin token (pasando valores vacíos o un helper de login)
            // Nota: Como SoapLoanService exige token en el constructor por defecto, 
            // podemos pasarle credenciales temporales o ajustar el constructor, 
            // pero para el login puro podemos instanciar un SoapClient nativo limpio:
            
            $wsdl = env('SOAP_WSDL_URL');
            $endpoint = env('SOAP_SERVICE_URL');
            
            $soapClient = new \SoapClient($wsdl, [
                'location'   => $endpoint,
                'trace'      => 1,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ]);

            // Llamamos a la función 'login' expuesta en el servidor SOAP de Laragon
            $respuestaSoap = $soapClient->__soapCall('login', [
                'username' => $username,
                'password' => $password
            ]);

            // NuSOAP suele devolver arrays o estructuras anidadas. Validamos el resultado:
            // (Dependiendo de cómo serialice NuSOAP tu RespuestaSOAP, puede venir como array o objeto)
            $resultado = is_object($respuestaSoap) ? (array)$respuestaSoap : $respuestaSoap;

            // Si el SOAP dice que falló la autenticación
            if (isset($resultado['exito']) && $resultado['exito'] == false) {
                return response()->json([
                    'exito'   => false,
                    'mensaje' => $resultado['mensaje'] ?? 'Credenciales inválidas en el servidor SOAP'
                ], 401);
            }

        } catch (Exception $e) {
            return response()->json([
                'exito'   => false,
                'mensaje' => 'No se pudo conectar con el servidor SOAP de Laragon: ' . $e->getMessage()
            ], 500);
        }

        // --- SI EL SOAP VALIDÓ CORRECTAMENTE, GENERAMOS EL JWT EN LARAVEL ---
        
        $secretKey = env('JWT_SECRET', 'ClaveSecretaSuperSeguraParaAmbosServicios2026!');
        $issuedAt = time();
        $expire = $issuedAt + 7200; // 2 horas de validez

        $payload = [
            'iat'      => $issuedAt,
            'exp'      => $expire,
            'username' => $username,
            'sub'      => $resultado['datos']['id'] ?? 1 
        ];

        $jwt = JWT::encode($payload, $secretKey, 'HS256');

        return response()->json([
            'exito'   => true,
            'mensaje' => 'Autenticación exitosa validada con SOAP y Token JWT generado',
            'datos'   => [
                'token'  => $jwt,
                'expira' => date('Y-m-d H:i:s', $expire)
            ]
        ]);
    }
}