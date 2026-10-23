# Catálogo durable de AAUD AUTH — Fase 2C

Esta biblioteca persiste operaciones; no entrega HTTP, inicia procesos ni escucha cambios administrativos.
La migración no se ha aplicado a desarrollo ni producción. No hay inicialización remota automática.

## Estado e inicialización

`aaud_auth_catalog_states` contiene únicamente `pazysalvo / roles`. La versión remota y la última
reserva comienzan en NULL. NULL significa desconocida, nunca cero. `prepare()` falla hasta registrar
una observación confiable mediante `recordRemoteObservation(version, evidenceReference, approvedBy)`.
Exige un administrador local activo con rol `admin`, una referencia de evidencia sin texto libre ni
credenciales y una versión no regresiva. La observación y su auditoría comparten transacción.
Esta API interna registra la aprobación; no verifica por sí misma una fuente remota. No tiene ruta ni comando público.

En Fase 2D habrá que obtener la versión de una fuente autorizada y confiable del receptor y conservar
la evidencia y aprobación. El GET de configuración inspeccionado no devuelve `catalog_version`.
No se debe deducir del número de roles, IDs, fechas, operaciones locales ni ausencia de recibos.

## Preparación e integridad

`prepare()` adquiere, en este orden, el advisory lock administrativo existente `(17012026, 1)`,
la fila de estado mediante FOR UPDATE y, cuando corresponde, la operación mediante FOR UPDATE.
Así mantiene el orden de los futuros llamadores administrativos y serializa las reservas.
La nueva versión es `max(última versión remota verificada, última reserva local) + 1`, con límite
9007199254740991. Las versiones canceladas no se reutilizan.

`RoleCatalogBuilder` consulta y valida el catálogo web completo dentro de esta transacción.
El JSONB conserva versión, UUID y todos los roles, incluidos los inactivos. PostgreSQL calcula
SHA-256 sobre la representación JSONB canónica. Cada lectura verifica ese hash; un trigger impide
mutar identidad, payload, hash y creación o eliminar operaciones. No requiere extensiones PostgreSQL.
El hash detecta corrupción; no sustituye controles de acceso frente a un propietario de base malicioso.

`CatalogOperation::payload()` reconstruye siempre el mismo orden de claves de solicitud. El intento
posterior usa ese payload; nunca vuelve a construir el catálogo ni genera otra versión/UUID.
Un savepoint deliberado conserva atomicidad si el llamador ya abrió una transacción: una excepción
o fallo de auditoría revierte también reserva y Outbox. No hay HTTP dentro de estas transacciones.

## Estados y reclamaciones

| Origen | Destino | Condición |
|---|---|---|
| pending, retryable, uncertain | processing | Fecha permitida, nueva concesión UUID y vencimiento de 1–3600 segundos |
| processing | confirmed | Concesión vigente; recibo coincide con UUID, versión e instantánea normalizada por el receptor |
| processing | retryable | Fallo transitorio conocido antes de aplicación; próximo intento explícito |
| processing | uncertain | Timeout, conexión de entrega, 5xx, recibo no confiable o recuperación de concesión vencida |
| processing | failed | Rechazo permanente o conflicto que requiere revisión |
| failed, uncertain | cancelled | Reconciliación y aprobación explícitas; reglas adicionales descritas abajo |

`attempts` cuenta reclamaciones, no certifica solicitudes HTTP enviadas. `claim_id` y `lease_expires_at`
no pueden sustituirse ni ampliarse mientras la operación sigue processing. La confirmación y el
registro de fallo comprueban concesión, fila bloqueada y actualización condicional de exactamente una fila.
PostgreSQL comprueba además su propio reloj: una concesión vencida solo permite recuperarse como
uncertain con `lease_expired`; no permite confirmar ni declarar un rechazo definitivo tardío.

Un futuro proceso de entrega deberá confirmar el commit de su reclamación antes de enviar HTTP.
La recuperación de una concesión vencida no supone que el receptor rechazó el envío. Una nueva
reclamación conserva el payload y obtiene otra concesión; los resultados del proceso antiguo se rechazan.
No se implementa aquí ese proceso ni reintentos automáticos.

## Revisión y cancelación

El índice parcial permite una sola operación sin resolver. `failed` sigue bloqueando: nunca se
salta automáticamente un error. `cancelAfterReview()` permite salir de ese estado de forma auditable:

- Exige administrador activo, referencia de evidencia y versión remota observada no regresiva.
- Conserva operación, UUID, versión, payload, hash y error. Registra aprobación, evidencia,
  versión observada y fecha en la operación y en AuditLogger, en la misma transacción.
- Un fallo definitivamente rechazado, sin incertidumbre histórica, puede cancelarse aunque
  el receptor siga por debajo de esa versión. La siguiente reserva será superior a la cancelada.
- `has_uncertain_attempt` es persistente y no puede borrarse: un timeout seguido de un 403
  sigue requiriendo reconciliación. Para cancelar ese caso o un uncertain, la versión remota
  realmente verificada debe ser mayor o igual a la operación. Esto impide que cualquier envío
  antiguo todavía en tránsito aplique después de cancelarlo: el receptor responderá duplicate
  o stale_version. La simple ausencia de un recibo remoto no basta.
- Si la versión remota no alcanza esa barrera, se rechaza la cancelación. Hay que resolver
  reintentando la misma operación y obteniendo un recibo, o realizar una reconciliación adicional
  expresamente autorizada. No hay bypass ni aumento ficticio de la versión observada.

confirmed y cancelled son terminales e inmutables. Cancelar no confirma que la operación nunca se
aplicó ni que el catálogo esté sincronizado. El índice deja de bloquear solo después de confirmar o cancelar.
El rollback de la migración funciona con Outbox vacío; se rechaza si hay operaciones para no perderlas.

## Cambios locales mientras existe una operación pendiente

La instantánea pendiente nunca cambia. `inspectLocalChanges()` compara el catálogo vigente con la
instantánea durable bajo el mismo bloqueo administrativo. Informa `snapshot_matches_local`,
`blocked_by_unresolved`, `needs_preparation` y `currently_synchronized`. No escribe datos ni envía HTTP.
Esta comparación no depende de memoria ni de un evento perdido: puede repetirse después de reiniciar.

Si cambian roles durante una entrega, el recibo puede confirmar la operación antigua, pero
`isCurrentlySynchronized()` permanece falso. Una vez resuelta, `needs_preparation` indica que hace falta
otra instantánea completa. Un futuro coordinador deberá consultar esta señal al terminar cada operación
y antes de declarar convergencia. Hasta implementarlo, no existe propagación automática de cambios.

Una operación histórica duplicate se reconoce como confirmed, pero su versión remota actual superior
impide afirmar coincidencia con el catálogo vigente. La condición de sincronización es evidencia según
la última observación remota confiable, no una vigilancia continua de modificaciones externas.

## Seguridad y pruebas

La persistencia funciona aunque la integración esté deshabilitada y no depende de tokens.
Los códigos de error se restringen a constantes locales; no se persisten mensajes de excepción,
cabeceras, cuerpos OAuth ni secretos. Las auditorías usan el estado real como resultado.

Las pruebas usan PostgreSQL exclusivo terminado en `_testing`, verifican `current_database()` y
bloquean HTTP no simulado. Las carreras usan procesos y conexiones independientes con barreras y
verificación de espera en `pg_stat_activity`; no son llamadas secuenciales disfrazadas de concurrencia.
