# Plan de implementación de roles, rutas y pruebas

Fecha: 24 de noviembre de 2026. Rama revisada: `alex-docker-phpmyadmin`.

Estado: ejecución local solicitada mediante `executing-plan`. Implementación y limpieza realizadas; revisión independiente y correcciones incorporadas. Sin migraciones de la base MySQL de trabajo. El usuario autorizó corregir SOAP y publicar en commits pequeños después de las pruebas. Serialización XML verificada en Docker con servidor controlado. La consulta al SOAP real y las regresiones de seguridad pasan tras la corrección autorizada; detalles en la documentación de integración.

> Para ejecutar: usar la skill `executing-plans` y avanzar tarea por tarea, con pruebas antes y después de cada cambio de comportamiento.

**Objetivo:** implementar los permisos de la guía sobre la API existente, completar sus controladores y retirar únicamente duplicados sin consumidores comprobados.

**Arquitectura propuesta:** conservar el login SOAP y el JWT HS256 existentes. Laravel administrará los roles y la vinculación de identidades; los cuatro middlewares comprobarán manualmente el rol y la operación antes de entrar al controlador. Las políticas añadirán comprobaciones de propiedad de registros, sin sustituir el rechazo por rol en middleware. SOAP recibirá exclusivamente las credenciales derivadas del Bearer validado.

**Tecnologías verificadas:** Laravel 13.35.0, `firebase/php-jwt` 7.2.1, PHPUnit 12.5.38 y Pint 1.32.1; entorno previsto PHP 8.5. No añadir dependencias.

**Referencia:** `/home/alexander/Descargas/_MConverter.eu_Guia_Implementacion_Roles_Middlewares_Pruebas.md`. Sus ejemplos se consideran material de referencia; no sustituyen las decisiones aprobadas en este plan ni las reglas del repositorio.

**Requisitos adicionales del usuario:** la matriz enviada después del plan es obligatoria. Cada middleware debe comprobar `$request->user()->role`; cualquier método no permitido se rechaza antes del controlador y de SOAP con HTTP 403 y exactamente `{"error":"Permisos insuficientes para ejecutar este método"}`. Toda ruta REST salvo login requiere Bearer válido y responde 401 si falta o es inválido.

## 1. Resultado de la revisión

- La API ya utiliza `/api/v1`, mediante `bootstrap/app.php` y `routes/api.php`.
- `POST /api/v1/login` usa `LoginController`, valida credenciales contra SOAP y emite JWT. Sanctum no está instalado.
- `AuthenticateJwt` verifica el token, prepara `SoapLoanService` y guarda `jwt_subject`, pero no establece el usuario que necesitan `$request->user()` y las políticas.
- Existen `IsAdministrator`, `CanManageSanctions`, `CanProcessReplacements` y `CanViewSanctions`, pero no están registrados ni asignados a rutas.
- Faltan `SancionController` y `ReposicionController`; por ello falla `php artisan route:list --path=api --except-vendor`.
- `User` tiene rol, pero no una vinculación explícita con el username SOAP ni con el solicitante remoto.
- `RoleUsersSeeder` existe y puede ejecutarse directamente, pero `DatabaseSeeder` no lo llama. Sus contraseñas locales no prueban credenciales válidas en SOAP.
- Hay 9 pruebas y 25 aserciones que pasan. El login usa SOAP simulado y las pruebas del middleware utilizan una ruta temporal; no acreditan el negocio completo.
- Boost devolvió un esquema MySQL sin tablas. Antes de preparar migraciones se debe comprobar la conexión efectiva y consultar nuevamente el esquema; las migraciones existentes son la referencia disponible, no prueba del estado real desplegado.

## 2. Decisiones propuestas para aprobar

1. **Conservar JWT y el login SOAP.** Migrar a Sanctum supondría instalar una dependencia, cambiar el login y gestionar por separado la autenticación SOAP. Este plan adapta la matriz de permisos de la guía al mecanismo actual.
2. **Conservar `/api/v1`.** Incorporar las rutas por perfil para reproducir la guía y mantener las cuatro rutas de negocio existentes como entradas compatibles, con los mismos controles por operación.
3. **Mantener roles locales.** Vincular explícitamente `User.soap_username` con el `sub` del JWT y `User.solicitante_id` con el solicitante remoto. No asumir que el ID de `users` coincide con el ID de SOAP, ni derivar usernames desde correos.
4. **No crear usuarios o privilegios desde una petición de login.** Solo usuarios previamente vinculados y con rol reconocido podrán usar la API. El cliente no podrá elegir su rol ni modificar sus vínculos mediante el login.
5. **Consultar únicamente registros propios para el solicitante.** Una petición por un solicitante ajeno responderá 404, sin consulta SOAP ni exposición de datos de terceros.
6. **No ampliar los permisos enumerados.** `completarReposicion` existe como referencia de ruta, pero no está entre los métodos autorizados enviados por el usuario. No implementarlo ni habilitarlo por inferencia: conservar temporalmente su ruta existente con 401 sin token y 403 para cualquier rol autenticado, antes del controlador. Habilitarla requiere confirmación específica de los roles permitidos.

## 3. Rutas objetivo

Las siguientes son rutas relativas de la API, no URLs de un servidor confirmado.

| Método | Ruta | Controlador / acción | Acceso |
|---|---|---|---|
| POST | `/api/v1/login` | `LoginController::__invoke` | Público; límite actual de 5 intentos/minuto |
| POST | `/api/v1/admin/sanciones` | `SancionController::store` | Administrador |
| GET | `/api/v1/admin/solicitantes/{id}/sanciones` | `SancionController::consultarPorSolicitante` | Administrador |
| POST | `/api/v1/admin/reposiciones` | `ReposicionController::store` | Administrador |
| GET | `/api/v1/admin/reposiciones/{id}` | `ReposicionController::show` | Administrador |
| POST | `/api/v1/laboratorio/sanciones` | `SancionController::store` | Encargado de laboratorio |
| GET | `/api/v1/laboratorio/solicitantes/{id}/sanciones` | `SancionController::consultarPorSolicitante` | Encargado de laboratorio |
| POST | `/api/v1/almacen/reposiciones` | `ReposicionController::store` | Almacenista |
| GET | `/api/v1/almacen/reposiciones/{id}` | `ReposicionController::show` | Almacenista |
| GET | `/api/v1/solicitante/solicitantes/{id}/sanciones` | `SancionController::consultarPorSolicitante` | Solicitante; solo su ID remoto |
| GET | `/api/v1/solicitante/reposiciones/{id}` | `ReposicionController::show` | Solicitante; solo sus registros |

Entradas compatibles: conservar `POST /api/v1/sanciones`, `GET /api/v1/solicitantes/{id}/sanciones` y `POST /api/v1/reposiciones`. Deben ejecutar las mismas acciones, con autorización de operación en middleware antes del controlador, sin redirecciones de peticiones con cuerpo y sin permitir eludir permisos. Conservar `PUT /api/v1/reposiciones/{id}/completar` solo como ruta explícitamente denegada según la decisión 6; sustituir la referencia a un método inexistente por una respuesta controlada, sin lógica de negocio.

En rutas por perfil, los cuatro middlewares conservan la comprobación exacta de su perfil. El administrador dispone del conjunto completo de operaciones bajo `/admin`; no obtiene acceso automático a las rutas exclusivas de otros perfiles.

## 4. Reglas de autorización y SOAP

| Username previsto de demostración | Rol local | Middleware | Métodos REST permitidos |
|---|---|---|---|
| `administrador` | `administrador` | `IsAdministrator` | login, registrarSancion, consultarSancionesSolicitante, registrarReposicion, consultarReposicion |
| `encargado_laboratorio` | `encargado_laboratorio` | `CanManageSanctions` | login, registrarSancion, consultarSancionesSolicitante |
| `almacenista` | `almacenista` | `CanProcessReplacements` | login, registrarReposicion, consultarReposicion |
| `solicitante` | `solicitante` | `CanViewSanctions` | login, consultarSancionesSolicitante, consultarReposicion |

Estos usernames deben existir en SOAP para las pruebas reales; el seeder local no crea cuentas remotas. Los nombres funcionales de la evaluación se corresponden con las acciones existentes del proyecto: `registrarSancion` → `SancionController::store`, `consultarSancionesSolicitante` → `consultarPorSolicitante`, `registrarReposicion` → `ReposicionController::store`, `consultarReposicion` → `show`.

| Operación | Administrador | Laboratorio | Almacenista | Solicitante |
|---|---|---|---|---|
| Crear sanción | Sí | Sí | No | No |
| Consultar sanciones | Sí | Sí | No | Solo propias |
| Crear reposición | Sí | No | Sí | No |
| Consultar reposición | Sí | No | Sí | Solo propia |
| Completar reposición | Pendiente de autorización expresa | No | No | No |
| `consultarPrestamos()` al crear/consultar sanciones | Sí | Sí | No | Solo al consultar las propias |
| `consultarPrestamos()` en reposiciones | Permitido, pero no necesario en el flujo propuesto | No | No | No |

- Autenticar antes de comprobar roles, validar el negocio o llamar a SOAP.
- Token ausente, inválido, expirado o sin usuario local vinculado: 401. Configuración JWT inválida: error de configuración, no credenciales incorrectas.
- Usuario autenticado con rol desconocido o sin permiso: 403 con `{"error":"Permisos insuficientes para ejecutar este método"}`.
- Para el 401 de token, conservar el contrato JSON actual de `AuthenticateJwt`; adaptar las expectativas de la guía, en vez de cambiarlo sin necesidad.
- Cada middleware de perfil debe leer y comparar manualmente `$request->user()->role`, además de verificar que la operación esté en su lista permitida. No basta con ocultar rutas ni delegar este rechazo a una política dentro del controlador.
- Para las rutas compatibles sin perfil en la URL, añadir `AuthorizeRestOperation`: selecciona el middleware de perfil según el rol autenticado y le pasa la operación requerida. Cada middleware mantiene su comprobación manual de rol y su lista de operaciones; un rol desconocido o una operación fuera de la lista se rechazan con el mismo 403. No apilar middlewares de perfiles distintos, porque eso exigiría varios roles simultáneos.
- Políticas `SancionPolicy` y `ReposicionPolicy` para propiedad de registros y comprobaciones adicionales. Registrar un permiso específico para consultar préstamos según la operación REST; comprobarlo antes de cada llamada correspondiente. Las políticas no reemplazan la evaluación obligatoria de rol en middleware.
- Resolver el usuario local en cada petición para que un cambio de rol tenga efecto sin esperar a que venza el JWT. No confiar en roles proporcionados por el cliente.
- No aceptar `X-WSS-Username` ni `X-WSS-Token` como identidad alternativa. Reutilizar el cliente SOAP preparado a partir del Bearer validado.
- El almacenista no realizará llamadas SOAP en ninguno de los flujos propuestos. Consultar reposiciones será local también para el solicitante.
- Evitar devolver la lista completa de préstamos SOAP al solicitante: filtrar por su identidad y por el contrato de respuesta aprobado.

## 5. Tareas de implementación

### Tarea 1. Vincular identidad JWT con usuarios y roles

**Archivos:** modificar `app/Models/User.php`, `app/Http/Middleware/AuthenticateJwt.php`, `app/Http/Controllers/Api/LoginController.php`, `database/factories/UserFactory.php` y `database/seeders/RoleUsersSeeder.php`. Crear una migración `add_soap_identity_to_users_table` y `tests/Feature/JwtUserIdentityTest.php`; adaptar `tests/Feature/JwtLoginTest.php`.

**Contrato:** mantener `JwtService::issue(string $username): string`, `sub` y `username` como username SOAP, y los campos existentes de la respuesta de login. Añadir un resumen `user` con ID local, nombre y rol. Exponer un `User` resuelto exclusivamente para la petición autenticada y configurar ese mismo usuario para las políticas, sin sesión persistente.

- [x] Confirmar el esquema y crear mediante Artisan una migración aditiva: `soap_username` nullable y único; `solicitante_id` remoto nullable e indexado, sin FK entre bases. No rellenar vínculos de producción mediante suposiciones.
- [x] Escribir y ejecutar primero pruebas de usuario vinculado, no vinculado, rol desconocido, cambio de rol y ausencia de filtración de identidad entre peticiones consecutivas.
- [x] Implementar la resolución de usuario en `AuthenticateJwt` y el contexto de autorización por petición. Nunca asignar privilegios desde el payload del login.
- [x] Mantener la validación SOAP de contraseña. Si SOAP confirma pero no existe usuario local vinculado, responder 401 y no emitir token. Un rol desconocido no podrá obtener acceso: devolver 403 y no emitir token.
- [x] Ajustar datos de prueba con vínculos explícitos. Los fixtures SOAP se simulan; el seeder local no debe presentarse como creación de usuarios SOAP.
- [x] Ejecutar `php artisan test --compact tests/Feature/JwtLoginTest.php tests/Feature/JwtUserIdentityTest.php`.

**Condición previa para pruebas reales:** contar con usernames SOAP existentes y su correspondencia local, y con el ID remoto de cada solicitante. La falta de esos datos no se resuelve copiando el correo ni usando el ID local como reemplazo.

### Tarea 2. Completar las acciones de negocio y sus políticas

**Crear:** `app/Http/Controllers/Api/SancionController.php`, `app/Http/Controllers/Api/ReposicionController.php`, `app/Policies/SancionPolicy.php`, `app/Policies/ReposicionPolicy.php`, `app/Http/Requests/StoreSancionRequest.php` y `app/Http/Requests/StoreReposicionRequest.php`.

**Modificar:** `app/Models/Sancion.php`, `app/Models/Reposicion.php` y `app/Providers/AppServiceProvider.php` solo donde lo exijan tipos, casts, políticas y autorización SOAP. Crear factories mediante Artisan para ambos modelos y pruebas `SancionApiTest.php` y `ReposicionApiTest.php` en `tests/Feature`.

**Interfaces:** `SancionController::store(StoreSancionRequest $request, SoapLoanService $soap): JsonResponse`; `consultarPorSolicitante(Request $request, string $id, SoapLoanService $soap): JsonResponse`. `ReposicionController::store(StoreReposicionRequest $request): JsonResponse`; `show(Request $request, string $id): JsonResponse`.

- [x] Escribir y ejecutar primero pruebas de permisos, propiedad, validación, persistencia y ausencia de llamadas SOAP cuando se deniega acceso.
- [x] Autorizar antes de validar los campos del negocio; mantener 403 para un rol no autorizado aunque envíe un cuerpo incompleto.
- [x] Crear sanciones con tipos exactos `Entrega_Tardia`, `Dano` y `Perdida`; descripción obligatoria, días enteros no negativos y multa no negativa dentro de `decimal(10,2)`. Inicializar estado `Activa` y fechas conforme a días, con `fecha_fin` nula si son cero.
- [x] Consultar préstamos mediante el cliente autenticado al crear sanciones y comprobar la relación préstamo/solicitante antes de persistir. Usar un fixture que represente el contrato SOAP real; verificar ese contrato antes de codificar su extracción.
- [x] Consultar sanciones propias con filtrado antes de cualquier llamada remota. Ninguna respuesta del solicitante incluirá préstamos de terceros.
- [x] Crear reposiciones `Pendiente` con sanción existente, solicitante coincidente, equipo remoto entero positivo, costo no negativo y máximo de `decimal(10,2)`, observaciones opcionales. No añadir validación remota al flujo del almacenista.
- [x] Consultar reposición junto con su sanción, aplicando propiedad al solicitante. Responder 404 para registro inexistente o ajeno a este perfil.
- [x] Mantener `exito`/`datos` como respuesta de negocio; usar 201 al crear, 200 al leer, 422 al validar y 502 ante fallo SOAP. Reportar la excepción sin exponer credenciales ni detalles internos al cliente.
- [x] Ejecutar `php artisan test --compact tests/Feature/SancionApiTest.php tests/Feature/ReposicionApiTest.php`.

No se implementará sincronización de equipos, cálculo de tarifas no descrito ni cierre automático de sanciones. Tampoco se asumirá que `equipo_id` existe en SOAP solo por ser entero: la verificación remota de reposiciones queda fuera del flujo local aprobado para almacenistas.

### Tarea 3. Registrar middlewares y rutas correctas

**Modificar:** `bootstrap/app.php`, `routes/api.php` y los cuatro middlewares actuales. Crear `app/Http/Middleware/AuthorizeRestOperation.php`, `tests/Feature/RoleAccessTest.php` y `tests/Unit/RoleMiddlewareTest.php`.

**Interfaz de cada middleware de perfil:** `handle(Request $request, Closure $next, string $operation): Response`. `AuthorizeRestOperation` recibe la misma operación y delega al middleware de perfil; deniega de forma predeterminada si no hay rol reconocido. En cada ruta por perfil se pasa explícitamente el nombre funcional de operación de la matriz.

- [x] Registrar `role.admin`, `role.sanctions`, `role.replacement` y `role.viewer` con las clases existentes, conservando la respuesta JSON de errores API ya configurada.
- [x] Escribir primero pruebas HTTP sobre las rutas de la sección 3 y las cuatro rutas compatibles; comprobar 401, 403, propiedad, métodos HTTP y respuestas positivas con dependencias simuladas.
- [x] Conectar primero `AuthenticateJwt`, después el middleware de perfil con la operación y finalmente las políticas de propiedad. Las rutas compatibles pasan por `AuthorizeRestOperation` y el middleware de perfil antes de entrar al controlador.
- [x] En `RoleMiddlewareTest`, invocar directamente cada uno de los cuatro middlewares con usuario de rol incorrecto y operación prohibida; comprobar el JSON 403 exacto y que el callback siguiente nunca se ejecute. Comprobar también los casos permitidos de la matriz.
- [x] En `RoleAccessTest`, comprobar con un espía que los rechazos por rol no invocan la acción del controlador, no consumen SOAP y no escriben en base de datos, incluso ante un cuerpo incompleto. Probar las rutas por perfil y las compatibles.
- [x] Mantener `/reposiciones/{id}/completar` bloqueada por middleware para todos los roles hasta confirmar una ampliación de permisos; sin token devuelve 401.
- [x] Reutilizar las mismas acciones para las rutas por perfil y las existentes; no duplicar controladores o lógica de negocio.
- [x] Mantener `GET /` y `/up` como rutas existentes; para cumplir la expresión literal «todas las rutas excepto login», proponer también Bearer válido en ambas y adaptar la prueba inicial de `/`. Si el usuario confirma que el requisito solo abarca la API REST, conservarlas públicas como excepción expresa. No aplicar literalmente los ejemplos `/api/login` de la guía: el login real seguirá en `/api/v1/login`.
- [x] Ejecutar `php artisan route:list --path=api --except-vendor` y comprobar que ninguna acción apunte a un método inexistente.
- [x] Ejecutar `php artisan test --compact tests/Feature/RoleAccessTest.php tests/Unit/RoleMiddlewareTest.php`.

### Tarea 4. Limpieza con referencias comprobadas

- [x] Repetir búsqueda de referencias en rutas, bootstrap, providers, código, tests y configuración antes de cada eliminación.
- [x] Eliminar `app/Http/Controllers/Api/AuthController.php` si sigue sin consumidores: duplica el login activo, contiene un secreto de respaldo fijo y emite claims incompatibles con el middleware actual. El login público seguirá en `LoginController`.
- [x] Eliminar solamente el método `SoapLoanService::autenticarEnSoap()` si continúa sin llamadas. Conservar `login()`, que sí usa `LoginController`.
- [x] Conservar los cuatro middlewares, modelos, migraciones, factories y seeders necesarios para el plan, aunque hoy parte de ellos todavía no esté conectada.
- [x] Conservar `consultarEquipo()` y `actualizarEstadoEquipo()`: son capacidades de integración existentes; la ausencia actual de controladores no basta para concluir que deban eliminarse.
- [x] No borrar carpetas de framework, configuraciones, frontend, pruebas iniciales ni documentación por no aparecer en una búsqueda simple. No se identificó una carpeta completa cuya eliminación esté justificada.
- [x] Actualizar `INTEGRACION-JWT-SOAP.md` para reflejar rutas, roles, vínculos y estado realmente verificado después de implementar.
- [x] Ejecutar las pruebas de login y roles tras la limpieza; comprobar que no queden referencias a los elementos retirados.

### Tarea 5. Verificación y recorrido de demostración

- [x] Probar la matriz completa de roles sobre rutas reales, incluyendo rutas compatibles, token expirado/alterado, rol cambiado y acceso a registros de terceros.
- [x] Afirmar que no hubo persistencia ni llamada SOAP ante 401/403/404 por propiedad. Probar `shouldNotReceive` para todas las operaciones SOAP del almacenista.
- [x] Cubrir 429 en login, respuesta SOAP malformada, fallo remoto sin escritura parcial y errores JSON sin cabecera `Accept`.
- [x] Cabecera WS-Security verificada con transporte nativo controlado en Docker; no confundir un mock de `SoapLoanService` con prueba de serialización XML.
- [x] Ejecutar `vendor/bin/pint --dirty --format agent`, `php artisan test --compact`, `composer validate --no-check-publish`, `git diff --check` y el listado de rutas. Revisar el resultado del formateador.
- [x] Adaptar las peticiones de demostración de la guía al `/api/v1/login` con `username`/`password`, a las rutas de la sección 3 y al único Bearer JWT. Los cuatro correos/contraseñas locales de la guía no acreditan acceso a SOAP.
- [x] Antes de compartir URLs de Postman, obtener la base efectiva con `get-absolute-url`. Usar variables de entorno para tokens; no guardar secretos en archivos versionados.
- [x] Integración SOAP de negocio corregida y probada: despacho a listar(), WSDL ListaPrestamos, secreto privado de entorno y rechazo de Envelope ambiguo. Cuatro regresiones remotas aprobadas.

## 6. Criterios de aceptación

- Todas las rutas objetivo pueden listarse y sus controladores existen.
- JWT, usuario local y rol quedan conectados; 401 y 403 representan fallos distintos.
- Los cuatro middlewares se usan en rutas reales; no hay acceso alternativo por rutas compatibles.
- El solicitante solo accede a sus registros; el almacenista no consume SOAP.
- Las sanciones y reposiciones se guardan con los valores y estados definidos. No se habilita completar reposiciones sin permiso expreso.
- Cada rechazo por rol ocurre en middleware antes del controlador y devuelve exactamente el JSON 403 solicitado.
- Solo se eliminan el controlador duplicado y el método sin consumidores, tras volver a comprobar referencias.
- Las comprobaciones pasan; la verificación real de SOAP se informa separada de los mocks.

## 7. Decisiones de ejecución y alcance pendiente

La ejecución solicitada sigue este enfoque: mantener JWT/login SOAP, añadir rutas por perfil bajo `/api/v1` conservando las existentes, vincular identidades explícitamente y aplicar la matriz de permisos enviada sin añadir operaciones. Al no indicarse una excepción para `GET /` y `/up`, ambas exigen Bearer. `completarReposicion` sigue denegada para todos los perfiles.

La alternativa Sanctum y login local de la guía implicaría otro diseño y aprobación expresa para instalar dependencias. La autorización anterior para subir la rama no se interpreta como autorización para publicar esta implementación todavía no revisada.

## 8. Correcciones de la revisión independiente

- Comparación PHP estricta de `soap_username` tanto en login como al resolver JWT, además del filtro SQL, para evitar equivalencias de colación MySQL.
- Restricción de propiedad también sobre la sanción asociada a una reposición histórica; una relación inconsistente devuelve 404 al solicitante.
- Validación de días contra el límite real de `TIMESTAMP` MySQL antes de consultar SOAP.
- IDs de ruta recibidos como string y comprobados contra enteros positivos representables antes de convertirlos; los que exceden el rango devuelven 404.
- Guard JWT reutilizado con usuario olvidado al inicio de cada petición, sin acumular callbacks de rebind.
- Aislamiento de base de pruebas frente a variables de entorno heredadas, con verificación sin abrir ni migrar el destino externo.
- Controles positivos de usuario vinculado en las pruebas de claims, para que un 401 no pueda explicarse por la ausencia del usuario.
- Consulta de sanciones alineada con el contrato `exito`/`datos` del plan.

## 9. Verificación de la ejecución local

- `php artisan test --compact`: 147 pruebas y 1.013 aserciones, todas aprobadas.
- `route:list`: 15 rutas API listadas sin referencias a controladores inexistentes.
- Pint, Composer, sintaxis PHP de los 35 archivos modificados/nuevos y `git diff --check`: aprobados.
- Prueba adicional con variables de base heredadas: la conexión efectiva continúa siendo SQLite `:memory:`.
- Sin migraciones ni seeders sobre MySQL de trabajo. Sin commits ni push de esta implementación.
- Limitación inicial superada al iniciar Docker: transporte nativo y SOAP real ya verificados después de corregir el repositorio SOAP; ver sección 11.

## 10. Verificación inicial con Docker activo, anterior a la corrección SOAP

- PHP 8.5.12 y extensiones SOAP/MySQL/SQLite confirmadas dentro de Laravel.
- Suite Docker: 151 aprobadas, 1.042 aserciones, dos pruebas remotas omitidas por defecto.
- Transporte SOAP nativo controlado: cuatro pruebas/29 aserciones aprobadas, incluidas en la suite anterior.
- MySQL 8.4 temporal: 134 pruebas/975 aserciones aprobadas. La base temporal fue eliminada.
- Postman HTTP real: ocho aserciones aprobadas para 401, 422, login negativo Laravel→SOAP y WSDL 200.
- Pruebas SOAP reales opt-in: una aprobada y una con error; consultar préstamos falla con SoapFault. El problema está reproducido y documentado, sin modificar el repositorio externo.
- Las dos migraciones pendientes de la base de trabajo siguen pendientes. No se crearon commits ni se hizo push.

## 11. Corrección SOAP autorizada y verificación final

- Despacho de préstamos corregido y WSDL tipado para listas, incluidas vacías.
- Detección exacta de operación login y rechazo de Envelope con Body duplicado o ambiguo.
- Clave privada compartida de entorno; clave publicada anterior sustituida, sin versionar los `.env`.
- Suite Docker con SOAP real habilitado: **155 pruebas y 1.049 aserciones aprobadas, sin omisiones**.
- Prueba de autenticación SOAP positiva con datos temporales revertidos: aprobada.
- Revisión independiente del cambio SOAP y regresión del caso adicional detectado: completadas.
- Publicación en commits pequeños autorizada por el usuario. La base MySQL de trabajo conserva sus dos migraciones pendientes.
