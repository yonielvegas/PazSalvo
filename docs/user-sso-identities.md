# Vinculación y perfil institucional (Fase 3B)

## Alcance y autoridad

El administrador selecciona expresamente una identidad `issuer + subject` por medio
del directorio de AAUD AUTH. Username, nombre o correo no deciden asociaciones.
El login local sigue usando email y contraseña; no hay login por username ni OIDC.
Los roles, permisos, agencias, estados, contraseñas e historial permanecen locales.
No se sincronizan accesos ni catálogos y no se modifica Keycloak/Active Directory.

## Datos y procedencia

- `users.username`: VARCHAR(255) nullable. Se normaliza con trim y minúsculas UTF-8.
  PostgreSQL exige normalización y un índice UNIQUE sobre LOWER(username), además
  de rechazar controles. La validación usa la misma comparación case-insensitive.
  No hay backfill ficticio; los usernames existentes permanecen NULL. El formulario
  local admite username opcional durante la transición.
- `user_sso_identities.verified_profile`: JSONB nullable. Solo almacena username,
  nombre real y correo válidos recibidos del directorio; sus claves constituyen la
  procedencia explícita. Nunca se copia un perfil arbitrario enviado por el navegador.
- `name_is_fallback`: booleano añadido al contrato de AAUD AUTH. Conserva el campo
  name anterior para compatibilidad. Si es true, el username usado como respaldo
  no se importa como nombre completo. Un nombre real igual al username sigue
  identificado correctamente por el flag, sin heurísticas de igualdad.

Los campos faltantes o inválidos no bloquean el campo local ni borran datos válidos.
El correo institucional válido se normaliza a minúsculas. La identidad y el perfil
se revalidan en el backend al guardar una selección nueva.

Las vinculaciones anteriores sin snapshot siguen con verified_profile=NULL: no se
atribuye procedencia institucional al perfil local. El administrador puede buscar y
seleccionar nuevamente esa misma identidad para verificarla, conservando ID y fecha.

## Interfaz y guardado

Nuevo usuario abre con el switch AAUD AUTH activado; requiere selección explícita
para crear con SSO. Al desactivarlo desaparece la búsqueda y se recupera el borrador
local escrito antes de seleccionar. Los campos verificados se muestran readonly y
marcados; los demás permanecen editables. Solo Guardar produce escrituras.

Editar refleja el vínculo guardado, muestra la instantánea y su fecha, y conserva
la fecha de creación local al final. Las fechas usan America/Panama y día/mes/año.
Una selección nueva advierte que sustituirá los campos locales disponibles. Cambiar
el switch o cancelar el modal no guarda nada. La desvinculación se confirma al
guardar, conserva el username y permite editarlo después de desvincular.

Se reutiliza UserSsoIdentityManager, con verificación HTTP antes de la transacción
de la operación. El bloqueo administrativo PostgreSQL precede al usuario y vínculo.
Se comprueban autorización, vínculo esperado, duplicados y colisiones de perfil;
los cambios de perfil, rol explícito, firmas, vínculo y auditorías se confirman o
revierten juntos. Las firmas preparadas se compensan si falla la operación.
Los índices únicos protegen también escrituras concurrentes directas.

La auditoría del perfil registra campos verificados y sustituidos, actor y usuario,
sin duplicar nombres/correos en ese nuevo evento ni guardar tokens/contraseñas.
Una respuesta perdida nunca provoca un reenvío automático: se bloquea Guardar y se
ofrece recargar el listado para comprobar el resultado persistido.

Los campos verificados del snapshot se vuelven a imponer en el servidor ante una
petición manipulada. No hay refrescos automáticos del perfil desde AD. No se puede
sobrescribir otro issuer/subject: primero hay que guardar su desvinculación.

## Aplicación manual de migraciones

Requiere la tabla de identidades de Fase 3 ya aplicada. Estas dos migraciones nuevas
solo se ejecutaron en testing. Aplicarlas manualmente en desarrollo antes de usar
la nueva pantalla, después de revisar la conexión efectiva:

```sh
php artisan migrate --path=database/migrations/2026_10_21_000001_add_username_to_users.php
php artisan migrate --path=database/migrations/2026_10_21_000002_add_verified_profile_to_user_sso_identities.php
```

No hay cambios de IDs ni contraseñas ni asignaciones. Los rollback eliminan las
columnas nuevas y sus restricciones; por ello eliminan también sus datos nuevos,
aunque conservan usuarios y vinculaciones. Fueron comprobados en testing.

El productor AAUD AUTH debe servir name_is_fallback antes de utilizar la importación
del perfil. El cliente falla cerrado si el campo está ausente o no es booleano.

## Pruebas y visualización

Las pruebas PHP verifican current_database() y exigen una base PostgreSQL aislada
terminada en _testing. El runner local de QA usa pazsalvo_phase01_20261019_testing.
Las carreras usan procesos independientes y observan esperas reales en pg_stat_activity.
Se bloquea HTTP no simulado. La prueba del directorio de AAUD AUTH usa dobles de DB/HTTP.

Las pruebas visuales no arrancan Laravel ni acceden a datos reales; interceptan todas
las peticiones y prueban fechas con el navegador configurado en otra zona horaria:

```sh
npm run typecheck
npm run build -- --outDir /tmp/pazsalvo-phase3b-build
SSO_UI_BUILD_DIR=/tmp/pazsalvo-phase3b-build npx playwright test --config tests/frontend/playwright.config.ts
```

Para visualizar con Laravel en localhost:8000, usar npm run dev; con Vite detenido,
npm run build genera los assets normales. La compilación de QA se conserva en /tmp.
No usar la configuración e2e general contra las bases normales.
