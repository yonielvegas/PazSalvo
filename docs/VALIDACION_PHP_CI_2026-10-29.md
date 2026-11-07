# Validación PHP 8.3 y CI local — 29/10/2026

> Estado actualizado: los cinco pendientes solicitados fueron corregidos. Los controles locales de validación están verdes; véase «Corrección de pendientes y repetición de CI» al final. Los resultados anteriores se conservan como historial.

## PHP y las CVE solicitadas

| Componente | Al iniciar esta sesión | Al terminar | Resultado |
|---|---|---|---|
| Ubuntu CLI/FPM y componentes PHP 8.3 | `8.3.6-0ubuntu0.24.04.13` | Misma revisión | Ya corregido para CVE-2026-109618/109619 |
| AAUD OAUTH: portal, scheduler, worker | PHP `8.3.36` | PHP `8.3.36` | Ya corregido para ambas CVE |

[Canonical USN-8948-1](https://ubuntu.com/security/notices/USN-8948-1) identifica `.24.04.13` como revisión corregida. Los avisos primarios de PHP para [CVE-2026-109618](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) y [CVE-2026-109619](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp) indican PHP 8.3.36 como versión corregida upstream. Ubuntu conserva el número base 8.3.6 y aplica los parches retroactivamente: `php -v` por sí solo no demostraba vulnerabilidad.

El historial real de dpkg registra la actualización **`8.3.6-0ubuntu0.24.04.12` → `8.3.6-0ubuntu0.24.04.13` el 23/10/2026**. Se verificaron `php8.3`, `cli`, `common`, `curl`, `fpm`, `gd`, `intl`, `mbstring`, `opcache`, `pgsql`, `readline`, `xml` y `zip`: todos los instalados están en `.13`.

**Paquetes actualizados en esta sesión: ninguno**, porque el objetivo de parche ya estaba cumplido. No se reinstalaron paquetes, cambiaron ramas, añadieron repositorios, actualizaron Ubuntu/Laravel/Keycloak ni reconstruyeron contenedores. APT muestra `.13` como instalada y candidata en sus índices disponibles; la actualización de índices con `sudo -n apt-get update` no pudo realizarse porque sudo requiere contraseña. Esto no impide verificar que la revisión ya instalada cumple el mínimo oficial.

PHP-FPM permanece **activo**, PID observado 788, arrancado el **28/10/2026 15:00:10**, después de la instalación del parche. **No se reinició el servicio compartido**. Se verificaron sus módulos: dom, fileinfo, gd, mbstring, pdo_pgsql, pgsql, SimpleXML, xml, xmlreader, xmlwriter, zip, curl e intl presentes. Composer confirma los requisitos de plataforma tanto en Ubuntu como dentro del portal Docker. El PHP-FPM del contenedor también informa 8.3.36.

## Comprobaciones locales equivalentes a CI

Se leyeron `.github/workflows/ci.yml`, `deploy-production.yml` y `rollback-production.yml`. No se ejecutaron despliegues, empaquetado/activación de releases, rollback, commits, pushes ni workflows remotos.

| Comprobación | Paz y Salvo | AAUD OAUTH |
|---|---|---|
| PHPUnit | **808 aprobadas, 7.003 aserciones** | **164 aprobadas, 1.415 aserciones** |
| Smoke documental exigido por CI | **1 aprobada, 11 aserciones** | No corresponde |
| Composer validate estricto / plataforma | Aprobados; simulación de instalación desde lock sin cambios necesarios | Aprobados, incluidos requisitos dentro de Docker |
| Composer audit | **Sin avisos** | Aviso existente de `symfony/mime` 7.4.19, CVE-2026-45070 |
| Symfony HTTP Foundation / Mime | **Ambos 7.4.22**, sin cambios de versión | No se actualizaron dependencias por esta tarea de PHP |
| Pint `--test` | **Falla: 11 archivos de formato**, enumerados en el log; documentos/scripts anteriores y dos archivos de tests | Aprobado |
| `npm ci`, lint/TypeScript y build | Aprobados | Aprobados |
| Vitest | No está configurado | **43 aprobadas** |
| Playwright del workflow | **20 aprobadas, 1 fallida** | No se ejecutó el flujo institucional con contraseñas reales |
| Auditoría NPM | Avisos existentes en Vite | Avisos existentes en Vite/Vitest/mocker/qs |
| Pruebas de transición | **18 Python aprobadas**; script shell aprobado, con servicios simulados/FPM privado | No corresponde |
| Bootstrap de producción | Seeder ejecutado dos veces correctamente **solo en BD de pruebas** | Orquestación de bootstrap simulada: **4 aprobadas** |
| Sincronización concurrente | Cubierta por PHPUnit | Prueba real con PostgreSQL efímero y dos procesos: aprobada |
| Herramientas adicionales | `git diff --check` aprobado | Puertos: 5 aprobadas; seguridad del reset: 3 aprobadas; SVG validado |

No se atribuyen a PHP fallos anteriores de formato, dependencias o fixtures. No se debilitaron validaciones ni aserciones para ocultarlos.

Errores locales pendientes:

- Playwright `tests/e2e/single-role.spec.ts:18`: espera habilitar «Crear usuario» tras completar únicamente los datos locales, sin resolver/desactivar la selección SSO requerida por el formulario actual. El botón sigue correctamente deshabilitado. No es un error del intérprete ni se modificó esa protección.
- `scripts/test-schema.py:31` de AAUD OAUTH prohíbe cualquier `Schema::table(` y falla ante la migración incremental existente `2026_10_20_000001_scope_local_identity_uniqueness_to_active_access.php`. Las pruebas PHPUnit de esquema/integración sí pasan. No se eliminó la migración ni se cambió la aserción.
- Pint de Paz y Salvo y avisos Composer/NPM indicados arriba. La equivalencia local de CI **no está completamente verde**.
- El job `package-production` declara PHP 8.4 y los scripts heredados de despliegue también lo referencian; el job de pruebas usa 8.3. No se ejecutó ese empaquetado ni se instaló PHP 8.4. No se certifica el despliegue con esas configuraciones a partir de pruebas locales en 8.3.

## Aislamiento e integridad

PHPUnit utilizó exclusivamente `paz_oauth_rename_20261029_testing`; Playwright y los seeders, la base separada `paz_php_ci_e2e_20261029_testing`. Se aplicaron migraciones normales en la base nueva, sin ejecutar `migrate:fresh`. El caché de pruebas y Spatie permaneció en memoria; configuración/vistas/archivos de sesión del navegador se aislaron. El portal utilizó `aaud_test`; las pruebas adicionales de concurrencia crearon su propio clúster temporal dentro de AAUD OAUTH. Las pruebas de transición usaron mocks o un FPM privado, sin reiniciar servicios del sistema.

La comparación final de huellas de usuarios, roles, permisos, identidades, asociaciones, estados y operaciones Outbox y migraciones operativas de Paz y Salvo resultó **idéntica en todos los conjuntos inspeccionados**. Se comprobó además la conexión PostgreSQL y el GET técnico autenticado hacia AAUD OAUTH:

- Ricardo Vargas, ID 15: `operador`, acceso activo, versión remota 4.
- Ana Duarte, ID 16: `consulta`, acceso activo, versión remota 2.

La autorización operativa de ambos sigue devolviendo `true`; no se volvió a contaminar Spatie. No se cambiaron identidades, usuarios, permisos, versiones ni sincronización.

Las pruebas funcionales completas incluyen entrada SSO desde la URL de tarjeta y desde login, callback firmado con identidad sintética, autorización final HTTP 200, login local, rechazo de accesos inválidos y logout según `auth_method`: local a `/login`; OIDC al portal sin solicitar logout central. El navegador comprobó el botón AAUD OAUTH real y el inicio OIDC hacia Keycloak. Se levantó y detuvo un servidor de desarrollo temporal para ese smoke; no se utilizó ninguna contraseña institucional ni se certificó un nuevo login interactivo humano contra AD.

## Estado remoto

Rama local: `sso-integration`; HEAD consultado: `609fab8eb7f67a41484d2683b4935e1bfde1a2cd`. GitHub CLI no está autenticado. La conexión GitHub disponible devolvió **cero ejecuciones de tipo pull request para ese SHA**; esa herramienta no lista todos los eventos de Actions. **No se pudo confirmar un resultado remoto completo y no se declara GitHub Actions aprobado**. Los cambios locales sin commit tampoco quedan certificados por una ejecución remota de HEAD.

## Evidencia

Registros y resultados: `docs/php-security-validation/` de ambos proyectos. En Paz y Salvo destacan `dpkg-current.txt`, `php-package-upgrade-history.txt`, `fpm-state.txt`, `phpunit.log`, `pint.log`, `e2e.log`, `composer-audit.json`, `frontend-clean.log`, `integrity.json` y `live-runtime.json`.


## Corrección de pendientes y repetición de CI

Correcciones implementadas, sin actualizar PHP ni desplegar:

1. **AAUD OAUTH:** `symfony/mime` **7.4.19 → 7.4.22**, actualización selectiva de un único paquete Composer. Laravel y las demás dependencias PHP permanecen en sus versiones. `composer audit` queda sin avisos; la [corrección oficial del mantenedor](https://github.com/symfony/symfony/security/advisories/GHSA-vqc8-7275-q272) confirma esta versión reparada.
2. **NPM:** Paz y Salvo, Vite **8.1.2 → 8.1.6**. Se elimina el aviso alto de launch-editor y los avisos asociados sin cambiar de versión mayor. AAUD OAUTH, Vite **6.4.4 → 6.4.5**, Vitest/mocker **4.1.11 → 4.1.12** y la dependencia transitiva qs **6.16.0 → 6.16.1**. Se conservaron las dependencias directas existentes; qs sigue siendo transitiva, sin añadir overrides. Se actualizaron únicamente los transitivos necesarios. Auditorías NPM: **cero avisos en ambos proyectos**.
3. **Pint:** formato corregido en `tests/Feature/Oidc/OidcLoginTest.php` y `PermissionCacheIsolationTest.php`. El nuevo `pint.json` conserva el preset Laravel y excluye únicamente `docs/rename-evidence` y `docs/php-security-validation`, que contienen instantáneas, registros y cachés generados. No se excluyen código de aplicación ni pruebas, ni se reformatearon las evidencias históricas. La configuración ejecutable del navegador dentro de las herramientas de evidencia sí se adaptó al router de pruebas, por ser necesaria para reproducir el CI aislado.
4. **Playwright:** `single-role.spec.ts` verifica primero que crear esté bloqueado sin identidad, selecciona una identidad sintética SSO, comprueba campos institucionales de solo lectura, crea el usuario mediante el backend real y comprueba el vínculo. Conserva las aserciones de selección única y el cambio de rol operador → consulta, verificando que el vínculo SSO permanece. `tests/e2e/server.php` simula exclusivamente el contrato HTTP del directorio y del token técnico; **no simula la escritura ni la autorización del backend**. El router requiere entorno testing, opt-in explícito y base real terminada en `_testing`; usa caché en memoria, bloquea peticiones HTTP no previstas y no consulta AD/Keycloak. `playwright.config.ts` utiliza este router y evita reutilizar un servidor existente.
5. **Esquema:** `scripts/test-schema.py` sustituye la prohibición de `Schema::table` por verificaciones del contrato final. Mantiene las quince tablas exactas, semillas repetibles, unicidad issuer/subject, múltiples roles, claves foráneas entre aplicaciones y auditoría inmutable. Añade pruebas reales de que una identidad histórica revocada no reserva el ID local, dos accesos activos no pueden compartirlo y el reemplazo después de revocar conserva el historial. Construye dos clústeres privados independientes en modo PostgreSQL single-user; no ejecuta `down()`, borrado de tablas ni migraciones sobre bases operativas.

### Resultados finales locales

| Control | Paz y Salvo | AAUD OAUTH |
|---|---|---|
| Composer validate estricto, instalación desde lock y plataforma | Aprobados | Aprobados |
| Composer audit | **0 avisos** | **0 avisos** |
| npm audit | **0 avisos** | **0 avisos** |
| Pint | **Aprobado** | **Aprobado** |
| TypeScript/lint y build | **Aprobados**, tras npm ci | **Aprobados**, tras npm ci |
| PHPUnit | **808/808; 7.003 aserciones** | **164/164; 1.415 aserciones** |
| Smoke documental | **1/1; 11 aserciones** | No corresponde |
| Playwright del workflow | **21/21** | No corresponde a estos pendientes |
| Vitest | No configurado | **43/43** |
| Transiciones simuladas y FPM privado | **18/18 Python y script shell aprobados** | No corresponde |
| Semilla de producción repetida | Dos ejecuciones correctas, solo BD de pruebas | Bootstrap simulado: **4/4** |
| Esquema final y concurrencia de sincronización | Cubiertos por PHPUnit | **Ambos scripts aprobados** |
| Puertos, validación de reset y branding | No corresponde | **5/5, 3/3 y SVG aprobado** |
| git diff --check | Aprobado | Sin repositorio Git; no se creó uno |

**Los controles locales de validación previos al despliegue están verdes.** Se repitieron los controles fallidos y sus regresiones relacionadas; no se ejecutaron empaquetado/activación de releases, despliegues ni rollback. La configuración heredada de empaquetado en PHP 8.4 no se modificó ni se certifica aquí: PHP continúa en la rama 8.3 ya parcheada.

La prueba PHP del portal utilizó la imagen de pruebas existente con el vendor actualizado montado en solo lectura. **Las imágenes de los servicios activos no fueron reconstruidas ni recreadas**: los cambios de dependencias y frontend permanecen preparados en el código/lock y deberán incorporarse mediante el siguiente despliegue autorizado. No se afirma que una imagen activa anterior ya contenga Mime 7.4.22.

Las huellas operativas antes/después coinciden en usuarios, roles, permisos, identidades, asociaciones, Outbox, versiones y migraciones inspeccionadas. Ana ID 16 conserva consulta y Ricardo ID 15 conserva operador; ambos mantienen autorización. No se modificaron datos operativos, Keycloak, Active Directory ni servicios PHP. No hubo commits ni pushes.

### Resultado remoto y evidencia de la corrección

Se consultaron nuevamente las ejecuciones de pull request asociadas al HEAD `609fab8eb7f67a41484d2683b4935e1bfde1a2cd` de `sso-integration`: resultado vacío. La herramienta no cubre todos los eventos de Actions y los cambios continúan sin commit. **No se declara GitHub Actions aprobado ni se provocó una ejecución remota.**

Los nuevos resultados se guardan en `docs/php-security-validation/ci-fixes/` de cada proyecto, separados de los registros históricos. Incluyen auditorías, formato, builds, suites PHP/navegador, esquema, concurrencia e integridad. El script y la prueba de navegador no se relajaron para ocultar fallos: incorporan verificaciones adicionales del vínculo SSO y del índice parcial.
