# Fase 4B.2 — Construcción y transporte de accesos

## Estado de esta entrega

La implementación está aplicada en el repositorio Paz y Salvo, rama `sso-integration`.
Antes de aplicar se verificaron los 12 archivos del manifiesto mediante SHA-256 y
`git apply --check`; se conservaron los cambios anteriores del proyecto. Ningún archivo de
AAUD AUTH se modificó. La entrega no activa envíos ni conecta operaciones administrativas.

## Auditoría y arquitectura

Se confirmó la rama sso-integration y se inspeccionaron User, UserSsoIdentity, HasSingleRole,
UserSsoIdentityManager, AdministrativeTransaction, Role/Spatie, RoleCode, AaudAuthClient,
TechnicalTokenProvider/TechnicalToken/excepciones, aaud-auth.php, RoleCatalogBuilder,
CatalogOutbox/operaciones y sus pruebas. No se encontraron AGENTS.md aplicables en la ruta
ni sus directorios padres inspeccionados. Se preservaron todos los cambios previos.

Se consultó en modo de solo lectura el receptor AAUD AUTH, especialmente
IntegrationController::access, ApplicationManagedAccess::receipt/duplicate/access y las
pruebas de integración. El endpoint se conserva genérico; aplicación y cliente técnico
siguen fijos en Paz y Salvo. No hay cambios al catálogo de roles o su persistencia.

## Constructor y contrato exacto

`App\Integrations\AaudAuth\Access\AccessSnapshotBuilder` expone:

- `build(User $user): ?AccessSnapshot`: lee el estado guardado actual y sus relaciones,
  ignorando relaciones cacheadas y cambios administrativos todavía no guardados. Devuelve
  null si no existe vinculación; no busca coincidencias por correo, nombre o username.
- `fromState(string $localId, mixed $administrativelyActive, ?array $identity, iterable $roles): ?AccessSnapshot`:
  transformación pura de una captura explícita; identity contiene issuer y subject.
- `revocation(array $previousAssociation): AccessSnapshot`: usa una asociación histórica
  explícita con application_user_id (string), issuer y subject; application_code es opcional
  pero, si se suministra, debe ser pazysalvo. No carga usuarios o vinculaciones actuales.

`AccessSnapshot` es readonly, applicationCode es siempre pazysalvo y `payload()` devuelve:

```php
[
    'issuer' => 'https://keycloak.example.test/realms/aaud',
    'subject' => 'subject-A',
    'application_user_id' => '15',
    'is_active' => true,
    'roles' => ['operador'],
]
```

Para revocación, is_active es false y roles es []. Nunca contiene versión, UUID, nombre,
correo, contraseña ni credenciales técnicas. El estado administrativo usa is_active;
is_login_blocked y login_attempts no afectan la instantánea. Un usuario inactivo puede
revocarse aunque ya no tenga un rol operativo; no se exige revalidar roles para retirar acceso.

Para un usuario activo se exige exactamente un Role web activo y su código estable válido.
Se rechazan estados administrativos ambiguos, rol ausente/múltiple, guard incorrecto, rol
inactivo, código inválido, issuer vacío/diferente/inseguro y subject ausente o inválido.
Los IDs locales son strings decimales positivos de hasta 128 caracteres y subject admite
1–255 caracteres ASCII A–Z, a–z, 0–9, guion y guion bajo. El issuer debe coincidir exactamente
con aaud-auth.issuer, incluir /realms/{realm} y usar HTTPS, salvo localhost en local/testing.
No hay normalización silenciosa de subject, issuer o código del rol.

La captura durable y la coordinación transaccional con cambios administrativos pertenecen
a 4B.3. build realiza lecturas normales; un coordinador futuro debe capturar el estado bajo
la disciplina de AdministrativeTransaction antes de reservar una operación. No se agrega
esa coordinación en esta fase.

## Transporte preparado

Se añade `AaudAuthClient::syncUserAccess(array $operation): UserAccessResult` al cliente
existente. Acepta exactamente las cinco claves de snapshot.payload() más version (int,
1–9007199254740991) y operation_id (UUID suministrado). Claves extra, campos ausentes,
secretos agregados, aplicación/cliente arbitrarios y tipos incorrectos se rechazan antes
incluso de obtener el token. El cliente no genera ni cambia versiones ni UUID.

Realiza un único PUT a:

```text
{aaud-auth.api_url}/api/integrations/v1/applications/pazysalvo/users/{subject}/access
```

El JSON contiene issuer, application_user_id, is_active, roles, version y operation_id.
Subject solo se utiliza en la ruta. La aplicación no procede del navegador ni del payload.
Se reutilizan validación TLS/CA, connect_timeout, timeout, ausencia de redirecciones,
clasificación sanitizada y cliente técnico pazysalvo-sync.

TechnicalTokenProvider admite ahora un scope requerido opcional, sin cambiar el flujo
anterior de catálogo/directorio. Para accesos se solicita explícitamente access.write y se
exige que la respuesta técnica lo reconozca en scope. La caché está ligada al scope requerido,
además de la configuración; un token obtenido para el flujo anterior no se reutiliza como
prueba de access.write. La ausencia/rechazo del scope detiene el flujo antes del PUT.
Esto deberá verificarse con la configuración institucional cuando se autorice una futura
prueba real; aquí todas las respuestas OAuth son simuladas.

## Recibos

`UserAccessResult` solo se construye mediante una fábrica que valida el recibo contra la
instantánea enviada y el sobre externo. Expone status, snapshot, version, operationId,
confirmedAt, currentVersion, historical y currentState; solo retiene campos validados.

Se exige HTTP 200, JSON válido, applied/duplicate, issuer/subject/aplicación/ID local/
estado/roles/versión/UUID exactos, fecha ISO válida, versión actual entera dentro del límite
y historical booleano. El UUID reconocido se compara con la representación minúscula que
normaliza el receptor. No se modifica el UUID del payload enviado.

- applied exige current_version igual a la enviada, historical=false y el formato actual
  del receptor, sin current_state.
- duplicate exige current_state con version=current_version, is_active booleano, roles
  ordenados/válidos/sin duplicados (hasta 50, según el contrato multirrol del receptor) y
  catalog_version válido. La versión de catálogo no se confunde con la del acceso.
- historical se valida contra aumento de versión **o cambio de estado/roles**. El catálogo
  puede revocar roles sin aumentar la versión del acceso; por eso no se copia la invariante
  historical=(current_version>version) del catálogo.
- wasApplied() distingue aplicación inicial; isDuplicate() distingue reconocimiento repetido;
  historical indica recibo histórico; confirmsCurrentAccess() solo es true con evidencia
  vigente para la misma versión y sin cambios. Un recibo histórico nunca confirma convergencia,
  aunque el estado de una versión posterior coincida casualmente.

La confirmación describe la respuesta recibida, no vigilancia continua ni estado durable.
El receptor no devuelve el ID local actual en current_state; la fábrica no lo inventa.

## Errores y entrega incierta

AaudAuthRequestException conserva razones fijas, HTTP, código remoto de una lista cerrada y
Retry-After numérico; no encadena objetos de transporte ni conserva cuerpos o cabeceras.
Los mensajes genéricos se amplían para accesos, manteniendo las razones anteriores.

| Caso | Clasificación |
| --- | --- |
| 401 | unauthorized |
| 403 | forbidden |
| 404 identidad o acceso desconocido | not_found |
| 409 versión antigua | version_conflict |
| 409 UUID reutilizado con otro contenido | idempotency_conflict |
| 409 asociación u otro conflicto | conflict |
| 400/422 | invalid_request |
| 429 | rate_limited; Retry-After solo informativo |
| 5xx, incluido 503 | temporary; entrega incierta |
| Timeout / pérdida de conexión | timeout / connection; entrega incierta |
| JSON o recibo inválido/incompleto/otra operación, HTTP inesperado | invalid_response / unexpected_response; entrega incierta |
| Configuración deshabilitada/insegura, operación inválida | Fallo local antes del PUT |
| Fallo de autenticación técnica/scope | TechnicalAuthenticationException antes del PUT |

isDeliveryUncertain() e isDefinitiveRejection() distinguen resultados inciertos de rechazos
HTTP explícitos. Ninguna excepción implica reintento automático. Un timeout puede ocurrir
después del commit remoto: un reenvío explícito debe conservar exactamente el mismo payload,
UUID y versión. Nunca se aumenta versión ni se cambia UUID para superar un conflicto.

## Archivos del parche

Nuevos:

- app/Integrations/AaudAuth/Access/AccessSnapshot.php
- app/Integrations/AaudAuth/Access/AccessSnapshotBuilder.php
- app/Integrations/AaudAuth/Exceptions/InvalidAccessSnapshotException.php
- app/Integrations/AaudAuth/Http/UserAccessResult.php
- tests/Support/NoDatabaseIntegrationTestCase.php
- tests/Unit/Integrations/AaudAuth/AccessSnapshotBuilderTest.php
- tests/Unit/Integrations/AaudAuth/UserAccessClientTest.php
- tests/Feature/Integrations/AaudAuth/AccessSnapshotBuilderTest.php
- docs/aaud-auth-user-access.md

Modificados:

- app/Integrations/AaudAuth/Http/AaudAuthClient.php
- app/Integrations/AaudAuth/Keycloak/TechnicalTokenProvider.php
- app/Integrations/AaudAuth/Exceptions/AaudAuthRequestException.php

## Pruebas y límites de validación

Las pruebas unitarias ejecutadas en el repositorio aplicado conservaron el resultado previo:
**69 pruebas, 1220 aserciones, salida 0**. No requieren base de datos y usan HTTP simulado.

Las cuatro pruebas nuevas de integración fueron ejecutadas satisfactoriamente en PostgreSQL:
**4 pruebas, 28 aserciones, salida 0**. Verifican lecturas sin escrituras del constructor,
separación entre bloqueo local y desactivación administrativa, revocación histórica y ausencia
de publicación para un usuario sin vínculo.

La validación PostgreSQL utiliza un clúster temporal exclusivo bajo `/tmp`, sin puerto TCP,
con base `pazsalvo_phase4b2_testing`. Antes de ejecutar se comprueban el nombre efectivo,
el directorio físico del clúster y la ausencia inicial de tablas. Se conservan las guardas
existentes de `Tests\TestCase` y se fuerzan configuración de testing y cachés temporales.

Las solicitudes de integración se simulan con `Http::fake()` y `Http::preventStrayRequests()`.
El arnés temporal añade bloqueo de cURL/sockets PHP y desregistra los wrappers HTTP/HTTPS,
sin impedir la construcción de Guzzle para las respuestas simuladas. La primera ejecución
completa fue descartada por una configuración demasiado restrictiva de ese arnés; no se
modificaron servicios ni pruebas para corregirla.

Pint, lint de los 11 archivos PHP del cambio y `git diff --check` pasaron.
La suite completa terminó con **609 pruebas aprobadas y 5108 aserciones**, cero fallos,
cero errores y cero pruebas omitidas. Incluye las 69 pruebas nuevas unitarias, las cuatro
nuevas de integración y las 536 existentes, con regresiones de catálogo/Outbox/directorio,
vinculaciones, Fortify, roles, sesiones, atomicidad y concurrencia administrativa.
El clúster temporal se detuvo al finalizar. La Fase 4B.2 queda validada en su alcance simulado.

Evidencia de esta ejecución: `/tmp/pazsalvo-phase4b2-final-opim6bhs/full-suite.xml` y
`/tmp/pazsalvo-phase4b2-final-opim6bhs/full-suite.log`. Los artefactos de `/tmp` no se
versionan y su conservación depende del entorno.

## Restricciones y siguiente paso

No se enviaron usuarios reales. Los cambios de Paz y Salvo se limitan a los 12 archivos de
esta fase. No se modificaron AAUD AUTH, Keycloak, AD, .env, Fortify, contraseñas, roles/permisos
ni bases reales. No se crearon
migraciones, tablas, AccessOutbox, jobs/listeners, endpoints, comandos publicadores, hooks
administrativos, reintentos automáticos o login SSO. No hubo despliegues, commits ni push.

La migración y actualización del contenedor de AAUD AUTH Fase 4B.1 siguen pendientes para
una futura intervención autorizada. La persistencia de accesos, captura durable de identidades
anteriores y coordinación con transacciones administrativas pertenecen a Fase 4B.3 y no se
implementan en esta entrega. La aprobación de estas pruebas simuladas no autoriza envíos reales.
