# Runbook de despliegue privado

Este documento aplica al monolito privado. No usar `php artisan serve` ni `npm run dev` en producción.

## Requisitos de runtime

- PHP 8.3 con `dom`, `fileinfo`, `gd`, `mbstring`, `pdo_pgsql`, `pgsql`, `xml` y `zip`.
- PostgreSQL accesible con una cuenta propia de la aplicación.
- LibreOffice Calc disponible en modo headless; `LIBREOFFICE_BINARY` debe resolver a un ejecutable.
- Un servicio web/PHP-FPM y un scheduler/worker acordes con la configuración finalmente confirmada en servidor.
- Node no será necesario en runtime si `public/build` llega dentro del artefacto.

`POR CONFIRMAR EN SERVIDOR`: versiones/paths reales de PHP-FPM, servidor web, LibreOffice, PostgreSQL, supervisor/systemd, cron y permisos POSIX.

## Artefacto y releases (diseño para la fase de CD)

```text
releases/<release>/
├── código
├── vendor/
└── public/build/
shared/
├── .env
└── storage/
current -> releases/<release>
```

El artefacto inmutable incluirá código versionado, `vendor/` instalado con `--no-dev` y `public/build/`. Excluirá `.git`, `.env`, `node_modules`, logs, caches y temporales. Esta estructura todavía no debe crearse desde CI.

## Construcción del futuro artefacto

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
```

## Activación futura de un release

Estos comandos son candidatos para la siguiente fase; no constituyen todavía un workflow de CD:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan queue:restart
```

Las migraciones se revisarán y aprobarán por release antes de cualquier `php artisan migrate --force`. Varias migraciones históricas son potencialmente destructivas. Nunca ejecutar automáticamente `migrate:fresh`, `migrate:refresh`, `migrate:reset` ni rollback de migraciones en producción.

No ejecutar `php artisan db:seed` automáticamente. `MasterDataSeeder` contiene únicamente roles/permisos idempotentes; `DevelopmentBootstrapSeeder` crea cuentas/agencias QA y puede desactivar agencias, por lo que se niega a correr en producción.

## Storage persistente

Todo `storage/` será compartido entre releases. En particular deben preservarse plantillas, logos, firmas, QR, XLSX y PDF bajo las rutas configuradas por `PAZ_SALVO_*`. El usuario de PHP-FPM/CLI requiere lectura y escritura; el despliegue no debe borrar ni reemplazar este árbol.

## Health y smoke tests

- `GET /healthz` valida aplicación, PostgreSQL, cache, escritura/lectura/borrado de una sonda en storage y que el binario configurado sea ejecutable. Solo devuelve booleanos y estado `200`/`503`.
- La conversión completa no se ejecuta en cada health check porque sería costosa.
- CI ejecuta `php artisan test --filter=CertificateDocumentTest`, que crea datos sintéticos y valida QR PNG → XLSX → LibreOffice → PDF sin invocar Widergy.
- Tras activar un release, consultar `/healthz`; además confirmar `libreoffice --version` con el usuario de runtime. Una conversión real adicional debe realizarse solo con fixture controlado fuera del flujo oficial.

## Validaciones previas

- `APP_ENV=production`
- `APP_DEBUG=false`
- `SESSION_SECURE_COOKIE=true`
- `USER_TEMPORARY_PASSWORD` configurada por la AAUD para restablecimientos administrativos.
- `PUBLIC_VERIFICATION_BASE_URL` apunta al monolito público y termina en `/verificar`.
- `APP_ALLOWED_HOSTS` contiene solo el dominio privado definitivo.
- `INTERNAL_ALLOWED_CIDRS` contiene rangos institucionales confirmados por AIG.

## Prohibiciones

- No incluir `.env` ni secretos en el artefacto.
- No ejecutar seeders, migraciones destructivas ni conversiones con datos productivos desde CI.
- No construir sobre el directorio `current` ni modificar una release mientras atiende solicitudes.

## REQUIERE VALIDACIÓN EN SERVIDOR DE PRODUCCIÓN

TLS, firewall, VPN, trusted proxies, PostgreSQL, backups, monitoreo, secret manager, hardening Linux, AppArmor/SELinux para LibreOffice, RPO/RTO y alta disponibilidad.
