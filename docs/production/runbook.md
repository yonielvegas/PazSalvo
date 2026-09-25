# Runbook de despliegue privado

El CD implementado se documenta en las secciones siguientes. Aplica exclusivamente al repositorio privado PazSalvo.

## Flujo CI → CD

Un `push` a `main` ejecuta `ci` en GitHub-hosted runners. El job `test` conserva todos sus checks. Solo después de ese job, `package-production` instala Composer `--no-dev`, compila assets y sube un artefacto asociado al SHA exacto. `deploy-production` escucha la finalización exitosa de ese mismo run de CI para un push a `main` del propio repositorio. Descarga el artefacto de ese run y verifica checksum y SHA. CI fallido, cancelado o incompleto no despliega. El runner de producción usa `[self-hosted, production, pazsalvo]`; tests y empaquetado siguen en `ubuntu-latest`.

Deploy y rollback comparten la concurrencia `pazsalvo-production`, sin cancelar operaciones en curso. El artefacto incluye código runtime, `vendor/` sin paquetes de desarrollo y `public/build/`. Excluye `.git`, cualquier `.env.*` (incluido `.env.testing`), `node_modules`, `storage`, cachés, pruebas, documentación y logs. El empaquetado falla si encuentra un archivo `.env` en el tar final. No se ejecutan Composer ni npm en producción.

## GitHub Environment

Crear el Environment `production`; se recomienda habilitar required reviewers y limitarlo a `main` según la política de AAUD. No hace falta un GitHub Secret: el `.env` productivo vive solo en el servidor y el token efímero de Actions lee el artefacto.

Deploy y rollback siempre consultan `http://127.0.0.1/healthz` y `/login` con `Host: pazsalvo.aaud.local`. La IP local debe estar permitida por `internal.network`. `PRODUCTION_HEALTH_URL` ya no interviene; elimínala del Environment si existía.

## Preparación manual del servidor

`current` puede faltar en el primer deploy. El CD exige los directorios reales `/var/www/paz-salvo-private/releases`, `/var/www/paz-salvo-private/shared` y `/var/www/paz-salvo-private/shared/storage`. Exige `/var/www/paz-salvo-private/shared/.env` como archivo real legible por `pazsalvo-deploy`; nunca crea uno de ejemplo. El usuario del runner necesita crear/borrar sus releases, cambiar `current` y asignar grupo `www-data` a los archivos nuevos. Debe pertenecer a `www-data`. Los archivos de release deben ser legibles por ese grupo; `bootstrap/cache` se deja `2770` con grupo `www-data`. `shared/storage` conserva escritura para `www-data`. El deploy crea los subdirectorios Laravel faltantes dentro de `shared/storage`, sin eliminar contenido existente.

Comprobaciones manuales, sin modificar otros proyectos:

```bash
id pazsalvo-deploy
namei -l /var/www/paz-salvo-private/releases
namei -l /var/www/paz-salvo-private/shared/storage
sudo -u pazsalvo-deploy test -r /var/www/paz-salvo-private/shared/.env
sudo -u pazsalvo-deploy test -w /var/www/paz-salvo-private/releases
sudo -u pazsalvo-deploy test -w /var/www/paz-salvo-private
/usr/bin/php8.4 -d memory_limit=256M -v
/usr/bin/libreoffice --version
```

La única autorización adicional necesaria es un comando concreto de `sudoers`. Ejecutar **una sola vez** como administrador del servidor:

```bash
printf '%s\n' 'pazsalvo-deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm' | sudo tee /etc/sudoers.d/pazsalvo-fpm-reload >/dev/null
sudo chmod 0440 /etc/sudoers.d/pazsalvo-fpm-reload
sudo visudo -cf /etc/sudoers.d/pazsalvo-fpm-reload
sudo -u pazsalvo-deploy sudo -n -l /usr/bin/systemctl reload php8.4-fpm
```

El preflight usa el último comando con `sudo -n -l` antes de cambiar `current`; el único comando elevado durante la transición es `sudo -n /usr/bin/systemctl reload php8.4-fpm`. No se conceden `restart`, `stop` ni sudo general. PHP-FPM 8.4 es compartido: el reload graceful también renueva sus workers de otras aplicaciones, sin modificar su configuración global.

El administrador debe crear y revisar `shared/.env` fuera de Actions. Usar `.env.production.example` como lista de campos, sin copiarlo como configuración final sin completar valores. Esta instalación productiva funciona en la red interna AAUD por HTTP, sin reverse proxy: `APP_URL=http://pazsalvo.aaud.local`, `APP_ALLOWED_HOSTS=pazsalvo.aaud.local`, `SESSION_SECURE_COOKIE=false` y `TRUSTED_PROXIES` vacío. El portal público interno usa `PUBLIC_VERIFICATION_BASE_URL=http://pazsalvo-public.aaud.local/verificar`. `APP_DEBUG=false` sigue siendo obligatorio; si `APP_URL` pasa a HTTPS, `SESSION_SECURE_COOKIE=true` será obligatorio. Configurar PostgreSQL, Apache y el pool PHP-FPM de este sitio por separado. El sitio debe apuntar a `/var/www/paz-salvo-private/current/public`. Preparar backup y aprobar/aplicar migraciones manualmente por release antes de exponer código que dependa de ellas. No se proporciona un comando genérico de migración automática por el riesgo de las migraciones históricas. Tampoco se ejecutan seeders.

## Activación y fallos

El job crea `releases/<UTC timestamp>-<SHA corto>` con grupo `www-data` y modo `2750`, y extrae el artifact sin restaurar los metadatos del directorio raíz. Después de generar los caches, normaliza lectura y recorrido para el grupo en toda la release y deja `bootstrap/cache` con modo `2770`. Antes de activar `current`, valida `public/index.php`, grupo y modo de la raíz, acceso de grupo a `public/index.php`, enlaces exactos de `.env` y `storage`, y grupo y modo de `bootstrap/cache`. También verifica `artisan`, `vendor/autoload.php`, `public/build/manifest.json` y el SHA. La release enlaza `.env` y `storage` a `shared`; solo elimina un directorio `storage` empaquetado dentro de esa nueva release antes de enlazarlo. Nunca modifica `shared/storage` ni otras releases.

Los comandos Artisan usan `/usr/bin/php8.4 -d memory_limit=256M`. Antes de activar, ejecutan `config:clear`, `route:clear`, `view:clear` y `event:clear`, seguidos de `config:cache`, `route:cache`, `view:cache` y `event:cache`. `config/view.php` ubica las vistas compiladas en `bootstrap/cache/views` de cada release; preparar B ya no borra las vistas compiladas de A en `shared/storage/framework/views`. No se ejecuta `optimize:clear` ni `cache:clear`: ambos intentarían limpiar el cache store de PostgreSQL antes de que exista la tabla `cache` en el primer despliegue. Si falta `.env`, un archivo o falla un comando, `current` no cambia. No hay migraciones ni seeders automáticos. La activación usa symlink temporal y `mv -T` atómico.

Antes del switch se validan el manifest y todos los archivos que referencia, los permisos y los enlaces a `shared`, y se comprueba el permiso sudo específico. Después del switch se recarga PHP-FPM con `sudo -n /usr/bin/systemctl reload php8.4-fpm`, se confirma que el servicio está activo y se hacen hasta cinco comprobaciones completas del runtime. Cada una exige `/healthz` HTTP 200 y su `release` igual a `RELEASE_SHA`, `/login` HTTP 200, las entradas principales JS/CSS del manifest actual presentes en el HTML, y HTTP 200 para todos los JS/CSS locales referenciados. Solo entonces se crea `.release-ready` y se limpian releases viejas. Si falla reload, SHA, login, assets o health, se restaura atómicamente el `current` anterior, se recarga FPM otra vez y se valida la release restaurada; el workflow falla. En el primer deploy fallido se retira `current` y se recarga FPM.

Para el bootstrap inicial, las migraciones aprobadas y los requisitos de `/healthz` deben estar listos antes del primer deploy automatizado: ya no se puede omitir el smoke test. Aplicar migraciones manualmente y con respaldo; el CD no las ejecuta. Las releases anteriores a este cambio no devuelven `release` en `/healthz`: su restauración cambia `current` y recarga FPM, pero la verificación SHA reportará error hasta que exista una release compatible. Se debe conservar manualmente una release previa conocida durante el primer despliegue de este mecanismo.

Tras éxito se conservan como máximo tres releases preparadas: activa, inmediatamente anterior y la más reciente restante. Solo se borran directorios con ID válido dentro de `releases`; nunca `shared`. Una interrupción abrupta podría dejar una release incompleta sin `.release-ready`; revisarla manualmente antes de borrarla. Si falla la limpieza, el job falla aunque la release nueva ya esté activa; consultar `current` antes de reintentar.

## Rollback de código

En Actions ejecutar `rollback-production` con `release` vacío para enumerar las releases preparadas sin cambiar `current`. Ejecutar de nuevo con uno de esos IDs. Se validan formato, ubicación real, permisos, manifest y enlaces a `shared`; luego se comprueba sudo, se cambia `current` atómicamente, se recarga FPM y se repiten las validaciones de SHA, login, assets y health. Si falla cualquier paso tras el switch, se restaura la release que estaba activa, se recarga FPM y se valida de nuevo. El workflow informa explícitamente si también falla esa restauración. Solo cambia código: no revierte PostgreSQL, migraciones ni storage, y no ejecuta seeders.

## Runtime y límites actuales

PHP 8.4-FPM, PostgreSQL, LibreOffice `/usr/bin/libreoffice`, conectividad local y permisos efectivos deben quedar listos antes de una prueba de extremo a extremo. El CD no recarga Apache ni cambia OPcache o realpath cache global; sí realiza un graceful reload de PHP-FPM tras cada switch o restauración. Scheduler/queue y su reinicio por release requieren coordinación con la configuración final del servidor.

Para diagnosticar una pantalla blanca o una release vieja servida, comparar `readlink -f /var/www/paz-salvo-private/current`, `cat /var/www/paz-salvo-private/current/RELEASE_SHA` y `curl -fsS -H 'Host: pazsalvo.aaud.local' http://127.0.0.1/healthz`. Comprobar que el SHA JSON coincide. Luego comparar `current/public/build/manifest.json` con las rutas `/build/assets/` del HTML de `curl -fsS -H 'Host: pazsalvo.aaud.local' http://127.0.0.1/login` y consultar cada asset con el mismo Host. Un SHA distinto o hashes de manifest distintos señalan que el runtime web aún sirve otra release; revisar el reload FPM en el job. No borrar manualmente la release anterior mientras pueda haber requests o workers usándola.

No usar `php artisan serve` ni `npm run dev` en producción. `GET /healthz` valida Laravel, PostgreSQL, cache, storage y LibreOffice; la conversión QR → XLSX → PDF se prueba en CI con datos sintéticos. Revisar `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=false` para el HTTP actual, `USER_TEMPORARY_PASSWORD`, `PUBLIC_VERIFICATION_BASE_URL`, `APP_ALLOWED_HOSTS` e `INTERNAL_ALLOWED_CIDRS` antes de abrir el sitio. El acceso debe permanecer restringido a la red interna AAUD; firewall, VPN, backups, monitoreo y continuidad siguen sujetos a validación operativa.
