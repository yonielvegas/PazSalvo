# Historial de certificados: filtros e indicadores

## Consultas y reglas

- El permiso `ver historial` y la política `PazSalvoPolicy::viewAny` siguen controlando el acceso. No se aplica un filtro nuevo por agencia.
- El formulario usa la ruta existente `GET /paz-salvos`. `elaborado_por` es el ID de `users`, comparado con `paz_salvos.generated_by`; `nac` conserva su nombre interno y aparece como «Número de Cliente».
- Los rangos rápidos escriben `fecha_desde` y `fecha_hasta`. El servidor los interpreta en `America/Panama` sobre `issued_at`, con límite inferior inclusivo y límite superior exclusivo al inicio del día siguiente.
- El historial y ambos grupos de indicadores incluyen certificados `generated` y `cancelled`. Los estados técnicos `processing` y `error` permanecen fuera del historial.
- **Total** incluye generados y anulados. **Vencidos** cuenta solo `generated` con `expires_at < ahora`; **vigentes** cuenta solo `generated` con `expires_at >= ahora`. Los anulados pertenecen al total, pero a ninguno de los otros dos KPI. El límite temporal coincide con `PazSalvo::publicStatus()`.
- Cada grupo se calcula con una agregación condicional de PostgreSQL. El historial aplica los filtros antes de agregar; la barra de navegación usa una consulta global separada. Los valores proceden de todos los resultados autorizados, no de la página actual.
- Los KPI globales se calculan al renderizar cada respuesta privada para usuarios con `ver historial`. No hay caché que invalidar después de generar o anular un documento. El selector carga autores únicos con certificados históricos, incluidos los inactivos, y ordena por operador, supervisor y demás roles.
- Los índices existentes sobre `issued_at`, `expires_at` y `(generated_by, issued_at)` cubren los nuevos filtros principales. No se requiere migración.

## Verificación local

Se usó una instancia PostgreSQL aislada en `/tmp`, con una base terminada en `_testing`. No se usaron datos de producción.

- Backend: `php artisan test` — 180 pruebas y 1,214 aserciones correctas.
- Navegador: `npx playwright test` — 15 pruebas correctas, incluidas las públicas y las nuevas del historial.
- `npm run typecheck`, `npm run build`, `vendor/bin/pint --test`, `composer validate --strict` y `git diff --check` correctos.
- Auditorías: `composer audit` y `npm audit --audit-level=high` sin avisos después de actualizar solo las versiones afectadas en los lockfiles.
- Pruebas de producción locales: 15 pruebas Python y `tests/production/test_runtime.sh` correctas.
- El pipeline remoto de GitHub Actions requiere una ejecución en una rama o PR. Este cambio no se ha desplegado.

## Evidencia visual

Las capturas de las pruebas responsive son resultados temporales, por lo que no se versionan en `docs/history/screenshots/`. Ejecutar `npx playwright test tests/e2e/history-enhancements.spec.ts` y consultar `playwright-report/index.html` para revisarlas localmente. GitHub Actions publica `playwright-evidence-<run_id>` como artefacto temporal durante siete días.

## Despliegue y reversión

No hay migración ni endpoint nuevo. El riesgo principal es la carga de las dos agregaciones por solicitud del historial y la agregación global por respuesta privada autorizada. Revisar latencia y planes de consulta con el volumen real antes de aprobar el despliegue. Los lockfiles incluyen versiones corregidas de Laravel, Flysystem y paquetes npm; ejecutar nuevamente CI y la prueba de navegador con el artefacto de producción.

Seguir el procedimiento de publicación y reversión de [runbook](../production/runbook.md). Si falla la verificación tras publicar, activar la versión anterior con el mecanismo de reversión existente y comprobar acceso al historial, consulta y validación pública. Mantener el ticket en estado previo a producción hasta revisión, despliegue autorizado y verificación en producción.
