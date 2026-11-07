# Política local y cuentas SSO-only — Fase 7

## Reglas

`users.local_login_enabled` es independiente de `is_active`, `is_login_blocked` y la
vinculación SSO. La migración nueva conserva todas las cuentas existentes con el flag
habilitado y sus hashes intactos; no convierte masivamente cuentas vinculadas a SSO-only.

- Alta local: mantiene la contraseña temporal y cambio obligatorio actuales.
- Alta con identidad verificada: flag falso, contraseña NULL, sin cambio obligatorio,
  sin generar ni leer la contraseña genérica. Usuario, rol, identidad, auditoría y estado
  deseado de sincronización se guardan en la unidad transaccional existente.
- Vincular una cuenta existente conserva contraseña y flag. Desvincular no los habilita
  ni crea credenciales. Si queda sin ambos métodos, requiere intervención administrativa.
- Solo el restablecimiento administrativo existente habilita el acceso local de una cuenta
  SSO-only. No hay un control editable del flag en los formularios de crear/editar.

## Restablecimiento existente

El modal **no envía correo ni enlace**: establece directamente la contraseña temporal
configurada para su entrega segura por el administrador. La confirmación autorizada guarda
hash y flag verdadero en la misma transacción, revoca sesiones y aplica las reglas existentes
de desbloqueo y cambio obligatorio. Abrir/cancelar el modal no escribe nada. Si no hay contraseña
temporal configurada, la operación se bloquea; si falla el guardado o la auditoría, se revierte
el hash y el flag. No hay una etapa ficticia de envío de enlace ni tokens nuevos.

Para cuentas con flag falso el modal muestra la advertencia roja antes de confirmar. Las
cuentas con flag verdadero conservan el modal habitual. Tras completar, el backend devuelve
el estado actualizado; al abrirlo nuevamente ya no aparece la advertencia.

Las rutas públicas de registro, recuperación y reset siguen sin estar habilitadas. Los tokens
inválidos/vencidos no pueden utilizarse en rutas inexistentes. Incluso si se invoca la acción
Fortify de recuperación, se rechaza modificar una cuenta SSO-only; no se introduce un flujo
público que active el flag. Se mantienen los parámetros del password broker existente.

## Backend y compatibilidad

La tubería Fortify y su callback de autenticación comprueban el flag antes del hash y del
bloqueo por intentos, con el mismo error público genérico que una cuenta desconocida.
No modifican contadores de intentos de una cuenta SSO-only. Una comprobación adicional
bajo el bloqueo de usuario evita aceptar un flag obsoleto durante el login.

`LocalLoginUserProvider` extiende el proveedor Eloquent para impedir saltarse la política
mediante `Auth::attempt` o una cookie histórica de Recordarme. Conserva la búsqueda por ID
utilizada por la sesión OIDC; no condiciona OIDC al flag ni a una contraseña.

Las acciones de contraseña y el controlador de cambio obligatorio verifican el estado
persistido y vuelven a comprobarlo dentro del bloqueo al escribir. Las opciones voluntarias
de perfil siguen sin existir; nunca se muestra el formulario de cambio obligatorio a SSO-only.
La pantalla Crear usuario muestra el mensaje de acceso exclusivo en lugar de una contraseña
cuando se selecciona el modo SSO. El restablecimiento reutiliza el modal actual.

No se modifican PKCE/OIDC, issuer+subject, roles, permisos, Keycloak, AD ni AccessOutbox.
Los eventos nuevos son `user.sso_only.created`, `user.password_change_rejected`,
`user.password_reset_requested`, `user.password_reset_completed` y `user.local_login_enabled`.
Los fallos conservan también la auditoría administrativa existente. No se guardan contraseñas,
hashes ni tokens en esos metadatos.

## Migración y comprobación manual

Desde `/var/www/PazSalvos/PazSalvo`, aplicar manualmente después de revisar los cambios:

```bash
php artisan migrate --path=database/migrations/2026_10_24_000001_add_local_login_policy_to_users.php
php artisan config:clear
```

La migración hace nullable `password`, añade el flag booleano obligatorio con default true y
una restricción que exige contraseña no NULL si el login local está habilitado. No cambia
usuarios, hashes, roles ni identidades existentes. Rollback se detiene si existen cuentas con
flag falso o contraseña NULL para evitar inventar contraseñas o eliminar una política vigente.
No se ejecutó sobre la base de desarrollo.

1. Con Vite activo (`npm run dev`), abrir Usuarios y crear una cuenta nueva con AAUD OAUTH.
   La sección explica que no se genera contraseña local. Seleccionar identidad, agencia y rol.
2. Comprobar su acceso OIDC con la cuenta institucional autorizada. El login local debe fallar
   sin cambiar los contadores. En la base, una consulta SELECT del ID, flag y `password IS NULL`
   permite verificar la política sin imprimir hashes.
3. Abrir **Restablecer contraseña**: aparece la advertencia roja. Cancelar conserva el estado.
4. Confirmar con un administrador autorizado y entregar la contraseña temporal por el canal
   seguro habitual. Ahora el login local funciona y exige cambiar la contraseña temporal.
   SSO continúa disponible y mantiene su rol local.
5. Abrir el mismo modal: ya no aparece la advertencia. Una cuenta local existente vinculada
   posteriormente conserva ambos métodos sin tener que restablecer su contraseña.

La prueba real institucional no se ejecuta automáticamente. Las pruebas utilizan PostgreSQL
exclusivo de testing y HTTP simulado; no publican usuarios ni accesos reales.

## Inventario de cambios

- `database/migrations/2026_10_24_000001_add_local_login_policy_to_users.php`: flag, nulabilidad,
  restricción y rollback seguro.
- `app/Models/User.php`: estado booleano y política común de cambios de contraseña.
- `app/Auth/LocalLoginUserProvider.php`, `app/Providers/AppServiceProvider.php`, `config/auth.php`:
  validación del guard y rechazo de cookies históricas para SSO-only.
- `app/Actions/Fortify/AttemptToAuthenticateWithSpanishMessages.php`,
  `app/Providers/FortifyServiceProvider.php`: tubería real y fallback de login local.
- `app/Actions/Fortify/ResetUserPassword.php`, `app/Actions/Fortify/UpdateUserPassword.php`:
  protección de acciones alternativas de contraseña.
- `app/Http/Controllers/AdminUserController.php`: alta sin contraseña, restablecimiento
  atómico y auditoría; props de la política persistida.
- `app/Http/Controllers/ForcedPasswordChangeController.php`,
  `app/Http/Middleware/EnsurePasswordHasBeenChanged.php`: protección del cambio obligatorio.
- `app/Http/Middleware/HandleInertiaRequests.php`: estado de política para el usuario actual.
- `resources/js/pages/admin/users/index.tsx`, `resources/css/app.css`: reutilización del
  modal, advertencia roja, fallos de restablecimiento y alta SSO sin contraseña genérica.
- `tests/Feature/LocalLoginPolicyTest.php`, `tests/Feature/Oidc/OidcLoginTest.php`,
  `tests/Feature/Integrations/AaudOauth/AutomaticAccessTest.php`,
  `tests/frontend/user-sso-identity.spec.ts`: pruebas nuevas y ajuste de la expectativa
  anterior de contraseña temporal en altas SSO a la nueva política.

## Resultados ejecutados

772 pruebas PHP y 6.607 aserciones aprobadas; 87 pruebas pertinentes con 1.228 aserciones
(incluidas en el total); 35 pruebas Chromium aprobadas. TypeScript, compilación Vite en
`/tmp/pazsalvo-phase7-ui`, PHP lint de 16 archivos, Pint y `git diff --check` aprobados.
PostgreSQL aislado: `pazsalvo_phase7_testing`, clúster temporal detenido al terminar.
Evidencia PHPUnit en `/tmp/pazsalvo-phase7-wpktb8qr/{oidc,full-suite}.xml`.
Los casos de rollback intencional producen logs de error simulados y terminan aprobados.
No se ejecutaron PUT reales, migraciones en desarrollo/producción, commits ni pushes.
