# Recuperación local del catálogo tras pérdida del historial

## Estado verificado de esta incidencia (solo lectura)

- Origen: Paz y Salvo, `sso-integration`, base local `paz_salvo_aaud`.
- Receptor: base `aaud` en `aaud-auth-postgres-1`; SELECT dentro de transacción READ ONLY.
- Versión remota: **2**, aplicación activa, cliente técnico y scopes roles.write/access.write verificados.
- Roles remotos activos: admin, administrador_general, comercial, consulta, operador, prueba, supervisor.
- Roles locales actuales: **cinco**: admin, administrador_general, consulta, operador, supervisor.
- Omitirían **comercial y prueba**, ambos con cero asignaciones remotas actualmente.
- No se debe retirar comercial por accidente: decidir si se restaura localmente primero o se
  autoriza expresamente también su retirada. No se restauran roles ni permisos automáticamente.
- Estado local del catálogo: versiones NULL y cero operaciones.
- Acceso pendiente: ID interno 1, usuario local 13, rol operador, versión 1, estado pending, intentos 0.
  UUID `997d3d62-dba6-4521-a468-0bbfc535458a`.
  Hash `d802ebc348a0149cacd1ddf36d00926e18d613295aea3c5b24580cfa6c4b6d08`.
- Target 13 detenido en needs_review, sin próximo intento. Administrador local elegible: ID 11.
- No hay acceso remoto registrado con application_user_id=13: no se detectó reutilización de
  ese ID frente a una identidad antigua. La identidad concreta volverá a comprobarse mediante GET.

Estas observaciones no autorizan PUT. La ejecución debe volver a verificar la versión y todo el plan.

## Previsualización predeterminada

Desde `/var/www/PazSalvos/PazSalvo`:

```bash
php artisan aaud-oauth:recover-catalog --expect-version=2
```

No realiza HTTP ni pide tokens, no prepara operaciones y no registra observaciones locales.
Consulta únicamente SELECT en origen y PostgreSQL remoto, con PGOPTIONS read-only y una
transacción remota repeatable-read/read-only. No carga credenciales en argumentos ni las imprime.
El procedimiento usa el contenedor local ya existente; no lo reconstruye ni reinicia.

Muestra el catálogo completo exacto, versión observada y candidata, omisiones con sus conteos,
y UUID/version/hash de los accesos detenidos. Produce un `approval_hash` SHA-256 del plan
normalizado. Una ejecución posterior vuelve a consultar la evidencia; si cambió versión,
catálogo, omisiones o las operaciones aprobadas, se detiene sin reservar una nueva versión.
No se presupone que la versión inicial remota sea cero ni se deriva de roles o fechas.

## Ejecución expresamente autorizada

**No se ha ejecutado en las bases reales durante esta tarea.** Después de decidir el tratamiento
de comercial y aprobar el plan exacto mostrado:

```bash
php artisan aaud-oauth:recover-catalog --expect-version=2 --approved-by=11 --plan=SHA256_APROBADO --execute
```

El flag autoriza las escrituras locales y el PUT de catálogo si hay diferencias, seguido de la
entrega del acceso ya preparado. Sin `--execute` todo es previsualización. No cambiar la
configuración privada ni usar el cliente de login. Se requieren enabled/automatic habilitados.
El comando está limitado a local/testing y exige administrador activo con permiso de usuarios.

Flujo:

1. Vuelve a leer la versión y catálogo persistidos. Deben seguir en la versión esperada.
2. Bajo el bloqueo administrativo existente verifica de nuevo el catálogo local y el historial
   vacío de catálogo. Registra la observación completa y su aprobación en CatalogOutbox/AuditLogger.
3. Si ambos catálogos coinciden, no crea una operación ni un recibo. La instantánea SELECT
   auditada constituye evidencia separada; `last_confirmed_at` no se inventa.
4. Si difieren, reserva `max(versiones observadas/reservadas)+1` mediante CatalogOutbox.
   En esta incidencia sería **3**, y el catálogo se construye desde roles locales completos.
5. Fuera de la transacción utiliza la entrega existente, el token técnico y el PUT existente.
   Confirma únicamente el recibo real validado para esa operación. Un 409 o timeout detiene
   la recuperación de accesos y conserva la operación para revisión/reintento; nunca inventa
   una versión más alta para eludir el conflicto. Una actualización concurrente del receptor
   queda protegida también por su comprobación de versión.
6. Vuelve a verificar PostgreSQL remoto: versión y catálogo deben coincidir exactamente con el
   estado local aprobado. Las filas omitidas quedan inactivas/eliminadas en origen conforme al
   contrato; no se borran filas ni se alteran accesos de otras aplicaciones.
7. Reanuda solo targets needs_review sin concesión y con operación **pending, cero intentos,
   sin recibo, incertidumbre ni error de operación**. Verifica hash, rol y estado deseado.
   No toca UUID, versión, payload, hash ni ninguna columna de la operación de acceso.
8. Desbloquea el target auditadamente y usa AccessDelivery. Este consulta primero el GET del
   acceso y entrega/valida mediante el mecanismo existente. No crea una operación duplicada.

El resultado JSON muestra por separado la versión de catálogo y si cada acceso está actualmente
sincronizado. Un target false no debe interpretarse como éxito; revisar su error persistido.

## Si falla el catálogo y se confirma después por el scheduler

Conservar el UUID/payload de catálogo existente. El scheduler de Fase 5 reintenta su entrega según
sus reglas. Nunca volver a inicializar ni preparar otro catálogo con un UUID distinto a modo de reintento.
Cuando la operación esté realmente confirmada, hacer una nueva previsualización de la versión
real, por ejemplo 3. Para reanudar únicamente el acceso pendiente conservado:

```bash
php artisan aaud-oauth:recover-catalog --expect-version=3
php artisan aaud-oauth:recover-catalog --expect-version=3 --approved-by=11 --plan=NUEVO_SHA256 --execute --resume-target=13
```

No desbloquea operaciones failed/uncertain/processing ni abandona revocaciones pendientes.
Esos casos necesitan su reconciliación específica. No desactiva reclamaciones ajenas.
Una entrega incierta de acceso sigue conservando su UUID y se recupera por las reglas existentes.

## Comprobación final

- Catálogo local: last_remote_version y last_reserved_version 3 si hubo PUT; operación confirmed
  con recibo applied/duplicate real y catálogo vigente. Si solo hubo observación coincidente,
  puede no existir operación ni last_reserved_version, lo cual es correcto y no un recibo ficticio.
- Target 13: delivery_error NULL; operación original confirmed con UUID/version/hash anteriores.
- GET técnico autenticado del acceso: issuer, subject, aplicación pazysalvo, ID local 13,
  is_active=true, roles=[operador], version=1, coincidentes con el payload original.
- El portal AAUD OAUTH debe mostrar el acceso y rol de esa identidad. No se imprime JWT ni secreto.

No hay migraciones ni dependencias nuevas para esta recuperación. La evidencia completa se conserva
en AuditLogger, no en un segundo Outbox ni en recibos fabricados.

## Archivos y validación

- `app/Integrations/AaudOauth/Roles/LocalCatalogReader.php`: observación actual mediante SELECT
  remoto read-only y validación de evidencia, cliente y aplicación.
- `app/Integrations/AaudOauth/Roles/CatalogRecovery.php`: plan aprobado, recuperación y
  reanudación transaccional limitada de accesos pendientes sin alterar sus operaciones.
- `app/Console/Commands/RecoverAaudOauthCatalog.php`: previsualización por defecto y ejecución
  solo con aprobación explícita, hash del plan e identificación administrativa.
- `app/Integrations/AaudOauth/Roles/CatalogOutbox.php`: instantánea observada auditada separada
  de los recibos; permite reconocer un catálogo ya existente sin inventar una operación.
- `app/Integrations/AaudOauth/Access/AccessDelivery.php`: acepta esa evidencia verificada para
  desbloquear la dependencia de catálogo, conservando la entrega existente.
- `tests/Feature/Integrations/AaudOauth/CatalogRecoveryTest.php`: ocho casos nuevos para pérdida
  de historial, igualdad/diferencias, versión cambiada, autorización, fallo e incertidumbre.

Pruebas ejecutadas: **780 pruebas PHP, 6.660 aserciones**, aprobadas. Selección pertinente:
94 pruebas, 771 aserciones (incluidas en el total). PostgreSQL exclusivo
`pazsalvo_catalog_recovery_testing`, HTTP y Docker simulados; bloqueo de HTTP a nivel de red.
Pint, PHP lint y git diff --check aprobados. Evidencia en
`/tmp/pazsalvo-catalog-recovery-n6cjreqh/{oidc,full-suite}.xml`; clúster temporal detenido.
Los cambios pendientes de Fase 7 se conservaron. No se hicieron commits ni pushes.
