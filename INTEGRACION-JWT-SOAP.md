# Funcionamiento interno de la integración Laravel, JWT y SOAP

Fecha de revisión: 4 de noviembre de 2026.

## 1. Propósito y estado actual

La integración busca que un usuario inicie sesión mediante la API REST de Laravel, reciba un token JWT y lo utilice en las siguientes peticiones. Laravel verifica ese token y prepara las llamadas al servidor SOAP con una cabecera WS-Security. Ambos sistemas conocen una misma clave secreta para verificar la firma.

La parte de Laravel incluye el endpoint de login, la emisión y validación del JWT y la preparación del cliente SOAP autenticado. Esto se ha probado con respuestas SOAP simuladas. El recorrido completo contra el servidor real todavía está bloqueado: SOAP publica una operación de login cuyo método no está implementado, y Laravel aún no tiene los controladores de sanciones y reposiciones.

Este documento describe el código existente y señala expresamente los comportamientos previstos que todavía no están disponibles.

## 2. Responsabilidad de cada aplicación

| Aplicación | Responsabilidad | Datos que administra o debe administrar |
| --- | --- | --- |
| Microservicio Laravel | API REST, emisión del JWT, validación de peticiones y gestión de sanciones y reposiciones | Tablas locales de sanciones y reposiciones; la gestión HTTP de esos registros sigue pendiente |
| Prestamo-soap | Operaciones SOAP de equipos y préstamos y comprobación del token recibido | Equipos, solicitantes y préstamos en su propia base de datos |
| Cliente, por ejemplo Postman | Solicitar el login, conservar temporalmente el token y enviarlo en las siguientes peticiones | No necesita conocer la clave secreta compartida |

Las aplicaciones tienen bases de datos separadas. Los campos `prestamo_id`, `solicitante_id` y `equipo_id` de Laravel son referencias al sistema SOAP; no son claves foráneas entre ambas bases. Cuando se implemente el negocio, será necesario comprobar que esas referencias existen y que la operación está permitida.

Laravel no consulta directamente la base de usuarios SOAP ni crea una copia local de sus contraseñas. El diseño actual delega la comprobación inicial de credenciales en la operación SOAP `login`.

## 3. Qué es el JWT y por qué sirve la clave compartida

Un JWT firmado tiene tres partes: cabecera, contenido y firma. La cabecera indica el algoritmo; el contenido lleva los datos de identidad y vigencia; la firma permite comprobar que el token no se modificó y que fue generado por alguien que conoce la clave.

Se usa `HS256`, una firma HMAC con SHA-256. Es simétrica porque la misma clave permite generar y verificar la firma. No es cifrado: el contenido del token se puede leer. Por esa razón no se incluye la contraseña.

La clave compartida identifica la confianza entre servicios. No es la contraseña del usuario y no se envía en las peticiones. Cualquiera que la conozca podría emitir tokens, por lo que debe mantenerse fuera del repositorio y del cliente.

El payload emitido por Laravel contiene:

| Campo | Significado en esta implementación |
| --- | --- |
| `sub` | Nombre de usuario autenticado; actualmente no es un ID numérico |
| `username` | Repite el nombre de usuario para facilitar compatibilidad |
| `iss` | Emisor; por defecto `sanciones` |
| `aud` | Destinatario; por defecto `prestamo-soap` |
| `iat` | Momento de emisión, en segundos Unix |
| `exp` | Momento de vencimiento, calculado como `iat + JWT_TTL` |

La biblioteca utilizada en Laravel es `firebase/php-jwt`, instalada mediante Composer y fijada en `composer.lock`.

## 4. Archivos integrados en Laravel

| Archivo | Función |
| --- | --- |
| `bootstrap/app.php` | Carga `routes/api.php` y configura respuestas JSON para errores de API |
| `routes/api.php` | Declara el login y aplica el middleware JWT a las rutas de dominio |
| `app/Http/Controllers/Api/LoginController.php` | Valida la entrada, solicita autenticación a SOAP y devuelve un JWT si hay éxito |
| `app/Services/JwtService.php` | Genera el JWT firmado y comprueba la configuración mínima de clave y duración |
| `app/Http/Middleware/AuthenticateJwt.php` | Rechaza tokens inválidos y prepara el cliente SOAP con la identidad verificada |
| `app/Services/SoapLoanService.php` | Construye el cliente SOAP y la cabecera WS-Security; ejecuta las operaciones remotas |
| `config/jwt.php` | Obtiene las variables de configuración del JWT |
| `config/soap.php` | Obtiene WSDL, endpoint y tiempo de espera de SOAP |
| `.env.example` | Declara las variables necesarias sin publicar secretos reales |
| `tests/Feature/JwtLoginTest.php` | Prueba login y middleware con respuestas SOAP simuladas |

## 5. Recorrido interno del login

### Entrada HTTP

El cliente envía una petición como esta a Laravel:

```http
POST /api/v1/login
Content-Type: application/json
Accept: application/json

{
  "username": "usuario_de_prueba",
  "password": "contraseña_del_usuario"
}
```

La ruta combina el prefijo `/api` configurado por Laravel y el grupo `/v1` de `routes/api.php`. No requiere JWT porque precisamente se utiliza para obtenerlo. Tiene un límite de cinco peticiones por minuto mediante `throttle:5,1`, con la identificación predeterminada del limitador de Laravel.

### Validación y autenticación remota

Laravel resuelve `LoginController`, `SoapLoanService` y `JwtService` a través de su contenedor de dependencias. Crear el cliente de servicio no abre inmediatamente una conexión SOAP: la conexión se realiza cuando se solicita una operación.

El controlador exige `username` y `password` de tipo texto, con máximos de 255 y 1024 caracteres respectivamente. Si faltan o no cumplen las reglas, devuelve `422` y los errores de validación.

Después ejecuta `SoapLoanService::login(username, password)`. El servicio obtiene el contrato WSDL y llama a la operación remota `login`, pasando ambos argumentos en el orden declarado por SOAP. Esta llamada inicial no lleva un JWT emitido por Laravel.

El controlador espera un objeto con un campo `exito` booleano. No utiliza el token, nombre ni vencimiento que pudiera devolver SOAP: su decisión actual depende de `exito`.

| Situación | Respuesta de Laravel |
| --- | --- |
| Datos de entrada inválidos | `422` |
| SOAP devuelve `exito: false` | `401`, credenciales incorrectas |
| SOAP falla o no se puede invocar | `502`, no se pudo autenticar con SOAP |
| La respuesta no contiene `exito` booleano | `502`, respuesta SOAP inválida |
| SOAP devuelve `exito: true` y la configuración JWT es válida | `200`, con un token nuevo |
| Se supera el límite de intentos | `429` |

Una configuración JWT inválida provoca una excepción de configuración; no se presenta como credenciales incorrectas.

### Emisión del token

`JwtService::issue()` exige un secreto de al menos 32 bytes y una duración positiva. Construye el payload y lo firma con `HS256`. El resultado HTTP tiene esta forma ilustrativa:

```json
{
  "token": "JWT_FIRMADO",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

Se añade `Cache-Control: no-store` para indicar que esa respuesta no debe almacenarse en caché.

El usuario no necesita iniciar sesión de nuevo para cada operación mientras el token sea válido. Sí necesita autenticación inicial: el diseño actual todavía depende de SOAP durante el login.

## 6. Recorrido de una petición protegida

El cliente presenta el token así:

```http
Authorization: Bearer JWT_FIRMADO
```

La implementación actual utiliza ese header. No lee `X-WSS-Username` ni `X-WSS-Token` de la petición REST.

Antes de ejecutar la acción protegida, `AuthenticateJwt`:

1. Obtiene el token Bearer y comprueba que la clave local tiene la longitud mínima.
2. Decodifica el JWT y verifica su firma usando exclusivamente `HS256`.
3. Delega en la biblioteca la comprobación de vencimiento y restricciones temporales presentes.
4. Exige `exp`, `iat` y un `sub` de tipo texto no vacío.
5. Comprueba que `iss` y `aud` coincidan exactamente con la configuración local.
6. Devuelve `401` si el token falta o es inválido.
7. Guarda la identidad en el atributo de petición `jwt_subject`.
8. Registra una instancia de `SoapLoanService` con alcance de petición, construida con el `sub` validado y el JWT original.

El controlador que consuma ese servicio recibirá un cliente preparado para usar la identidad autenticada. No necesita confiar en un nombre de usuario libre enviado por el cliente.

Esto autentica la petición, pero no decide sus permisos. Aún faltan las reglas de autorización para determinar quién puede sancionar, consultar información ajena o completar reposiciones.

## 7. Cómo se construye y envía WS-Security

`SoapLoanService` utiliza `SoapClient`, `SoapHeader` y `SoapVar` de PHP. Al ejecutar la primera operación, configura el WSDL, el endpoint, las excepciones y un tiempo de espera de diez segundos para conexión y contexto HTTP. Desactiva la caché WSDL y reutiliza el cliente durante la vida de esa instancia.

Si tiene un token, agrega una cabecera con esta estructura conceptual; PHP puede elegir otros prefijos XML equivalentes:

```xml
<soap:Header>
  <wsse:Security>
    <wsse:UsernameToken>
      <wsse:Username>usuario_autenticado</wsse:Username>
      <wsse:Password>JWT_FIRMADO</wsse:Password>
    </wsse:UsernameToken>
  </wsse:Security>
</soap:Header>
```

El namespace WS-Security utilizado es:

```text
http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd
```

El nodo `Password` contiene el JWT, no la contraseña del usuario ni el secreto compartido. Esta es una convención de estas aplicaciones sobre una cabecera UsernameToken; no incorpora por sí misma cifrado del mensaje, firma XML ni un perfil completo de seguridad WS-Security. El transporte de credenciales y tokens debe protegerse con HTTPS cuando corresponda.

El cliente Laravel dispone de estos métodos:

| Método local | Operación SOAP |
| --- | --- |
| `login()` | `login` |
| `consultarPrestamos()` | `consultarPrestamos` |
| `consultarEquipo()` | `consultarEquipo` |
| `actualizarEstadoEquipo()` | `actualizarEquipo` |

No existe una operación SOAP de registrar sanciones. Las sanciones pertenecen a la base de Laravel. Una futura acción podría consultar datos remotos antes de guardarlas localmente.

## 8. Qué hace SOAP al recibir el mensaje

`Prestamo-soap/servicio/server.php` carga NuSOAP, conecta con MySQL, registra las operaciones y delega su ejecución a los servicios de autenticación, equipos y préstamos.

`AuthService::validarWssSecurity()` interpreta el XML con DOM y XPath, localiza `Security/UsernameToken/Password` en el namespace WS-Security y extrae el JWT. Después llama a `JWT::decode()` con el secreto y `HS256`. Devuelve los claims si tiene éxito y `null` si la validación falla.

Actualmente SOAP no aplica el mismo contrato estricto de Laravel: no exige expresamente emisor, destinatario ni todos los campos temporales, y no compara el `Username` del XML con la identidad del token. El punto de entrada utiliza el resultado como comprobación de acceso, sin pasar esa identidad a reglas de permisos de cada operación.

El middleware antiguo `middleware/AuthMiddleware.php`, basado en consultar `token_acceso` en la base de datos, no está conectado al punto de entrada revisado. No debe confundirse con el mecanismo JWT/WS-Security que sí usa `server.php`.

Aunque compartir algoritmo y secreto permite compatibilidad criptográfica, SOAP no implementa actualmente la emisión de tokens en un método de login. Tampoco se ha comprobado aceptación bidireccional de tokens reales.

## 9. Configuración y contenedores

| Variable | Función |
| --- | --- |
| `SOAP_WSDL_URL` | URL del contrato SOAP, normalmente terminada en `?wsdl` |
| `SOAP_SERVICE_URL` | URL que recibe las llamadas SOAP |
| `JWT_SECRET` | Clave compartida; debe coincidir exactamente en ambos servicios |
| `JWT_TTL` | Duración del token en segundos; `3600` equivale a una hora |
| `JWT_ISSUER` | Emisor esperado por Laravel |
| `JWT_AUDIENCE` | Destinatario esperado por Laravel |

Laravel lee estas variables desde archivos de `config/`, y el resto del código utiliza `config()`. Esto permite trabajar con configuración cacheada. Tras cambiar `.env`, puede limpiarse la caché con `php artisan config:clear` o su equivalente dentro de Sail.

Las variables vacías en `.env.example` son marcadores para cada instalación. El secreto real no debe copiarse a ese archivo ni al documento.

Laravel usa Sail con PHP 8.5 y MySQL 8.4; también incluye phpMyAdmin. SOAP dispone de un contenedor PHP 8.3/Apache con NuSOAP y otro MySQL 8.4. NuSOAP implementa el servidor en PHP; Laravel sí necesita la extensión nativa SOAP para su `SoapClient`.

Los proyectos no comparten actualmente una red Docker. La configuración local de Laravel apunta a `host.docker.internal:8088`, mientras SOAP publica el puerto solo en `127.0.0.1`. La conectividad desde el contenedor debe resolverse y verificarse; no basta con que el WSDL abra desde el equipo anfitrión.

## 10. Persistencia de sanciones y reposiciones

La migración de sanciones define préstamo, solicitante, tipo, descripción, días, multa, estado y fechas de vigencia. Los tipos incluyen entrega tardía, daño y pérdida.

La migración de reposiciones define la sanción relacionada, equipo, solicitante, código del equipo nuevo, observaciones, costo, estado y fecha. La relación local usa una clave foránea con eliminación en cascada: borrar una sanción elimina sus reposiciones asociadas.

Los modelos representan una relación de una sanción a muchas reposiciones. Todavía no hay lógica HTTP que calcule multas o fechas, cree esos registros o sincronice cambios de equipos con SOAP. Tampoco hay una transacción distribuida entre las dos bases.

## 11. Pruebas y alcance de la verificación

En la revisión del 4 de noviembre de 2026, `php artisan test --compact` completó nueve pruebas con veinticinco aserciones. Siete pruebas corresponden a JWT/login y dos son los ejemplos iniciales de Laravel.

Se cubren entrada obligatoria, emisión del JWT después de un éxito SOAP simulado, credenciales incorrectas, excepción SOAP, secreto corto, token ausente o alterado, vencimiento y emisor/destinatario incorrectos.

Las pruebas protegidas usan una ruta temporal creada dentro del test. Por tanto, no prueban los controladores de sanciones y reposiciones ni demuestran que esas rutas de negocio funcionen.

No se ha probado el intercambio SOAP real, la serialización WS-Security completa, el acceso a usuarios reales, los permisos ni la escritura del negocio en ambas aplicaciones. El PHP local revisado carece de `soap` y `pdo_mysql`; esa observación no describe necesariamente las extensiones de los contenedores en ejecución.

## 12. Fallas pendientes y su efecto

| Hallazgo | Efecto |
| --- | --- |
| SOAP detecta login buscando la palabra `login` en todo el XML | Puede omitir autenticación para una operación distinta; necesita identificar exactamente la operación del cuerpo SOAP |
| `AuthService::login()` no existe | Impide la autenticación real solicitada por Laravel |
| El esquema SQL disponible no incluye `username` y `password_hash` | No proporciona la estructura de credenciales usada por los ejemplos; la base real aún debe confirmarse |
| Faltan `SancionController` y `ReposicionController` | Las rutas de negocio no pueden ejecutar sus acciones; `route:list` falla al inspeccionarlas |
| `consultarPrestamos` llama a `$ps->consultar()`, pero existe `listar()` | La operación de consulta de préstamos falla |
| WSDL declara `datos` como `RespuestaLogin` para todas las operaciones | No describe equipos ni listas de préstamos y puede impedir una serialización correcta |
| SOAP mantiene el secreto en código y aplica menos comprobaciones de claims | Dificulta administrar el secreto y deja políticas de aceptación distintas entre sistemas |
| No hay autorización por operación | Un token válido no demuestra permiso para todas las acciones |
| La disponibilidad del equipo se consulta antes de la transacción, sin bloqueo | Dos solicitudes simultáneas podrían registrar préstamos para el mismo equipo |
| El login Laravel captura errores SOAP sin registrarlos | El cliente recibe un error controlado, pero se pierde información útil para diagnóstico |
| La red entre proyectos no está resuelta | Puede impedir que Laravel alcance SOAP aunque ambos contenedores estén activos |

## 13. Guion para explicar el funcionamiento

“Tenemos dos aplicaciones con responsabilidades separadas. El sistema SOAP administra equipos, solicitantes y préstamos. El microservicio Laravel expone una API REST y está preparado para administrar sanciones y reposiciones relacionadas con esos préstamos.

El cliente solicita el login a Laravel. Laravel envía las credenciales a la operación de autenticación SOAP y, cuando recibe confirmación, genera un JWT firmado con HS256. Ese token contiene la identidad y su fecha de vencimiento, sin incluir la contraseña.

En las siguientes peticiones el cliente presenta el JWT como Bearer. Un middleware de Laravel verifica su firma y sus datos antes de permitir continuar. Después prepara un cliente SOAP que incorpora el mismo JWT en una cabecera WS-Security.

SOAP puede verificar la firma porque conoce el mismo secreto. Así se evita repetir el envío de la contraseña en cada operación. La clave compartida permite verificar autenticidad; los permisos de cada acción deben comprobarse por separado.

La generación y validación del token ya se probaron en Laravel con SOAP simulado. Todavía debemos completar el login SOAP, corregir su protección y contrato, resolver la red e implementar los controladores de negocio para demostrar el recorrido completo.”
