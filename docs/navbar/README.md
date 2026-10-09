# Navbar responsive y modal de factura

## Diseño

- El header usa cuatro columnas Grid: logo, navegación, KPI globales y cuenta. A partir de 901 px permanece en una sola fila.
- Entre 901 y 1120 px la navegación muestra iconos con nombre accesible y `title`, y los KPI usan rótulos cortos. Entre 1121 y 1280 px se reducen espacios y tipografía secundaria. Desde 1281 px se muestran todos los rótulos.
- A 900 px o menos, la barra superior conserva las cuatro zonas. La navegación y la cuenta pasan a menús accesibles por teclado. Los tres valores KPI permanecen visibles; al abrirlos se muestran los nombres y valores completos. Solo un menú puede quedar abierto a la vez.
- El módulo activo se determina por la URL de Inertia, incluidas las rutas de detalle del historial y las subsecciones de configuración. Se marca con `aria-current="page"` y fondo institucional.
- El modal de factura cambió únicamente el placeholder solicitado. A 400 px o menos se redujo el relleno del modal y el tamaño del placeholder para que el texto completo sea visible; `maxLength`, validación y envío permanecen intactos.

## Evidencia visual local y de CI

La prueba `navbar-responsive.spec.ts` revisa 320, 375, 768, 1024, 1280, 1440 y 1920 px, además de zoom de 100 %, 125 % y 150 %. Sus capturas se crean con `testInfo.outputPath()` en `test-results/`, sin guardarlas en esta documentación.

Ejecutar `npx playwright test tests/e2e/navbar-responsive.spec.ts` y abrir `playwright-report/index.html` para revisarlas localmente. En GitHub Actions, el artefacto temporal `playwright-evidence-<run_id>` incluye las mismas evidencias durante siete días.

## Verificación y despliegue

Playwright comprueba las cuatro zonas sin superposición, la ausencia de scroll horizontal, el estado activo en rutas principales y secundarias, los permisos, los menús, los KPI completos, el cierre de sesión y el placeholder. La suite completa E2E pasó con 19 pruebas. La suite PHP pasó con 180 pruebas y 1.214 aserciones sobre PostgreSQL temporal. El build y TypeScript pasaron.

No se cambió el cálculo de los KPI, las rutas, los permisos ni la base de datos. El riesgo visual restante corresponde a nombres de usuario excepcionalmente largos y cifras KPI de muchos dígitos; comprobarlos con datos representativos durante revisión. No se ha ejecutado GitHub Actions para estos cambios locales y no se ha desplegado. Seguir el [runbook de producción](../production/runbook.md) y su procedimiento de reversión después de aprobación.
