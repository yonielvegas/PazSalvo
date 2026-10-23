# Sincronización automática de accesos — Fase 5

## Funcionamiento

Las operaciones administrativas autorizadas capturan los usuarios afectados dentro de
`AdministrativeTransaction`. Al terminar la unidad administrativa se consulta su estado
final mediante `AccessSnapshotBuilder` y se registra en **AccessOutbox existente**, en la
misma transacción que los cambios de cuenta, rol y vinculación. Un rollback elimina también
el registro del estado deseado. Los bloqueos por intentos fallidos y los cambios de contraseña
no disparan este mecanismo.

Después del commit se intenta la entrega inmediatamente. Si falla, el scheduler retoma
los objetivos ya registrados; no recorre `users` para publicar vinculaciones antiguas.
Las rutas alternativas del gestor SSO, los cambios de rol y estado, la sustitución del
administrador general y la eliminación administrativa utilizan la misma captura.
La eliminación conserva primero la identidad en Outbox y la auditoría de desvinculación;
se mantienen las restricciones locales de certificados y último administrador.

El GET técnico consulta exclusivamente la aplicación `pazysalvo`, con el issuer configurado
y scope `access.write`. Se exige HTTP 200 y el contrato completo. Solo `exists=false`,
versión entera cero, propietario NULL, estado falso y roles vacíos prueban ausencia.
Un 404, fallo de autenticación, timeout o JSON incompleto **no** prueban ausencia.
Los accesos existentes de otro propietario o múltiples roles inesperados requieren revisión.
No se sobrescriben ni se reasocian silenciosamente.

Una observación GET validada inicializa la versión conocida. La operación reserva una
versión superior a las observadas y reservadas. Se conserva la instantánea, versión y UUID
originales en cada replay. La entrega reclama primero el objetivo con un UUID y vencimiento;
la operación tiene además su reclamación independiente, verificada por AccessOutbox y los
triggers existentes. El HTTP ocurre sin transacciones abiertas.

## Recuperación y revocaciones

- La identidad anterior se conserva sin FK que borre su historial al eliminar el usuario.
- Una revocación tiene prioridad; la nueva identidad espera su confirmación o una observación
  verificable que demuestre que la anterior ya está revocada o ausente.
- Una operación pendiente con cero intentos puede cancelarse auditadamente si cambió el estado
  deseado antes del primer envío. La migración extiende únicamente esa transición en el trigger,
  conservando las restricciones para reclamaciones y resultados inciertos. Una desactivación no
  publica un alta obsoleta que nunca llegó a enviarse.
- Un PUT sin respuesta queda incierto. Si GET todavía no alcanza su versión, se reenvía
  exactamente la operación original; no se abandona un intento que podría llegar tarde.
- Si GET alcanza o supera esa versión, permite conciliar y cerrar la operación con evidencia
  `technical-get:<claim UUID>` y actor originador histórico. No se fabrica un recibo `applied`.
- Los recibos históricos permanecen almacenados; requieren conciliación y no significan
  que la réplica vigente esté sincronizada.
- Los conflictos de versión solo se resuelven desde una versión realmente observada.
  Los conflictos de UUID, asociación y rechazos permanentes requieren atención.
- Backoff: 60, 120, 240… segundos, hasta 1 hora; se respeta Retry-After hasta 24 horas
  para operaciones. Las esperas de catálogo, reclamación ajena o backoff no consumen el presupuesto. Máximo 8 intentos de entrega por objetivo sin una nueva modificación
  administrativa. Al agotarse, se muestra que requiere atención; no se pierde la operación.
- Después de un reinicio, una reclamación vencida se recupera como incierta. Ningún worker
  puede confirmar con un claim de operación vencido o sustituido.

El catálogo usa CatalogOutbox. Los cambios de nombre/estado y altas preparan una instantánea
si hay versión inicial verificada; mientras otra esté pendiente, los cambios siguen siendo
consultables en los roles locales. El scheduler compara nuevamente tras confirmar la anterior.
Los accesos activos esperan que el catálogo completo vigente esté confirmado y contenga sus
códigos activos. Las revocaciones no dependen del catálogo. No se modifica un código inmutable.
Sin un GET de catálogo confirmado no se inventa conciliación de conflictos de catálogo:
quedan detenidos para revisión mediante los mecanismos anteriores.

## Activación local y proceso permanente

Se necesita aplicar **manualmente** la migración aditiva que registra recuperación del GET,
porque este puede fallar antes de reservar una operación. No almacena secretos ni tokens.
No se alteran las tablas de catálogo ni los payloads anteriores. Se amplía de forma controlada
el trigger de accesos para cancelar intenciones obsoletas nunca enviadas; rollback restaura
la definición anterior y conserva las operaciones y sus auditorías.

Desde `/var/www/PazSalvos/PazSalvo`:

```bash
php artisan migrate --path=database/migrations/2026_10_23_000001_add_access_delivery_recovery.php
php artisan schedule:work
```

La integración conserva la configuración privada existente. `AAUD_AUTH_ENABLED=true`
habilita también la sincronización automática por defecto. `AAUD_AUTH_AUTOMATIC=false`
permite detenerla explícitamente para mantenimiento. No se debe usar ese interruptor para
realizar modificaciones SSO que se pretendan publicar: estando desactivado no captura nuevas
operaciones. La aplicación no solicita tokens al arrancar. Mantener TLS y scopes
`directory.read`, `roles.write`, `access.write` configurados según las funciones utilizadas.
No ejecutar los comandos de entrega hasta tener el receptor actualizado y autorizado.

No es necesario ejecutar un comando por usuario ni mantener un queue worker. El scheduler
lanza `aaud-auth:deliver` cada minuto, con `withoutOverlapping(10)` más reclamaciones durables
en PostgreSQL. En un servidor debe mantenerse `schedule:run` cada minuto mediante su gestor
de procesos/cron habitual. `schedule:work` debe permanecer en ejecución en desarrollo.

## Usuarios históricos: autorización separada

Los usuarios vinculados antes de esta fase no se publican por barrido automático.
Consultar los IDs en administración y previsualizar explícitamente:

```bash
php artisan aaud-auth:initialize-access ID_USUARIO
```

Ese modo no realiza HTTP ni escribe. Solo después de autorizar los IDs concretos:

```bash
php artisan aaud-auth:initialize-access ID_USUARIO --approved-by=ID_ADMIN --authorize
```

Comprueba el permiso administrativo, audita la autorización y usa el mismo flujo automático
GET → Outbox → PUT. Repetir la inicialización no crea duplicados. No se debe autorizar a
ciegas un conjunto de cuentas administrativas históricas.

## Estado mostrado y comprobación

`SSO: SI / SSO: No` representa únicamente el vínculo. Tras guardar se muestra por separado:
sincronizado, cambio local guardado pendiente, o requiere atención. El listado muestra un
indicador discreto para entregas pendientes o con error. Un Outbox pendiente nunca se
presenta como sincronizado. Tras una corrección externa de un rechazo permanente se necesita
revisión de la operación y conciliación; no basta aumentar versiones ni cambiar UUID.

Desde AAUD AUTH, comprobar en la aplicación Paz y Salvo la identidad vinculada, su ID local,
estado y código de rol. El GET técnico autenticado permite contrastar esos campos y la versión;
no imprimir JWT ni secretos. Un usuario desactivado/desvinculado debe tener acceso falso y
roles vacíos sin afectar otras aplicaciones. Este procedimiento no incorpora login OIDC.

## Pruebas

Usar PostgreSQL temporal terminado en `_testing`, verificar `current_database()` y bloquear
HTTP externo. Las pruebas cubren entregas post-commit, revocación y reemplazo, rollback,
catálogo, evidencias GET, respuesta perdida, backoff y procesos independientes. No usar la
base de desarrollo ni ejecutar inicialización histórica como parte de las pruebas.

## Archivos de esta fase

- Transporte: `app/Integrations/AaudAuth/Http/AaudAuthClient.php`, `RemoteUserAccess.php`.
- Persistencia/entrega: `app/Integrations/AaudAuth/Access/AccessOutbox.php`, `AccessDelivery.php`.
- Captura transaccional: `app/Services/AdministrativeTransaction.php`, `UserSsoIdentityManager.php`,
  `app/Models/User.php`, `app/Models/Role.php`, `app/Models/Concerns/HasSingleRole.php`.
- Administración: `app/Http/Controllers/AdminUserController.php`,
  `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/pages/admin/users/index.tsx`.
- Operación/configuración: `app/Console/Commands/DeliverAaudAuthAccess.php`,
  `InitializeAaudAuthAccess.php`, `routes/console.php`, `config/aaud-auth.php`, `.env.example`.
- Migración: `database/migrations/2026_10_23_000001_add_access_delivery_recovery.php`.
- Pruebas: `tests/Feature/Integrations/AaudAuth/AutomaticAccessTest.php`,
  `tests/Unit/Integrations/AaudAuth/RemoteUserAccessTest.php`,
  `tests/helpers/automatic-access-worker.php`, `tests/frontend/user-sso-identity.spec.ts`.

Los cambios previos del repositorio se conservan; no forman parte de esta lista de cambios de Fase 5.

## Validación ejecutada

- Suite PHP completa: **685 pruebas, 5.589 aserciones**, aprobadas.
- Casos específicos de esta fase: **46 pruebas, 323 aserciones**, aprobadas (incluidos en la suite completa).
- Chromium/Playwright: **26 pruebas**, aprobadas; todas las peticiones interceptadas.
- TypeScript, compilación Vite en `/tmp/pazsalvo-phase5-ui`, PHP lint (19 archivos),
  Pint y `git diff --check`: aprobados.
- PostgreSQL temporal verificado: `pazsalvo_phase5_testing`, directorio de datos
  `/tmp/pazsalvo-phase5-7p5jgvz9/pgdata`; el clúster se detuvo al finalizar.
- Evidencia: `/tmp/pazsalvo-phase5-7p5jgvz9/access-outbox.xml` y `full-suite.xml`.
- HTTP de PHP bloqueado además de Http::fake/preventStrayRequests; sin PUT reales,
  inicializaciones históricas reales ni migraciones sobre desarrollo/producción.
