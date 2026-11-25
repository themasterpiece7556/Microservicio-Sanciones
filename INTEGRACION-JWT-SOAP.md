# Integración de JWT, roles y SOAP

Actualización: 24 de noviembre de 2026.

## Estado

Laravel implementa login SOAP, emisión y validación JWT, vinculación de usuarios locales, cuatro middlewares de perfil y operaciones locales de sanciones y reposiciones. Las pruebas automatizadas ejercitan las rutas reales con SQLite en memoria y respuestas SOAP simuladas. La configuración de pruebas impone SQLite en memoria también cuando el proceso hereda `DB_URL`, `DB_CONNECTION` o `DB_DATABASE`.

Docker está activo. En el contenedor Laravel, PHP 8.5.12 dispone de `soap`, `pdo_mysql` y `pdo_sqlite`. La serialización WS-Security y el flujo REST → SOAP → persistencia se verificaron con SoapClient/SoapServer nativos contra un servidor temporal controlado, sin mocks del cliente SOAP. La consulta al servicio SOAP de negocio real ya pasa después de corregir el despacho, el contrato WSDL y la validación de Envelope. No se ejecutaron migraciones ni seeders sobre la base MySQL de trabajo.

El servicio SOAP corregido despacha `consultarPrestamos()` a `PrestamoService::listar()` y devuelve `RespuestaPrestamos` con `datos` de tipo `ListaPrestamos`. El login conserva su tipo específico y las otras operaciones tienen una envoltura general con `datos` de tipo `xsd:anyType`. La excepción de autenticación solo admite un Envelope no ambiguo con un único Body y la operación login correcta; tanto incluir la palabra login en argumentos como añadir un Body de otro namespace se rechazan antes del despacho. `JWT_SECRET` se obtiene del entorno, sin respaldo fijo. La clave pública anterior se reemplazó en ambos entornos locales, en archivos `.env` excluidos de Git.

## Identidad y configuración

- `POST /api/v1/login` recibe `username` y `password`, valida las credenciales con SOAP y exige un usuario local cuyo `soap_username` coincida exactamente.
- La respuesta conserva `token`, `token_type: Bearer` y `expires_in`, e incorpora un resumen de `user` con ID local, nombre y rol. No devuelve contraseña ni secreto.
- JWT usa HS256; sus claims son `sub`, `username`, `iss`, `aud`, `iat` y `exp`. `sub` es el username SOAP, no el ID local.
- En cada petición se vuelve a consultar el usuario local. Cambiar su rol o eliminarlo afecta la siguiente petición aunque el JWT no haya vencido.
- `User.solicitante_id` vincula al solicitante remoto. No hay claves foráneas entre bases ni equivalencia implícita entre los dos IDs.
- Las políticas y `$request->user()` utilizan la identidad validada. El guard `jwt` se reutiliza y olvida su usuario al iniciar cada petición; una sesión web no sustituye al Bearer.

| Variable | Uso |
|---|---|
| `SOAP_WSDL_URL` | Contrato WSDL accesible desde el proceso PHP |
| `SOAP_SERVICE_URL` | Endpoint SOAP accesible desde ese mismo proceso |
| `JWT_SECRET` | Secreto compartido, aleatorio, de al menos 32 bytes |
| `JWT_TTL` | Vigencia positiva en segundos; por defecto 3600 |
| `JWT_ISSUER` | Emisor; por defecto `sanciones` |
| `JWT_AUDIENCE` | Destinatario; por defecto `prestamo-soap` |

Mantener `JWT_SECRET` vacío en `.env.example`. Configurar el secreto real fuera de Git en ambas aplicaciones. Laravel utiliza `config()` para ser compatible con configuración cacheada.

La migración aditiva `add_soap_identity_to_users_table` incorpora `soap_username` nullable y único y `solicitante_id` nullable e indexado. Aplicarla en el entorno elegido después de revisar su conexión y permisos. No crea cuentas SOAP ni rellena vínculos existentes por inferencia.

`RoleUsersSeeder` prepara estos usernames locales de demostración: `administrador`, `encargado_laboratorio`, `almacenista` y `solicitante`. El username coincide con el rol por indicación de la evaluación, no por una regla general de autenticación. Las cuentas SOAP deben existir por separado con sus propias credenciales. El vínculo de solicitante requiere el ID remoto real; el seeder lo deja vacío hasta disponer de ese dato. El seeder usa contraseñas locales de demostración y no debe ejecutarse en producción.

## Rutas y perfiles

Solo `POST /api/v1/login` es público; conserva el límite de 5 intentos por minuto. El middleware global exige Bearer también en `/` y `/up`, según el alcance aprobado. Los middleware de perfil rechazan antes del controlador una operación no permitida.

| Perfil | Middleware | Rutas bajo `/api/v1` |
|---|---|---|
| Administrador | `IsAdministrator` | POST `admin/sanciones`, GET `admin/solicitantes/{id}/sanciones`, POST `admin/reposiciones`, GET `admin/reposiciones/{id}` |
| Encargado de laboratorio | `CanManageSanctions` | POST `laboratorio/sanciones`, GET `laboratorio/solicitantes/{id}/sanciones` |
| Almacenista | `CanProcessReplacements` | POST `almacen/reposiciones`, GET `almacen/reposiciones/{id}` |
| Solicitante | `CanViewSanctions` | GET `solicitante/solicitantes/{id}/sanciones`, GET `solicitante/reposiciones/{id}`; solo registros propios |

Se conservan POST `/api/v1/sanciones`, GET `/api/v1/solicitantes/{id}/sanciones` y POST `/api/v1/reposiciones`. `AuthorizeRestOperation` delega al middleware del rol y aplica la misma lista de métodos permitidos antes de llegar al controlador.

PUT `/api/v1/reposiciones/{id}/completar` permanece bloqueada: devuelve 401 sin Bearer válido y 403 para cualquier perfil autenticado. Completar reposiciones no está en los métodos autorizados de la evaluación.

Correspondencias de nombres: `registrarSancion` usa `SancionController::store`; `consultarSancionesSolicitante` usa `consultarPorSolicitante`; `registrarReposicion` usa `ReposicionController::store`; `consultarReposicion` usa `show`.

## Autorización y llamadas SOAP

Cada middleware compara manualmente `$request->user()->role` y comprueba la operación requerida. Un rol incorrecto recibe exactamente:

```json
{"error":"Permisos insuficientes para ejecutar este método"}
```

La respuesta tiene estado 403 y se genera antes del controlador, la validación del cuerpo o cualquier llamada SOAP. Un token ausente, alterado, vencido, con claims incorrectos o sin usuario vinculado devuelve 401. Un secreto ausente o inválido es un error de configuración del servidor.

Las políticas aplican además las restricciones sobre registros: el solicitante recibe 404 al consultar IDs ajenos y no puede provocar consultas remotas para ellos.

| Contexto | `consultarPrestamos()` |
|---|---|
| Administrador crea/consulta sanciones | Permitido y utilizado |
| Laboratorio crea/consulta sanciones | Permitido y utilizado |
| Solicitante consulta sus sanciones | Permitido y utilizado |
| Almacenista | Prohibido; sus operaciones son locales |
| Cualquier consulta o registro de reposición implementado | Local; no necesita consultar préstamos |

El cliente SOAP se resuelve usando los atributos de la petición validada, sin capturar el JWT de otro usuario. Construye `Security/UsernameToken` en el namespace WS-Security, con `Username` igual al sujeto y `Password` igual al Bearer. No admite `X-WSS-Username` ni `X-WSS-Token` como alternativa de identidad.

El contrato de préstamos esperado es `exito: true` y `datos` como lista de filas con `id` y `solicitante_id` positivos. Esta forma corresponde a `PrestamoService::listar()` y `Prestamo::listar()` y está publicada por el WSDL corregido; el cliente nativo la aceptó en la prueba contra el servicio real. Una respuesta de error o malformada produce 502 sin escritura local. Al responder consultas, solo se incluyen préstamos del solicitante consultado.

## Negocio

Las consultas de sanciones responden `exito` y `datos`, con `datos.sanciones` y `datos.prestamos_soap`.

Crear una sanción requiere préstamo y solicitante positivos, tipo `Entrega_Tardia`, `Dano` o `Perdida`, descripción, y opcionalmente días no negativos y multa no negativa dentro del rango decimal admitido. Se verifica en SOAP la relación préstamo/solicitante. Los días deben permitir una fecha final dentro del rango de `TIMESTAMP` de MySQL (hasta el 19 de enero de 2038 a las 03:14:07 UTC); se rechazan antes de llamar a SOAP cuando lo superan. El servidor asigna estado `Activa`, fecha inicial y fecha final si hay días; el cliente no puede imponer el estado.

Crear una reposición exige sanción local existente, solicitante coincidente, equipo positivo, costo válido y observaciones opcionales. Se guarda como `Pendiente`. No verifica el equipo remoto ni sincroniza cambios SOAP, porque el flujo permitido al almacenista es local.

Consultar una reposición incluye su sanción. El solicitante solo puede consultar registros cuya reposición y sanción asociada pertenecen a su ID remoto, incluso ante datos históricos inconsistentes. No se devuelve información de terceros al denegar acceso.

## Demostración y pruebas

En Postman usar una variable `base_url` resuelta para el entorno y añadir las rutas relativas anteriores. El login envía `username`/`password`, no el ejemplo `email` de la guía Sanctum. Guardar el `token` de la respuesta en una variable privada y usar Authorization de tipo Bearer. No guardar secretos ni tokens en el repositorio.

Agrupar peticiones en autenticación/401, rechazos por perfil/403 y operaciones permitidas/200–201. Incluir intentos por rutas compatibles y accesos a registros ajenos. Los headers `X-WSS-*` no son necesarios.

Comandos de verificación:

```bash
php artisan test --compact
php artisan route:list --path=api --except-vendor
vendor/bin/pint --dirty --format agent
composer validate --no-check-publish
git diff --check
```

Las pruebas cubren persistencia, filtrado, matriz de perfiles, no ejecución del controlador/SOAP ante 403, respuestas SOAP simuladas, limitación de login, claims JWT, cambio de rol y aislamiento entre peticiones.

## Resultado de pruebas en Docker

- Suite completa en PHP 8.5.12 con SOAP real activado: 155 pruebas aprobadas y 1.049 aserciones, sin omisiones. Sin activar el grupo remoto se ejecutan 151 pruebas y se omiten expresamente cuatro pruebas externas.
- Transporte nativo controlado: cuatro pruebas aprobadas, 29 aserciones, incluidas dentro de la suite anterior. Verifican XML y namespaces WS-Security, Bearer exacto, login sin cabecera de seguridad, argumentos de equipos y login/creación de sanción con SOAP nativo y base de pruebas.
- MySQL 8.4: 134 pruebas y 975 aserciones aprobadas sobre una base temporal exclusiva. Incluyeron todas las migraciones, los perfiles, el negocio y el límite TIMESTAMP. La base temporal fue eliminada después; no se usó la base de trabajo.
- HTTP real con Postman: 401 sin Bearer, 422 al omitir campos del login, 401 al enviar credenciales ficticias (recorrido Laravel → SOAP) y WSDL accesible con 200. Las ocho aserciones correspondientes pasaron. Postman CLI devuelve exit 1 para los HTTP 401/422 aunque sus aserciones de contrato pasen; esos códigos HTTP son el resultado esperado.
- Integración SOAP real: cuatro pruebas aprobadas. Cubren login negativo, consulta de préstamos con JWT compartido y lista tipada, rechazo de la palabra login en otra operación y rechazo de Body duplicado con namespace distinto.
- Prueba de autenticación del repositorio SOAP: login positivo, JWT aceptado por WS-Security, comparación exacta del username, credenciales inválidas y rechazo de secretos ausentes/cortos. Crea un usuario temporal y revierte su transacción.
- La base de trabajo conserva pendientes las migraciones `add_role_to_users_table` y `add_soap_identity_to_users_table`. Probarlas en la base temporal no las aplica al entorno de trabajo.

Las cuatro pruebas remotas de Laravel son de solo lectura, no crean cuentas ni datos de negocio y no imprimen el contenido de los préstamos. Se reproducen con:

```bash
docker compose exec -e RUN_LIVE_SOAP_TESTS=1 laravel.test vendor/bin/phpunit tests/Feature/LiveSoapContractTest.php
```

`tests/soap.wsdl` y `tests/soap-router.php` pertenecen exclusivamente al servidor temporal de pruebas; no sustituyen el contrato de `Prestamo-soap`. Las pruebas remotas se ejecutan contra el contenedor SOAP reconstruido con la corrección. Un login positivo REST con usuarios reales sigue requiriendo aplicar las dos migraciones pendientes y configurar sus vínculos locales; no se inventan ni se publican credenciales de esos usuarios.
