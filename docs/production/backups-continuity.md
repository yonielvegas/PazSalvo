# Backups y continuidad

REQUIERE VALIDACIÓN EN SERVIDOR DE PRODUCCIÓN.

- PostgreSQL: backups cifrados, diarios como mínimo, con retención definida por AAUD/AIG.
- Documentos: respaldar `storage/app` o volumen persistente equivalente.
- Firmas y sellos: respaldar junto con documentos; son imágenes institucionales, no firmas digitales criptográficas.
- Copia externa: mantener una copia fuera del servidor principal.
- Restauración: probar restauración completa y parcial antes de producción.
- RPO/RTO: deben ser definidos por AAUD/AIG.
- Ransomware: retención inmutable o WORM si la infraestructura lo permite.
- Rollback: respaldo previo a migraciones y artefactos versionados.
