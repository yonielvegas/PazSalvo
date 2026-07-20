# Runbook de despliegue privado

Este documento aplica al monolito privado. No usar `php artisan serve` ni `npm run dev` en producción.

## Comandos

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan queue:restart
```

## Validaciones previas

- `APP_ENV=production`
- `APP_DEBUG=false`
- `SESSION_SECURE_COOKIE=true`
- `USER_TEMPORARY_PASSWORD` configurada por la AAUD para restablecimientos administrativos.
- `PUBLIC_VERIFICATION_BASE_URL` apunta al monolito público y termina en `/verificar`.
- `APP_ALLOWED_HOSTS` contiene solo el dominio privado definitivo.
- `INTERNAL_ALLOWED_CIDRS` contiene rangos institucionales confirmados por AIG.

## REQUIERE VALIDACIÓN EN SERVIDOR DE PRODUCCIÓN

TLS, firewall, VPN, trusted proxies, PostgreSQL, backups, monitoreo, secret manager, hardening Linux, AppArmor/SELinux para LibreOffice, RPO/RTO y alta disponibilidad.
