# Runbook de despliegue privado

El CD implementado se documenta en las secciones siguientes. Aplica exclusivamente al repositorio privado PazSalvo.

## Flujo CI → CD

Un `push` a `main` ejecuta `ci` en GitHub-hosted runners. El job `test` conserva todos sus checks. Solo después de ese job, `package-production` instala Composer `--no-dev`, compila assets y sube un artefacto asociado al SHA exacto. `deploy-production` escucha la finalización exitosa de ese mismo run de CI para un push a `main` del propio repositorio. Descarga el artefacto de ese run y verifica checksum y SHA. CI fallido, cancelado o incompleto no despliega. El runner de producción usa `[self-hosted, production, pazsalvo]`; tests y empaquetado siguen en `ubuntu-latest`.

Deploy y rollback comparten la concurrencia `pazsalvo-production`, sin cancelar operaciones en curso. El artefacto incluye código runtime, `vendor/` sin paquetes de desarrollo y `public/build/`. Excluye `.git`, `.env`, `node_modules`, `storage`, cachés, pruebas, documentación y logs. No se ejecutan Composer ni npm en producción.

## GitHub Environment

Crear el Environment `production`; se recomienda habilitar required reviewers y limitarlo a `main` según la política de AAUD. No hace falta un GitHub Secret: el `.env` productivo vive solo en el servidor y el token efímero de Actions lee el artefacto.

Variable opcional del Environment: `PRODUCTION_HEALTH_URL`, URL real accesible desde el runner y terminada en `/healthz`, sin credenciales embebidas. Para la instalación actual corresponde `http://pazsalvo.aaud.local/healthz` cuando esa ruta esté operativa. Si está vacía, deploy y rollback omiten el check HTTP y lo informan. `/healthz` usa `internal.network`, así que la IP de origen del runner debe estar permitida.

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

El administrador debe crear y revisar `shared/.env` fuera de Actions. Usar `.env.production.example` como lista de campos, sin copiarlo como configuración final sin completar valores. Esta instalación productiva funciona en la red interna AAUD por HTTP, sin reverse proxy: `APP_URL=http://pazsalvo.aaud.local`, `APP_ALLOWED_HOSTS=pazsalvo.aaud.local`, `SESSION_SECURE_COOKIE=false` y `TRUSTED_PROXIES` vacío. El portal público interno usa `PUBLIC_VERIFICATION_BASE_URL=http://pazsalvo-public.aaud.local/verificar`. `APP_DEBUG=false` sigue siendo obligatorio; si `APP_URL` pasa a HTTPS, `SESSION_SECURE_COOKIE=true` será obligatorio. Configurar PostgreSQL, Apache y el pool PHP-FPM de este sitio por separado. El sitio debe apuntar a `/var/www/paz-salvo-private/current/public`. Preparar backup y aprobar/aplicar migraciones manualmente por release antes de exponer código que dependa de ellas. No se proporciona un comando genérico de migración automática por el riesgo de las migraciones históricas. Tampoco se ejecutan seeders.

## Activación y fallos

El job crea `releases/<UTC timestamp>-<SHA corto>`, verifica `artisan`, `vendor/autoload.php`, `public/index.php` y `public/build/manifest.json`, y confirma el SHA. La release enlaza `.env` y `storage` a `shared`; solo elimina un directorio `storage` empaquetado dentro de esa nueva release antes de enlazarlo. Nunca modifica `shared/storage` ni otras releases.

Los comandos Artisan usan `/usr/bin/php8.4 -d memory_limit=256M`: `optimize:clear`, `config:cache`, `route:cache`, `view:cache` y `event:cache`. Si falta `.env`, un archivo o falla un comando, `current` no cambia. No hay migraciones ni seeders automáticos. La activación usa symlink temporal y `mv -T` atómico.

Si `PRODUCTION_HEALTH_URL` está configurada, se hacen hasta cinco intentos HTTP con timeout; solo 200 pasa. Si falla, se restaura atómicamente el `current` anterior. En el primer deploy, al no haber anterior, se retira `current`. El workflow termina en error. Si la URL falta, se informa que se omitió el check y el deploy termina tras activar.

Tras éxito se conservan como máximo tres releases preparadas: activa, inmediatamente anterior y la más reciente restante. Solo se borran directorios con ID válido dentro de `releases`; nunca `shared`. Una interrupción abrupta podría dejar una release incompleta sin `.release-ready`; revisarla manualmente antes de borrarla. Si falla la limpieza, el job falla aunque la release nueva ya esté activa; consultar `current` antes de reintentar.

## Rollback de código

En Actions ejecutar `rollback-production` con `release` vacío para enumerar las releases preparadas sin cambiar `current`. Ejecutar de nuevo con uno de esos IDs. Se validan formato, ubicación real, archivos runtime y enlaces a `shared`; luego se cambia `current` atómicamente. Si el health check configurado falla, se restaura el enlace anterior y el workflow falla. Solo cambia código: no revierte PostgreSQL, migraciones ni storage, y no ejecuta seeders.

## Runtime y límites actuales

PHP 8.4-FPM, PostgreSQL, LibreOffice `/usr/bin/libreoffice`, conectividad de health y permisos efectivos deben quedar listos antes de una prueba de extremo a extremo. El CD no recarga Apache ni PHP-FPM. Si OPcache no valida timestamps, los workers existentes pueden conservar código anterior tras cambiar el symlink; preparar fuera del workflow un reload limitado al pool de este sitio antes de depender de despliegues sin interrupción. No se asume sudo ni se añade sudoers. Scheduler/queue y su reinicio por release requieren coordinación con la configuración final del servidor.

No usar `php artisan serve` ni `npm run dev` en producción. `GET /healthz` valida Laravel, PostgreSQL, cache, storage y LibreOffice; la conversión QR → XLSX → PDF se prueba en CI con datos sintéticos. Revisar `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=false` para el HTTP actual, `USER_TEMPORARY_PASSWORD`, `PUBLIC_VERIFICATION_BASE_URL`, `APP_ALLOWED_HOSTS` e `INTERNAL_ALLOWED_CIDRS` antes de abrir el sitio. El acceso debe permanecer restringido a la red interna AAUD; firewall, VPN, backups, monitoreo y continuidad siguen sujetos a validación operativa.
