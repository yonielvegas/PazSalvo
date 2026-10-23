# Fase 4B.3: persistencia de accesos

## Alcance

AccessOutbox persiste estados y operaciones; nunca obtiene tokens, ejecuta HTTP ni despacha
jobs. No se conecta a controladores, modelos mediante observers, vinculaciones o scheduler.
El catálogo y sus tablas permanecen independientes. No se ejecuta esta migración en desarrollo.

## Tablas

- `aaud_auth_access_states`: identidad `(application_code, issuer, subject)` única, última
  versión remota observada, última reservada, evidencia temporal y estado remoto observado.
  La versión inicial es NULL. Una ausencia verificada se registra explícitamente como
  `remote_absent=true`, versión 0, snapshot revocado. Nunca se deduce ausencia de un error 404.
- `aaud_auth_access_targets`: estado deseado más reciente por ID local, identidad posiblemente
  publicada y revocación requerida. Estos JSONB sobreviven a procesos y eliminaciones locales.
- `aaud_auth_access_operations`: versión, UUID, snapshot JSONB completo, hash SHA-256 calculado
  en PostgreSQL, origen administrativo histórico, estados, intentos y fecha del último intento,
  claim/lease, próximo intento, error fijo, recibo normalizado y resolución administrativa.

No hay FK a users ni a user_sso_identities: eliminar un usuario/vínculo no borra operaciones.
Las FK internas usan RESTRICT. Solo se admite pazysalvo. No se almacenan perfiles, credenciales
ni tokens. El payload incluye issuer, subject (metadato de ruta), application_user_id,
is_active, roles, version y operation_id, compatible con syncUserAccess().

## API explícita

- `recordRemoteObservation(AccessSnapshot, int version, bool absent, string evidence, User actor)`:
  observación autorizada de fuente confiable. No consulta el receptor. Rechaza regresiones,
  evidencias inválidas y observaciones ordinarias mientras existan operaciones sin resolver.
- `prepare(User, User actor)`: vuelve a leer el usuario con AccessSnapshotBuilder y registra
  estado deseado. Devuelve AccessOperation o NULL si se difiere, falta conciliación o no hay cambio.
- `prepareRevocation(array previousAssociation, User actor)`: preserva revocación histórica sin
  consultar el vínculo actual ni exigir que siga existiendo el usuario local.
- `prepareReplacement(array previousAssociation, User newLocalState, User actor)`: conserva
  revocación obligatoria y estado deseado nuevo, con el mismo ID local. La identidad nueva espera.
- `prepareNext(string owner, User actor)`: prepara explícitamente el siguiente estado disponible.
  No envía, no es un worker y no modifica una operación ya reservada.
- `get`, `claim`, `recordFailure`, `confirm`, `recoverExpiredClaim`: persistencia y recuperación.
- `reconcile`: requiere observación, evidencia y autorización; conserva la operación cancelada
  y su auditoría. Una entrega incierta no puede cancelarse mediante una supuesta ausencia:
  debe acreditarse una versión remota que impida aplicar después el envío viejo. El reconocimiento
  histórico exige conciliar también la versión vigente indicada por el recibo.
- `status`: informa operaciones sin resolver y coincidencia con el último estado deseado registrado.

Los actores de preparación/observación se reautorizan mediante manage-users. Los métodos de
entrega son internos para un futuro coordinador autorizado; no tienen rutas públicas.

## Orden, versiones e integridad

Se reutiliza el bloqueo transaccional administrativo (17012026, 1), antes de filas de target,
identidad y operación. Es deliberadamente conservador y serializa brevemente persistencias de
usuarios distintos; sus versiones y datos son independientes. Nunca se mantiene durante HTTP.
Los savepoints aseguran rollback incluso si un llamador captura una excepción en su transacción.

Cada nueva versión es max(última remota verificada, última reservada)+1. Restricciones únicas
impiden duplicar versión por identidad y permiten una sola operación sin resolver por identidad
y por ID local. El trigger valida sobre, identidad, versión e inmutabilidad; prohíbe DELETE,
transiciones inválidas, reemplazar claims y confirmar leases vencidas.

Una nueva solicitud mientras existe otra pendiente conserva el último estado deseado, sin
cambiar payload/versión/UUID. Las desactivaciones y revocaciones son barreras: no se borran por
una reactivación posterior. Se confirma primero la revocación y luego se prepara el estado nuevo.
Una ausencia remota acreditada permite omitir una revocación que nunca tuvo destinatario remoto,
pero solo cuando no existe ningún envío pendiente para esa identidad/usuario.

## Estados y recuperación

pending -> processing -> confirmed | acknowledged | uncertain | retryable | failed.
Una operación confirmed reconoce un recibo vigente en ese instante. acknowledged conserva
un recibo histórico válido, bloqueando la siguiente operación hasta conciliación. Ninguno prueba
vigilancia continua del receptor. Los cambios locales que no se registren explícitamente mediante
prepare() no se capturan automáticamente en esta fase.

Los reintentos explícitos de uncertain/retryable reutilizan exactamente el mismo sobre.
Cada claim tiene UUID propio y vencimiento; el claim anterior no puede confirmar. Recuperar un
processing vencido lo convierte en uncertain, nunca en rechazo. La incertidumbre se conserva
incluso después de otros rechazos. Failed no se descarta silenciosamente: requiere reconcile.
Los recibos se validan con UserAccessResult y se comparan con la instantánea persistida completa.

## Migración manual posterior

Después de revisar y autorizar su aplicación, desde el repositorio:

```bash
php artisan migrate --pretend --path=database/migrations/2026_10_22_000001_create_aaud_auth_access_outbox.php
php artisan migrate --path=database/migrations/2026_10_22_000001_create_aaud_auth_access_outbox.php
```

Estos comandos NO se ejecutan sobre desarrollo en esta entrega. La migración es aditiva.
El rollback elimina las tablas solo si todas están vacías; se rechaza si destruiría cualquier
estado u operación durable. No usar migrate:fresh sobre bases normales.

## Límites

Una observación requiere revalidación y aprobación externas; el servicio no puede demostrar
criptográficamente una consulta manual. Antes de entrega real se debe comprobar despliegue y
migración 4B.1 del receptor, catálogo vigente y permisos técnicos. No existen workers ni hooks
administrativos: la integración transaccional automática requiere otra fase autorizada.

## Verificación final

- 30 pruebas nuevas: 158 aserciones. Cuatro casos de concurrencia lanzan procesos PHP y
  conexiones PostgreSQL independientes; pg_blocking_pids verifica la espera real del segundo
  proceso detrás del primero (preparación, claim, confirmación y recuperación de lease).
- Suite completa: 639 pruebas, 5266 aserciones, cero fallos, errores u omitidas. Incluye las
  609 pruebas anteriores de autenticación, vinculaciones, roles, administración y CatalogOutbox.
- Se probó rollback de la migración vacía, reaplicación, rechazo del rollback con datos,
  inmutabilidad SQL, recibos falsos, UUID/versión conservados y reconciliación segura.
- PHP lint y Pint sobre los siete archivos PHP nuevos; git diff --check correcto.
- PostgreSQL temporal pazsalvo_phase4b3_testing, con directorio físico y conexión verificados;
  clúster detenido al finalizar. HTTP real bloqueado por el arnés y HTTP simulado en pruebas.
- Evidencia temporal: /tmp/pazsalvo-phase4b3-jxprbj15/full-suite.xml.

Se corrigieron durante testing la recreación de funciones PL/pgSQL al reconstruir esquemas,
la precedencia de operadores JSONB y la limpieza/barrera de las pruebas concurrentes. Los
resultados anteriores corresponden al código definitivo posterior a esas correcciones.
No se ejecutaron migraciones, observaciones o preparaciones reales en desarrollo.
