# Corrección focalizada de acceso SSO y login AAUD OAUTH

Fecha: 29/10/2026. No se realizó otra auditoría ni despliegue general.

## Causa confirmada y reparación

La primera ejecución aislada de pruebas del renombrado heredó `CACHE_STORE=file` del proceso operativo. Aunque utilizaba una base sintética, publicó su caché de Spatie en el almacenamiento de archivos de la aplicación activa. La repetición posterior con caché en memoria hizo pasar la suite, pero no había eliminado ese caché operativo contaminado.

Reproducción previa a esta corrección, mediante consultas sin modificar usuarios:

| ID | Rol real | Permisos directos en BD | `can('consultar paz y salvo')` antes | Después |
|---|---|---:|---|---|
| 14 | admin | 21 | false | true |
| 15 | operador | 4 | false | true |
| 16 | consulta | 3 | false | true |

El registro de permisos en caché no tenía asociaciones de roles. Por eso un callback válido podía autenticar al usuario y el middleware de permisos rechazar la página final. El historial operativo contenía `login.sso.succeeded` tras el renombrado: el rechazo no demostraba un fallo del intercambio OIDC.

Se ejecutó **solamente `php artisan permission:cache-reset`** y se verificó que los permisos se reconstruyen desde las asociaciones existentes. No se modificaron filas para conseguir acceso. No fue necesario alterar issuer, subject, cliente, callback, realm, URL de aplicación o roles.

Prevención permanente:

- `phpunit.xml`: `CACHE_STORE=array` con `force=true`, para que las pruebas no hereden el caché operativo.
- `tests/TestCase.php`: caché de permisos de pruebas explícitamente en `array`.
- `tests/Feature/PermissionCacheIsolationTest.php`: comprueba aislamiento y reproduce en memoria un caché sin roles; el reset recupera la autorización manteniendo intactas las asociaciones SQL.

## Botón de login

El componente React **no había eliminado el botón** y conserva el texto «Iniciar sesión con AAUD OAUTH» y el enlace `/auth/aaud/redirect`. Antes de editar código, una sesión nueva en el navegador mostró el botón, con `oidc_login_enabled=true` y sin errores JavaScript. Por tanto, **no se reprodujo su desaparición en una carga nueva y no se atribuye una causa histórica exacta sin evidencia**.

La opción dependía de una propiedad compartida ordinaria de Inertia, que puede omitirse de respuestas parciales. Se hizo explícita y permanente en las respuestas del login:

- `app/Providers/FortifyServiceProvider.php`: la vista Fortify entrega `oidc_login_enabled` mediante `Inertia::always`, respetando `AAUD_OIDC_ENABLED`.
- `app/Http/Middleware/HandleInertiaRequests.php`: se retiró la definición compartida duplicada; la página de login es la propietaria de esta opción.
- `tests/Feature/Oidc/OidcLoginTest.php`: verifica carga completa y parcial, habilitación y deshabilitación explícitas.

No se duplicó autenticación ni se forzó la activación cuando está deshabilitada. En la configuración operativa permanece habilitada. La comprobación final de navegador muestra exactamente un botón, además del formulario local, sin errores. No cambió JavaScript; no fue necesario recompilar frontend.

## Aceptación A–G

| Escenario | Resultado y alcance |
|---|---|
| A — Portal | **Aprobado por pruebas funcionales encadenadas por el contrato real.** En AAUD OAUTH una identidad sintética autorizada ve la tarjeta `pazysalvo`, su acción redirige exactamente a `http://localhost:8000/auth/aaud/redirect`, y una revocada recibe 403. En Paz y Salvo se prueba ese mismo endpoint con referencia del portal, intercambio OIDC simulado con JWT firmado, callback validado, usuario vinculado, rol local, `auth_method=oidc` y GET protegido **200**. La URL operativa almacenada coincide. |
| B — Botón | **Aprobado funcional y visualmente.** Página login con opción habilitada, endpoint existente, callback completo, resolución issuer/subject, rol operador y GET protegido **200**. Navegador verifica botón real; cuatro pruebas de interfaz verifican formulario local, configuración deshabilitada y navegación normal por teclado en escritorio/móvil. |
| C — Local | **Aprobado con cuenta sintética.** Fortify valida contraseña local, marca `auth_method=local` y permite GET protegido **200**. |
| D — Logout local | **Aprobado.** Finaliza la sesión y regresa a `/login`; sin llamadas al proveedor. |
| E — Logout SSO | **Aprobado para los dos puntos de entrada.** Regresa al portal, elimina la sesión local y rota identificadores/CSRF; no realiza petición de logout a Keycloak. Se conserva la posibilidad de reutilizar la sesión central. |
| F — Autorización | **Aprobado.** Se rechazan asociaciones ausentes, otro issuer/subject, usuario deshabilitado, rol ausente/inactivo, callbacks y tokens inválidos; no se importan roles de tokens ni se conceden accesos. |
| G — Integridad | **Aprobado.** Comparación exacta antes/después de roles, permisos, vínculos, identidades, estados, operaciones Outbox y migraciones de Paz y Salvo; mismo número de usuarios y mismos datos verificados de Ana/Ricardo. En el portal las huellas de aplicaciones, roles, accesos, asignaciones administrativas, cliente técnico, recibos y migraciones coinciden con el final del renombrado. Ricardo ID 15 sigue `operador`; Ana ID 16 sigue `consulta`. |

Ejecuciones: **59 pruebas PHP focalizadas aprobadas** (1.128 aserciones), seguidas de **2 pruebas de caché aprobadas** (10 aserciones; una añade la reproducción y otra repite el aislamiento); **4 pruebas funcionales del portal aprobadas** (45 aserciones); **4 pruebas de navegador del login aprobadas**. No se ejecutaron suites generales ni `migrate:fresh`. El runner reutilizó el esquema de pruebas existente mediante migraciones normales sin pendientes; no aplicó migraciones a las bases operativas.

Durante la comprobación final apareció un login operativo real exitoso del usuario ID 14 a las **14:48:41**, con `session_version=18`. Por eso la huella completa de la tabla `users` difiere al cierre; las huellas de autorización, identidades y sincronización permanecen idénticas. No se atribuye esa actividad de sesión a una modificación administrativa ni se declara igualdad completa de todas las filas de usuarios al final. Véase `docs/sso-regression-evidence/integrity-summary.json`.

No se introdujeron contraseñas reales ni se tomaron sesiones institucionales existentes para simular aceptación. **No se completó una nueva autenticación interactiva humana contra AD.** La aprobación del callback y del acceso final procede de pruebas funcionales con identidades sintéticas y verificaciones de autorización operativa; no se declara aprobada solo por abrir Keycloak. La conservación de la sesión central se verifica por la ausencia de petición de logout y la navegación OIDC sin `prompt=login`, sin apropiarse de una sesión real.

## Archivos y evidencia

Código de producto modificado en esta corrección: `app/Providers/FortifyServiceProvider.php` y `app/Http/Middleware/HandleInertiaRequests.php` de Paz y Salvo. Aislamiento/pruebas: `phpunit.xml`, `tests/TestCase.php`, `tests/Feature/PermissionCacheIsolationTest.php`, `tests/Feature/Oidc/OidcLoginTest.php`; en AAUD OAUTH, `portal/tests/Feature/AuthorizationTest.php`.

Evidencia de Paz y Salvo: `docs/sso-regression-evidence/{focused-php.log,cache-regression.log,focused-browser.log,live-login-browser.json,live-permissions-after.json,data-before.json,data-after.json}`. Evidencia del portal: `docs/rename-evidence/sso-regression-portal-tests.log` y `sso-regression-integrity.json`.

No se cambiaron el controlador OIDC, el validador de tokens, el logout, las políticas de autorización, los usuarios, los clientes de Keycloak, Active Directory ni la sincronización. No se reiniciaron servicios operativos ni se reconstruyeron imágenes: se utilizó el contenedor de pruebas con la prueba focalizada montada en solo lectura. No se hicieron commits ni pushes.
