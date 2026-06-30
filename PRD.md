# PRD — Sistema de Generación de Paz y Salvo AAUD

## 1. Nombre del proyecto

**Paz y Salvo AAUD**

Sistema interno para consultar el estado de deuda de un cliente mediante NAC/número de cliente, validar si está paz y salvo, buscar la información complementaria en un Excel maestro, llenar una plantilla de Excel y generar un PDF imprimible.

---

## 2. Objetivo del sistema

Crear una aplicación web sencilla, rápida y mantenible que permita:

1. Ingresar un número de cliente/NAC.
2. Consultar el endpoint externo de deudas.
3. Determinar si el cliente está paz y salvo.
4. Mostrar deuda pendiente si existe.
5. Si el balance es 0, buscar los datos del cliente en un Excel maestro.
6. Cargar la información en una plantilla Excel.
7. Convertir la plantilla generada a PDF.
8. Mostrar el PDF en pantalla para visualizar, imprimir y descargar.
9. Guardar en PostgreSQL la información recibida desde el endpoint externo y el historial de paz y salvos generados.

---

## 3. Stack tecnológico recomendado

El proyecto debe desarrollarse como un **monolito Laravel moderno**.

```txt
Laravel
Inertia
React
Bun
Tailwind CSS
PostgreSQL
PhpSpreadsheet
LibreOffice Headless
```

### Justificación

Este sistema no necesita frontend y backend separados. El flujo es administrativo, corto y centrado en formularios, consultas, archivos y generación documental.

La arquitectura recomendada es:

```txt
React/Inertia → Laravel → Endpoint externo Widergy
                     ↓
                 PostgreSQL
                     ↓
              Excel maestro / plantilla
                     ↓
              Excel generado → PDF
```

---

## 4. Configuración de base de datos

El proyecto debe usar PostgreSQL.

### Nombre sugerido de la base de datos

```env
DB_DATABASE=paz_salvo_aaud
```

### Configuración `.env`

```env
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=paz_salvo_aaud
DB_USERNAME=yonielvegas
DB_PASSWORD=aaud.123
```

### Comando sugerido para crear la base de datos

Desde terminal:

```bash
createdb -U yonielvegas paz_salvo_aaud
```

O desde `psql`:

```sql
CREATE DATABASE paz_salvo_aaud;
```

No se debe versionar el archivo `.env`.

---

## 5. Alcance funcional

### Módulo 1 — Pantalla principal de búsqueda

Al cargar el sistema, lo primero que debe aparecer es una pantalla limpia con:

```txt
Input: Número de cliente / NAC
Botón: Buscar
```

El usuario debe poder buscar de dos formas:

1. Presionando Enter.
2. Presionando el botón Buscar.

Validaciones:

```txt
El campo es obligatorio.
Solo debe aceptar números.
Debe limpiar espacios antes y después.
Debe mostrar mensaje si el NAC está vacío.
Debe mostrar estado de carga mientras consulta.
```

---

### Módulo 2 — Consulta al endpoint externo

Cuando el usuario busca un NAC, Laravel debe llamar primero al siguiente endpoint:

```txt
GET https://utilitygo.widergy.com/api/v1/accounts/complete_debts?client_number={client_number}
```

Ejemplo:

```txt
https://utilitygo.widergy.com/api/v1/accounts/complete_debts?client_number=34787
```

La respuesta esperada es:

```json
{
  "response": "881e1f7d-009e-4ac7-9980-c3cd9ec02498",
  "job_id": "881e1f7d-009e-4ac7-9980-c3cd9ec02498",
  "url": "https://utilitygo-api-4.widergy.com/async_request/jobs?id=881e1f7d-009e-4ac7-9980-c3cd9ec02498"
}
```

Luego Laravel debe consultar el endpoint del job:

```txt
GET https://utilitygo-api-4.widergy.com/async_request/jobs?id={job_id}
```

Ejemplo:

```txt
https://utilitygo-api-4.widergy.com/async_request/jobs?id=881e1f7d-009e-4ac7-9980-c3cd9ec02498
```

---

## 6. Regla principal de negocio

El cliente está paz y salvo únicamente cuando:

```txt
balances.total_balance <= 0
```

Ejemplo de cliente con deuda:

```json
"balances": {
  "total_balance": 130.51,
  "expired_balance": 0,
  "non_expired_balance": 130.51
}
```

Resultado:

```txt
No está paz y salvo.
```

Ejemplo de cliente sin deuda:

```json
"balances": {
  "total_balance": 0,
  "expired_balance": 0,
  "non_expired_balance": 0
}
```

Resultado:

```txt
Está paz y salvo.
```

---

## 7. Comportamiento cuando el cliente tiene deuda

Si `total_balance` es mayor que 0, el sistema debe mostrar un modal o popup animado, visualmente claro y moderno.

### El modal debe mostrar

```txt
Título: No está paz y salvo
Mensaje: El cliente mantiene saldo pendiente.
Total adeudado: B/. {total_balance}
```

También debe mostrar el detalle de las deudas con monto mayor que 0.

Ejemplo:

```txt
Saldo de este mes Energía(JUN/2026) — B/. 54.80
Saldo de este mes Aseo(JUN/2026) — B/. 10.00
Saldo a 30 días Energía(MAY/2026) — B/. 55.71
Saldo a 30 días Aseo(MAY/2026) — B/. 10.00
```

Debe ignorar visualmente las deudas con monto 0, salvo que se decida mostrarlas en una sección secundaria.

### Botones del modal

```txt
Cerrar
Nueva búsqueda
```

No debe permitir generar paz y salvo si el balance es mayor que 0.

---

## 8. Comportamiento cuando el cliente está paz y salvo

Si `total_balance` es 0, el sistema debe mostrar una tarjeta con la información base del cliente.

La información puede venir de dos fuentes:

1. Endpoint externo.
2. Excel maestro.

La fuente principal para el documento será el **Excel maestro**.

### Datos a mostrar

```txt
NAC / Número de cliente
Nombre completo
Distrito
Corregimiento
Dirección
Balance total
Estado: Paz y salvo
```

Debe mostrarse un botón:

```txt
Generar Paz y Salvo
```

---

## 9. Excel maestro de clientes

El sistema debe tener un archivo Excel maestro con la información de clientes.

Ruta sugerida:

```txt
storage/app/templates/clientes.xlsx
```

### Columnas requeridas

```txt
NAC
Nombre completo
Distrito
Corregimiento
Direccion
```

### Datos de prueba sugeridos

```txt
NAC    | Nombre completo                       | Distrito      | Corregimiento             | Direccion
34787  | LEIDA AMANDA TERRADO SANTAMARIA       | SAN MIGUELITO | AMELIA DENIS DE ICAZA     | LOS ANDES N1
3465   | CLIENTE DE PRUEBA                     | PANAMÁ        | SIN CORREGIMIENTO         | SIN DIRECCIÓN
10001  | JUAN PEREZ RODRIGUEZ                  | PANAMÁ        | BELLA VISTA               | CALLE 50
10002  | MARIA GONZALEZ MARTINEZ               | SAN MIGUELITO | OMAR TORRIJOS             | VILLA LUCRE
```

Si el cliente está paz y salvo según el endpoint, pero no aparece en el Excel maestro, el sistema debe mostrar un mensaje:

```txt
El cliente está paz y salvo, pero no fue encontrado en el Excel maestro.
No se puede generar el documento hasta completar sus datos.
```

---

## 10. Plantilla Excel de paz y salvo

El sistema debe tener una plantilla Excel base.

Ruta sugerida:

```txt
storage/app/templates/plantilla_paz_y_salvo.xlsx
```

La plantilla debe ser creada inicialmente con un diseño de prueba.

### Celdas sugeridas para cargar información

```txt
B8  = Número de cliente / NAC
B9  = Nombre completo
B10 = Distrito
B11 = Corregimiento
B12 = Dirección
E5  = Fecha de emisión
E6  = Folio / Número de documento
```

### Texto base sugerido en la plantilla

```txt
AUTORIDAD DE ASEO URBANO Y DOMICILIARIO

PAZ Y SALVO

Se certifica que el cliente identificado con el número de cliente {NAC},
a nombre de {Nombre completo}, ubicado en {Dirección}, corregimiento de
{Corregimiento}, distrito de {Distrito}, no mantiene saldo pendiente
registrado al momento de la consulta.

Documento generado el día {Fecha}.
```

---

## 11. Generación de documentos

Cuando el usuario presiona **Generar Paz y Salvo**, el sistema debe:

1. Validar nuevamente que el último resultado consultado tiene `total_balance = 0`.
2. Buscar el cliente en el Excel maestro usando el NAC.
3. Abrir la plantilla Excel.
4. Cargar los datos en las celdas configuradas.
5. Generar una copia `.xlsx`.
6. Convertir la copia `.xlsx` a `.pdf`.
7. Guardar ambos archivos.
8. Registrar el paz y salvo generado en la base de datos.
9. Mostrar el PDF en pantalla.

### Rutas sugeridas de archivos generados

```txt
storage/app/generated/paz-salvos/{year}/{month}/paz_salvo_{client_number}_{folio}.xlsx
storage/app/generated/paz-salvos/{year}/{month}/paz_salvo_{client_number}_{folio}.pdf
```

Ejemplo:

```txt
storage/app/generated/paz-salvos/2026/06/paz_salvo_34787_PS-20260630-000001.xlsx
storage/app/generated/paz-salvos/2026/06/paz_salvo_34787_PS-20260630-000001.pdf
```

---

## 12. Visualización del PDF

Después de generar el PDF, el sistema debe mostrar una pantalla con:

```txt
Visor del PDF
Botón Imprimir
Botón Descargar PDF
Botón Nueva búsqueda
```

El PDF debe poder verse dentro del navegador usando un `iframe` o una ruta protegida de Laravel.

Para imprimir sin descargar manualmente, el botón **Imprimir** puede abrir el PDF en una nueva pestaña o ejecutar impresión desde el visor.

---

## 13. Persistencia en base de datos

Cada consulta al endpoint externo debe almacenarse en PostgreSQL.

La estrategia más simple y segura será:

```txt
Siempre guardar una nueva consulta en debt_queries.
Siempre actualizar el cliente en clients con la información más reciente.
Si se genera paz y salvo, crear un registro en paz_salvos.
```

Esto permite tener historial sin complicarse comparando si la respuesta cambió.

---

# 14. Modelo de base de datos propuesto

## 14.1 Tabla `clients`

Tabla principal de clientes.

Debe seguir una estructura compatible con el estilo usado en el sistema de Reclamos:

```txt
id bigserial primary key
client_number string unique
holder_name string nullable
district string nullable
corregimiento string nullable
address text nullable
city string nullable
rate string nullable
is_active boolean default true
created_at timestamp
updated_at timestamp
```

Notas:

```txt
client_number representa el NAC.
Se usa client_number en base de datos para mantener compatibilidad con el endpoint externo.
En pantalla puede mostrarse como NAC.
```

---

## 14.2 Tabla `debt_queries`

Guarda cada consulta realizada al endpoint externo.

```txt
id bigserial primary key
client_id foreign key nullable
client_number string
job_id string nullable
job_url text nullable
status string
total_balance decimal(12,2) default 0
expired_balance decimal(12,2) default 0
non_expired_balance decimal(12,2) default 0
external_holder_name string nullable
external_address text nullable
external_city string nullable
external_rate string nullable
next_expiration_on date nullable
raw_response jsonb
queried_at timestamp
created_at timestamp
updated_at timestamp
```

Valores sugeridos para `status`:

```txt
debt_free
has_debt
not_found
error
```

Reglas:

```txt
Si total_balance <= 0, status = debt_free.
Si total_balance > 0, status = has_debt.
Si el endpoint responde sin datos suficientes, status = not_found.
Si ocurre error de conexión o timeout, status = error.
```

---

## 14.3 Tabla `debt_items`

Guarda el detalle de las deudas devueltas por el endpoint.

```txt
id bigserial primary key
debt_query_id foreign key
period string nullable
external_id string nullable
amount decimal(12,2) default 0
status string nullable
payable boolean nullable
document_type string nullable
issued_on date nullable
first_expiration_on date nullable
created_at timestamp
updated_at timestamp
```

Cada elemento del arreglo `debts` del endpoint debe almacenarse aquí.

---

## 14.4 Tabla `paz_salvos`

Guarda cada paz y salvo generado.

```txt
id bigserial primary key
folio string unique
client_id foreign key
debt_query_id foreign key
client_number string
holder_name string
district string nullable
corregimiento string nullable
address text nullable
total_balance decimal(12,2) default 0
xlsx_path text nullable
pdf_path text nullable
status string default 'generated'
generated_by foreign key nullable
generated_at timestamp
raw_snapshot jsonb nullable
created_at timestamp
updated_at timestamp
```

Valores sugeridos para `status`:

```txt
generated
cancelled
error
```

`generated_by` debe quedar nullable por ahora, por si el sistema inicia sin autenticación. Si luego se agrega login, se puede conectar con `users.id`.

---

## 15. Compatibilidad futura con Reclamos AAUD

Aunque este sistema queda separado por ahora, debe prepararse para una posible unificación futura con el sistema de Reclamos.

Reglas de compatibilidad:

```txt
Usar nombres de tablas en inglés y snake_case.
Usar id bigserial como llave primaria.
Usar timestamps created_at y updated_at.
Usar is_active cuando aplique.
Evitar nombres ambiguos como nombreCliente o numeroCliente.
Usar foreign keys reales.
Guardar archivos con rutas relativas, no absolutas.
Separar lógica en services.
No colocar lógica pesada en controllers.
No depender directamente del Excel desde los controllers.
```

Convención recomendada:

```txt
NAC en pantalla.
client_number en base de datos y backend.
holder_name para nombre del titular.
address para dirección.
district para distrito.
corregimiento para corregimiento.
```

---

## 16. Servicios Laravel requeridos

El proyecto debe separar la lógica en servicios.

### `WidergyDebtService`

Responsabilidades:

```txt
Consultar endpoint inicial.
Obtener job_id.
Consultar endpoint del job.
Manejar timeouts.
Manejar errores.
Normalizar la respuesta.
```

Métodos sugeridos:

```php
public function consult(string $clientNumber): array;
private function requestJob(string $clientNumber): array;
private function fetchJobResult(string $jobId, ?string $url = null): array;
```

---

### `DebtQueryPersistenceService`

Responsabilidades:

```txt
Actualizar o crear cliente.
Guardar consulta en debt_queries.
Guardar detalle en debt_items.
Guardar raw_response como JSONB.
Determinar status de la consulta.
```

Método sugerido:

```php
public function store(string $clientNumber, array $jobResponse, array $debtResponse): DebtQuery;
```

---

### `ClientExcelLookupService`

Responsabilidades:

```txt
Abrir Excel maestro.
Buscar cliente por NAC.
Retornar datos normalizados.
Manejar cliente no encontrado.
```

Método sugerido:

```php
public function findByClientNumber(string $clientNumber): ?array;
```

---

### `PazSalvoExcelService`

Responsabilidades:

```txt
Abrir plantilla Excel.
Cargar datos en celdas específicas.
Generar archivo XLSX final.
Retornar ruta generada.
```

Método sugerido:

```php
public function generate(array $clientData, string $folio): string;
```

---

### `PdfConversionService`

Responsabilidades:

```txt
Convertir XLSX a PDF usando LibreOffice headless.
Validar que el PDF fue creado.
Retornar ruta del PDF.
```

Método sugerido:

```php
public function convertXlsxToPdf(string $xlsxPath): string;
```

---

### `PazSalvoService`

Responsabilidades:

```txt
Validar que el cliente está paz y salvo.
Buscar datos en Excel maestro.
Generar folio.
Generar Excel.
Convertir PDF.
Crear registro en paz_salvos.
Retornar datos para el frontend.
```

Método sugerido:

```php
public function generateFromDebtQuery(int $debtQueryId): PazSalvo;
```

---

## 17. Controllers requeridos

### `PazSalvoController`

Métodos sugeridos:

```php
public function index();
public function consult(ConsultPazSalvoRequest $request);
public function generate(GeneratePazSalvoRequest $request);
public function showPdf(PazSalvo $pazSalvo);
public function downloadPdf(PazSalvo $pazSalvo);
```

---

## 18. Rutas Laravel sugeridas

```php
Route::get('/', [PazSalvoController::class, 'index'])
    ->name('paz-salvo.index');

Route::post('/paz-salvo/consultar', [PazSalvoController::class, 'consult'])
    ->name('paz-salvo.consult');

Route::post('/paz-salvo/generar', [PazSalvoController::class, 'generate'])
    ->name('paz-salvo.generate');

Route::get('/paz-salvo/{pazSalvo}/pdf', [PazSalvoController::class, 'showPdf'])
    ->name('paz-salvo.pdf');

Route::get('/paz-salvo/{pazSalvo}/download', [PazSalvoController::class, 'downloadPdf'])
    ->name('paz-salvo.download');
```

---

## 19. Frontend React/Inertia

Pantallas/componentes sugeridos:

```txt
resources/js/Pages/PazSalvo/Index.tsx
resources/js/Components/PazSalvo/SearchClientForm.tsx
resources/js/Components/PazSalvo/DebtModal.tsx
resources/js/Components/PazSalvo/ClientResultCard.tsx
resources/js/Components/PazSalvo/PdfViewer.tsx
resources/js/Components/PazSalvo/LoadingOverlay.tsx
```

### Estado inicial

```txt
Formulario centrado.
Input grande.
Botón Buscar.
Diseño limpio.
```

### Estado cargando

```txt
Mostrar animación/spinner.
Deshabilitar input y botón.
Texto: Consultando información del cliente...
```

### Estado con deuda

```txt
Mostrar modal animado.
Mostrar total adeudado.
Mostrar detalle de deuda.
No mostrar botón generar.
```

### Estado paz y salvo

```txt
Mostrar tarjeta de cliente.
Mostrar botón Generar Paz y Salvo.
```

### Estado PDF generado

```txt
Mostrar visor de PDF.
Botones Imprimir, Descargar y Nueva búsqueda.
```

---

## 20. Validaciones

### `ConsultPazSalvoRequest`

```txt
client_number required
client_number string
client_number regex solo números
client_number max 30
```

### `GeneratePazSalvoRequest`

```txt
debt_query_id required
debt_query_id exists debt_queries.id
```

Antes de generar, el backend debe validar:

```txt
La consulta existe.
La consulta tiene total_balance <= 0.
La consulta no está en status error.
El cliente existe en el Excel maestro.
```

---

## 21. Manejo de errores

### Error de endpoint inicial

Mensaje:

```txt
No se pudo iniciar la consulta del cliente. Intente nuevamente.
```

### Error de job

Mensaje:

```txt
No se pudo obtener el resultado de la consulta. Intente nuevamente.
```

### Timeout

Mensaje:

```txt
La consulta tardó más de lo esperado. Intente nuevamente.
```

### Cliente no encontrado en Excel

Mensaje:

```txt
El cliente está paz y salvo, pero no fue encontrado en el Excel maestro.
```

### Error al generar Excel

Mensaje:

```txt
No se pudo generar la plantilla del paz y salvo.
```

### Error al convertir PDF

Mensaje:

```txt
No se pudo convertir el documento a PDF.
```

---

## 22. Reglas de actualización de datos

Para mantenerlo simple:

```txt
Cada consulta crea un nuevo registro en debt_queries.
Cada consulta actualiza el registro del cliente en clients usando updateOrCreate por client_number.
Cada deuda individual se guarda en debt_items asociada a la consulta.
Cada paz y salvo se vincula con la consulta exacta que permitió generarlo.
```

Esto evita lógica compleja de comparación y mantiene auditoría.

Ejemplo:

```txt
Cliente 34787 se consulta hoy con deuda de 130.51.
Se guarda debt_query #1.

Cliente 34787 se consulta mañana con deuda de 0.
Se guarda debt_query #2.
Se actualiza clients.
Se permite generar paz y salvo.
Se crea paz_salvo vinculado a debt_query #2.
```

---

## 23. Folio de paz y salvo

Cada documento generado debe tener un folio único.

Formato sugerido:

```txt
PS-{YYYYMMDD}-{ID}
```

Ejemplo:

```txt
PS-20260630-000001
```

El folio debe guardarse en la tabla `paz_salvos`.

---

## 24. Librerías necesarias

### PHP

```bash
composer require phpoffice/phpspreadsheet
```

Para PostgreSQL, Laravel ya soporta `pgsql`, pero el servidor debe tener habilitada la extensión:

```txt
pdo_pgsql
pgsql
```

### Frontend

```bash
bun install
```

Dependencias sugeridas:

```bash
bun add @inertiajs/react
bun add lucide-react
bun add framer-motion
```

Tailwind debe instalarse según el starter kit usado.

### Sistema operativo

Instalar LibreOffice:

```bash
sudo apt update
sudo apt install libreoffice -y
```

Comando esperado para conversión:

```bash
libreoffice --headless --convert-to pdf --outdir {output_directory} {xlsx_file}
```

---

## 25. Estructura de carpetas sugerida

```txt
app/
├── Http/
│   ├── Controllers/
│   │   └── PazSalvoController.php
│   └── Requests/
│       ├── ConsultPazSalvoRequest.php
│       └── GeneratePazSalvoRequest.php
│
├── Models/
│   ├── Client.php
│   ├── DebtQuery.php
│   ├── DebtItem.php
│   └── PazSalvo.php
│
├── Services/
│   ├── WidergyDebtService.php
│   ├── DebtQueryPersistenceService.php
│   ├── ClientExcelLookupService.php
│   ├── PazSalvoExcelService.php
│   ├── PdfConversionService.php
│   └── PazSalvoService.php
│
resources/
├── js/
│   ├── Pages/
│   │   └── PazSalvo/
│   │       └── Index.tsx
│   └── Components/
│       └── PazSalvo/
│           ├── SearchClientForm.tsx
│           ├── DebtModal.tsx
│           ├── ClientResultCard.tsx
│           ├── PdfViewer.tsx
│           └── LoadingOverlay.tsx
│
storage/
├── app/
│   ├── templates/
│   │   ├── clientes.xlsx
│   │   └── plantilla_paz_y_salvo.xlsx
│   └── generated/
│       └── paz-salvos/
```

---

## 26. Variables `.env` adicionales

```env
WIDERGY_COMPLETE_DEBTS_URL=https://utilitygo.widergy.com/api/v1/accounts/complete_debts
WIDERGY_JOB_BASE_URL=https://utilitygo-api-4.widergy.com/async_request/jobs

PAZ_SALVO_CLIENTS_EXCEL=templates/clientes.xlsx
PAZ_SALVO_TEMPLATE_EXCEL=templates/plantilla_paz_y_salvo.xlsx
PAZ_SALVO_OUTPUT_DIR=generated/paz-salvos

LIBREOFFICE_BINARY=libreoffice
```

---

## 27. Criterios de aceptación generales

### Consulta

```txt
Dado que ingreso un NAC válido,
cuando presiono Buscar,
entonces el sistema consulta el endpoint externo y muestra el resultado correspondiente.
```

### Cliente con deuda

```txt
Dado que el endpoint devuelve total_balance mayor que 0,
cuando finaliza la consulta,
entonces el sistema muestra un modal indicando que el cliente no está paz y salvo y lista las deudas pendientes.
```

### Cliente sin deuda

```txt
Dado que el endpoint devuelve total_balance igual a 0,
cuando finaliza la consulta,
entonces el sistema muestra la tarjeta del cliente y permite generar paz y salvo.
```

### Cliente no encontrado en Excel

```txt
Dado que el cliente está paz y salvo,
pero su NAC no existe en el Excel maestro,
cuando intento generar el documento,
entonces el sistema muestra un error y no genera PDF.
```

### Generación correcta

```txt
Dado que el cliente está paz y salvo y existe en el Excel maestro,
cuando presiono Generar Paz y Salvo,
entonces el sistema llena la plantilla, genera el Excel, convierte a PDF, guarda el registro y muestra el PDF.
```

### Persistencia

```txt
Dado que realizo una consulta al endpoint externo,
cuando el sistema recibe respuesta,
entonces guarda la respuesta en PostgreSQL.
```

### Actualización

```txt
Dado que consulto un cliente que ya existe,
cuando llega nueva información del endpoint,
entonces el sistema actualiza el registro del cliente y guarda una nueva consulta histórica.
```

---

## 28. Prioridad del MVP

### Fase 1 — MVP obligatorio

```txt
Crear proyecto Laravel + Inertia + React + Bun.
Configurar PostgreSQL.
Crear migraciones.
Crear pantalla de búsqueda.
Consultar endpoint externo.
Guardar respuesta en base de datos.
Mostrar si debe o está paz y salvo.
Crear Excel maestro de prueba.
Crear plantilla Excel de prueba.
Generar Excel final.
Convertir a PDF.
Mostrar PDF en pantalla.
Permitir descargar PDF.
```

### Fase 2 — Mejoras

```txt
Agregar login.
Agregar historial de paz y salvos generados.
Agregar filtros por fecha y NAC.
Agregar reimpresión de paz y salvo.
Agregar administración del Excel maestro.
Agregar carga masiva de clientes.
Agregar permisos por rol.
Agregar auditoría.
```

---

## 29. Decisiones técnicas importantes

```txt
No usar Next.js separado.
No crear API separada.
No consultar el endpoint externo desde React directamente.
No guardar rutas absolutas de archivos.
No generar paz y salvo si total_balance > 0.
No depender de los datos del endpoint para el documento final si existe Excel maestro.
Siempre guardar la respuesta cruda del endpoint en raw_response.
Siempre crear historial de consultas.
```

---

## 30. Resultado esperado

Al finalizar el MVP, el usuario podrá:

1. Entrar al sistema.
2. Escribir un número de cliente.
3. Consultar si tiene deuda.
4. Ver claramente si no está paz y salvo.
5. Ver el detalle de deuda si existe.
6. Generar paz y salvo si el balance es 0.
7. Visualizar el PDF en pantalla.
8. Imprimirlo o descargarlo.
9. Tener historial persistido en PostgreSQL de las consultas y documentos generados.
