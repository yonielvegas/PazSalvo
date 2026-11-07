# Integridad administrativa de Paz y Salvo

## Códigos de rol

`roles.id` y los nombres/permisos de Spatie se conservan. `code` es VARCHAR(80),
NOT NULL, único por `guard_name`, y cumple `[a-z0-9][a-z0-9_.:-]{0,79}`.
PostgreSQL rechaza UPDATE de `code`, incluso por Query Builder o SQL directo.

El backfill y la creación sin código explícito usan el mismo algoritmo:
trim, transliteración ASCII de Laravel, minúsculas, cada secuencia incompatible
se sustituye por `_` y se eliminan caracteres iniciales que no sean alfanuméricos.
Ejemplo: `Revisión Técnica` → `revision_tecnica`. No se truncan códigos ni se
añaden sufijos. Un resultado vacío, de más de 80 caracteres o una colisión
detiene la operación; la resolución requiere una decisión explícita. El nombre
solo interviene en la creación/backfill, nunca durante un renombrado.

Las migraciones nuevas no modifican IDs, nombres, permisos, hashes ni asignaciones.
La primera bloquea `roles`, verifica el backfill completo y añade las restricciones
y el trigger dentro de la transacción de migración PostgreSQL. La segunda bloquea
`model_has_roles`, verifica duplicados y crea un índice único parcial por `model_id`
para `App\Models\User` y sus aliases configurados. Otros modelos no están sujetos
a ese índice. Cambiar un morph map después requiere revisar el predicado mediante
otra migración; no se debe cambiar silenciosamente el alias de User.

Cada migración tiene rollback propio: se retiran únicamente las protecciones que
añade; no se eliminan roles ni asignaciones. El rollback del código elimina esa
columna, por lo que debe guardarse el catálogo antes de un rollback operativo si
se necesita conservar identificadores de roles renombrados.

## Un rol y estados excepcionales

La API administrativa expone `role: string|null`; los formularios siguen usando
un select simple. `assignRole` y `syncRoles` sustituyen por un único rol activo;
una entrada con varios roles falla antes de desprender el anterior. `removeRole`
permite retirar el rol salvo al último admin. Un usuario sin rol es un estado
excepcional no operativo: no inicia sesión ni atraviesa middleware privado ni
obtiene autorización de Gate, aunque conserve permisos directos. Los permisos
directos existentes no se eliminan. Un usuario no puede reactivarse sin un rol
vigente. El índice también impide asignaciones múltiples por SQL concurrente.

## Transacciones y bloqueos

`AdministrativeTransaction` adquiere `pg_advisory_xact_lock(17012026, 1)` antes
de bloquear filas. Todas las operaciones administrativas y sustituciones de rol
comparten ese recurso; luego se bloquean filas de roles/usuarios y finalmente
se modifican pivotes, firmas, sesiones y auditoría. Se privilegia una política
simple y conservadora: la administración se serializa, el tráfico de certificados
y las consultas no toman este bloqueo. Una operación superior abre una sola
transacción; las llamadas internas reutilizan su unidad. Si el llamador ya tiene
una transacción externa se crea un savepoint para revertir la operación completa.

Las comprobaciones del último admin se hacen bajo ese bloqueo en sustitución de
rol, desactivación y borrado. Los comandos y seeders de usuarios usan la misma
estrategia. SQL manual puede omitir las reglas de negocio del último admin y de
estado activo; no es una interfaz administrativa soportada. La inmutabilidad del
código y el máximo de un rol sí se protegen en PostgreSQL.

La auditoría de éxito comparte la transacción. Ante un fallo se revierte y se
intenta registrar un evento `.failed` separado, con la clase de excepción, sin
mensaje SQL ni credenciales. Si tampoco puede persistirse, queda un error de log;
no se afirma que exista auditoría durable cuando la base está indisponible.

Las firmas se guardan con nombre nuevo y se eliminan por compensación si falla
la transacción. No se eliminan archivos de firmas históricas. PostgreSQL y el
filesystem no forman una transacción distribuida: una caída abrupta del proceso
o un fallo de borrado puede dejar un archivo huérfano. La compensación fallida
se registra y requiere revisión; nunca se anuncia atomicidad física absoluta.

## Despliegue y validación

No aplicar estas migraciones sobre datos reales sin revisión y autorización de
despliegue. Probar primero una copia aislada del esquema y del catálogo; ante
colisiones/duplicados, detenerse sin elegir roles ni códigos arbitrarios.

El código nuevo necesita la columna antes de escribir roles; la versión antigua
no proporciona `code` para altas posteriores a la migración. Coordinar migración
y activación de release, sin escrituras administrativas concurrentes de versiones
distintas. El bloqueo DDL necesita una ventana controlada; no se usa índice
CONCURRENTLY porque las comprobaciones y la instalación deben ser atómicas.

Las pruebas requieren PostgreSQL separado con nombre terminado en `_testing`.
`Tests\TestCase` comprueba ese requisito antes de ejecutar los traits de base de
datos. Exportar las credenciales de testing de forma segura y ejecutar
`vendor/bin/phpunit --do-not-cache-result`; no usar `composer test`, que limpia
caché. Las pruebas de concurrencia usan procesos/conexiones independientes y
observan `pg_stat_activity.wait_event` antes de liberar la primera transacción.
Necesitan permisos para observar sus propias conexiones. PHPUnit usa migraciones
y fixtures únicamente en esa base. Las migraciones históricas completas tienen
un rollback problemático de tablas antiguas; las pruebas nuevas no dependen de
revertir toda la historia, y prueban por separado el rollback de las migraciones
de esta fase.

El frontend se verifica con `npx tsc --noEmit`, `npm run build` y Playwright para
settings y selección única, con servidor, sesiones y fixtures de testing propios.
