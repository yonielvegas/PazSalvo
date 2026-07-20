# Checklist AIG

Todo elemento de esta lista REQUIERE VALIDACIÓN EN SERVIDOR DE PRODUCCIÓN.

- Dominio privado definitivo.
- Dominio público definitivo del monolito validador.
- DNS y certificados TLS.
- Firewall, VPN y rangos CIDR institucionales.
- Trusted proxies o balanceador.
- PostgreSQL con usuario de aplicación de mínimo privilegio.
- Usuario separado y preferiblemente solo lectura para el monolito público.
- Backups cifrados y restauración probada.
- Monitoreo, logs centralizados y alertas.
- Secret manager o procedimiento institucional para `.env`.
- Hardening de Nginx, PHP-FPM, Linux y LibreOffice.
- NTP y zona horaria `America/Panama`.
- RPO, RTO, alta disponibilidad y respuesta a incidentes.
