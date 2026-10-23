# Inicio institucional OIDC — configuración de desarrollo

La implementación añade Authorization Code con PKCE S256, state y nonce de un solo uso.
El cliente de login es exclusivamente `pazysalvo-web`; `pazysalvo-sync` y el AccessOutbox
no intervienen. Se usa `firebase/php-jwt` 7.2 para verificar RS256/JWKS y después se verifican
issuer, audience/azp, nonce, tiempos y hashes opcionales. Referencias:
[OpenID Connect Core, validación de ID Token](https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation),
[PHP-JWT](https://github.com/googleapis/php-jwt).

## Variables privadas

Configurar manualmente en el entorno local (no subir secretos):

```dotenv
APP_URL=http://localhost:8000
AAUD_OIDC_ENABLED=true
AAUD_OIDC_ISSUER=http://localhost:8080/realms/aaud
AAUD_OIDC_CLIENT_ID=pazysalvo-web
AAUD_OIDC_CLIENT_SECRET=
AAUD_OIDC_REDIRECT_URI=http://localhost:8000/auth/aaud/callback
SESSION_COOKIE=pazsalvo_session
SESSION_SAME_SITE=lax
```

- Si `pazysalvo-web` es confidencial, rellenar su secreto privado de ese cliente.
  Con secreto se usa `client_secret_basic`; nunca utilizar el secreto de `pazysalvo-sync`.
  Si está configurado como cliente público, dejar el secreto vacío y conservar PKCE obligatorio.
- El issuer debe coincidir exactamente con Keycloak y con las vinculaciones guardadas;
  no se corrigen ni se crean vinculaciones a partir de correo o username.
- La redirect URI debe coincidir con APP_URL más `/auth/aaud/callback`, sin comodines.
  Usar el mismo hostname (`localhost`) al abrir Paz y Salvo y al configurar sus URLs.
- Mantener cookie exclusiva de Paz y Salvo, distinta de AAUD AUTH, host-only y claves APP_KEY
  independientes. Las cookies no distinguen puertos: dos aplicaciones en localhost requieren
  nombres de cookie diferentes. No se comparte la sesión de Laravel con AAUD AUTH.
- Mantener el almacenamiento de sesión existente (`database` es el predeterminado); no usar
  `SESSION_DRIVER=cookie`. El state, nonce y verificador PKCE son temporales y solo se guardan
  en la sesión propia del servidor, nunca tokens ni secretos del cliente.
- Mantener un cache con locks y `add` atómico para impedir callbacks concurrentes/repetidos.
  En desarrollo, database o file; con varios servidores, database/Redis compartido. No usar array
  o null fuera de testing. No requiere tablas nuevas: se utilizan sesión/cache existentes.
- Los timeouts y CA reutilizan la configuración HTTP de AAUD AUTH. No se desactiva TLS.
  HTTP se permite solamente para hosts loopback en entorno local/testing; en producción, HTTPS
  y cookies Secure. Mantener sincronizada la hora de WSL/servidores.

No se cambia la activación de sincronización automática ni sus credenciales. El login OIDC
puede activarse independientemente de `AAUD_AUTH_ENABLED` y `AAUD_AUTH_AUTOMATIC`.

## Keycloak y entrada desde el portal

En el cliente existente `pazysalvo-web`, comprobar manualmente:

- Realm `aaud`.
- Standard Flow (Authorization Code) habilitado.
- PKCE S256 obligatorio.
- Firma de ID Tokens RS256.
- Valid Redirect URI exacta: `http://localhost:8000/auth/aaud/callback`.
- Client authentication y secreto coherentes con las variables anteriores.

URL de inicio que debe usar el enlace Paz y Salvo del portal AAUD AUTH:

`http://localhost:8000/auth/aaud/redirect`

Esta URL también es el destino del botón «Iniciar sesión con AAUD AUTH» en `/login`.
No se usa el endpoint de callback como enlace inicial. No se aceptan URLs externas
`returnTo`/`redirect` del navegador. No se requiere CORS ni compartir sesiones entre aplicaciones:
las redirecciones son navegación del navegador y el intercambio del código ocurre en PHP.
Si ya existe sesión Keycloak, este puede reutilizarla: no se envía `prompt=login` ni `max_age`.

El usuario debe estar vinculado explícitamente, activo, con un único rol web activo y sus
permisos locales. El portal debe tener confirmado su acceso para mostrar la aplicación;
este login no publica roles/accesos ni consulta al portal en cada petición. Los roles del
JWT se ignoran. No se crean usuarios ni se modifica el perfil al autenticarse.

Si existe configuración Laravel cacheada, ejecutar tras los ajustes privados:

```bash
php artisan config:clear
```

Arranque habitual de desarrollo:

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
```

Abrir `http://localhost:8000/login`. Mantener el scheduler existente de sincronización según
su documentación; el login OIDC no necesita un worker ni migraciones adicionales.

## Sesiones y políticas locales

La sesión local se regenera y aplica session_version, sesión única, expiración absoluta,
inactividad, estado del usuario y autorización local. Una vinculación eliminada o sustituida
invalida una sesión OIDC en su siguiente petición mediante consulta local.

SSO no cambia la contraseña, no desbloquea intentos fallidos y no borra la obligación local
de cambio de contraseña. Esa obligación se aplica al login por email; no se exige cambiar
la contraseña local durante una sesión OIDC verificada. El logout sigue siendo el POST local
existente con CSRF. No invoca logout de Keycloak ni cierra sesiones de otras aplicaciones.

Los tokens se descartan después de verificar la identidad. No se guardan en sesión, base,
frontend ni logs; los errores son genéricos. En el proxy/webserver no registrar query strings
de `/auth/aaud/callback` (contienen un código OAuth temporal). Las respuestas OIDC usan
no-store y no-referrer. No habilitar depuración en producción.

## Pruebas y alcance

Pruebas automatizadas: claves RSA efímeras, Keycloak/HTTP simulados, PostgreSQL exclusivo
terminado en `_testing` y bloqueo de red HTTP. Cubren firmas/claims, state/nonce/PKCE,
consumo/replay, vinculaciones/roles locales, bloqueo independiente, logout y regresión.
No se utilizaron identidades ni credenciales institucionales reales. No se modifican
AAUD AUTH, Keycloak, AD ni el sistema de sincronización.

Validación final de esta fase: 756 pruebas PHP y 6.450 aserciones aprobadas, incluidas
54 pruebas específicas OIDC (786 aserciones); 30 pruebas Chromium aprobadas. TypeScript,
compilación Vite en `/tmp/pazsalvo-phase6-ui`, PHP lint, Pint, Composer validate y
`git diff --check` aprobados. Evidencia PHPUnit en
`/tmp/pazsalvo-phase6-c7ve982s/{oidc,full-suite}.xml`. El clúster temporal se detuvo.
No se ejecutó login institucional real ni se modificaron bases normales, configuraciones
privadas, Keycloak, Active Directory o AAUD AUTH. No hubo migraciones nuevas, commits ni pushes.
