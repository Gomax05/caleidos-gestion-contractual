# Caleidos Gestión Contractual

Prototipo de demostración con PHP 8.1+, HTML, CSS y JavaScript de interfaz, sin frameworks ni paquetes npm/Composer. SQLite guarda los registros y el servidor conserva los documentos por versión. Se mantienen los perfiles demo y la contraseña `demo1234`; la firma y el envío son simulaciones.

## Abrir la aplicación

Ejecuta `index.cmd` con doble clic desde el Explorador de archivos, o ejecuta `./index.cmd` desde PowerShell en esta carpeta. El lanzador encuentra PHP en el PATH o en `C:\xampp\php\php.exe`, inicia el servidor en segundo plano y abre el navegador. El puerto inicial es 8765; si está ocupado, elige uno libre hasta 8785. Solo escucha en `127.0.0.1`.

El iniciador abre siempre **`index.php?page=login`**, incluso si existe una sesión anterior. Abrir la dirección raíz también muestra el login; la navegación interna utiliza rutas explícitas para conservar la sesión durante el trabajo. El cierre de sesión vuelve al login.

Para detener el servidor ejecuta `detener.cmd`. Cerrar la pestaña no detiene PHP. Si el servidor ya estaba abierto durante esta actualización, detenlo e inicia nuevamente para aplicar los límites de carga de respaldos. Consulta `storage/server-error.log` si aparece un error.

Inicio manual desde esta carpeta:

```powershell
php -d upload_max_filesize=64M -d post_max_size=70M -S 127.0.0.1:8765 -t public public/router.php
```

Abre `http://127.0.0.1:8765/`. PHP requiere PDO, pdo_sqlite, mbstring, iconv y zip. El equipo de desarrollo ya tiene esas extensiones. La validación de documentos mantiene un máximo de **10 MB por PDF**; el límite superior del servidor permite cargar respaldos ZIP de hasta 64 MB.

La única carpeta pública es `public/`. Para Apache configura ese directorio como DocumentRoot. No abras el HTML de `respaldo-estatico/` ni uses Live Server para esta versión. GitHub Pages no ejecuta PHP.

## Recorrido por área

| Correo de acceso | Funciones de demostración |
| --- | --- |
| comercial@caleidos.pe | Preparar borradores, enviar solicitudes, validar su fase, atender correcciones, subir versiones, organizar el seguimiento de sus contratos y simular envío/firma. |
| legal@caleidos.pe | Consultar sus pendientes, revisar documentos, aprobar Legal o solicitar correcciones. |
| finanzas@caleidos.pe | Validar importes y condiciones en su fase, aprobar o solicitar correcciones. |
| gerencia@caleidos.pe | Revisar las validaciones anteriores y dar la aprobación final, o solicitar correcciones. |
| admin@caleidos.pe | Supervisar todas las tareas, consultar perfiles, preparar solicitudes/borradores, organizar responsables y plazos, crear y recuperar respaldos. No aprueba por otras áreas. |

Los cinco perfiles iniciales usan **`demo1234`**. «Vista demo» permite recorrer los perfiles activos. Se conserva el alcance de demostración y no se separó un modo de producción.

El login ofrece **Crear usuario**, **Gestionar usuarios** y ayuda con la contraseña. Crear o gestionar requiere iniciar sesión como Administrador; no hay autorregistro público con selección libre de permisos. Administración puede crear perfiles, editar nombre/correo/área, cambiar contraseñas y activar/desactivar accesos. La contraseña inicial es `demo1234` si no se indica otra. No puede desactivarse el perfil propio, el último activo de un área ni un perfil con tareas pendientes. Los perfiles con contratos o historial conservan su área para mantener la trazabilidad. No se eliminan usuarios ni se envían correos de recuperación.

## Solicitudes y borradores

1. En Comercial, abre **Nueva solicitud**. Puedes completar parte de los datos y seleccionar **Guardar borrador**, incluso sin PDF.
2. En **Borradores**, continúa la preparación. Si ya había un PDF, se conserva salvo que adjuntes otro.
3. **Enviar a Legal** exige los datos completos, monto positivo, correo válido, vigencia correcta y un PDF de hasta 10 MB. El borrador se convierte en contrato en una transacción, sin duplicarlo.
4. Tras el envío, los datos pasan al flujo de versiones: no se editan como borrador.

Cada tarea recibe un responsable de su área y un plazo de **3 días calendario por defecto**, configurable entre 1 y 30 al crear la solicitud. Al aprobar, observar, corregir, enviar o firmar, se actualiza la siguiente tarea y su fecha límite. Comercial responsable y Administración pueden ajustar el responsable compatible y la fecha desde el detalle. No se generan notificaciones externas.

## Paneles y seguimiento

- **Panel de mi área:** explica la responsabilidad del perfil, muestra sus tareas (o las de todo el equipo para Administración), tareas vencidas, contratos próximos a vencer en 30 días y acuerdos firmados.
- **Mis pendientes:** incluye revisiones, correcciones, preparación de envío y seguimiento de firma según la persona asignada. Permite filtrar vencidos y tareas que vencen hoy.
- **Contratos:** búsqueda por cliente, título, código o servicio; filtros por estado, área, servicio y plazo; paginación en SQL. El CSV respeta la vista filtrada.
- **Tiempo por etapa:** las nuevas asignaciones registran inicio y cierre. El panel muestra promedios de revisiones completadas y cada contrato conserva sus periodos de atención.
- **Documentos, observaciones y trazabilidad:** consultas y paginación en base de datos. Los PDF requieren una sesión. La trazabilidad puede exportarse con sus filtros.
- **Comparación de cláusulas:** compara texto manual manteniendo el orden y las repeticiones. Una línea reordenada aparece retirada y agregada. Hay contadores de cambios, filas alineadas y un modo simplificado acotado para textos extensos. No extrae ni interpreta PDF.

El flujo permanece **Legal → Comercial → Administración y Finanzas → Gerencia**. Una nueva versión retorna a la primera área afectada o a una fase anterior pendiente; conserva aprobaciones anteriores y obliga a repetir las posteriores. El envío, las observaciones del cliente y la firma mantienen su recorrido demo.

## Base local y migraciones

`config/database.php` selecciona la conexión PDO. Por defecto se utiliza `storage/caleidos.sqlite`. `DatabaseSchema` prepara el esquema inicial y `MigrationRunner` registra los cambios en `schema_migrations`. `DatabaseSeeder` solo carga los ejemplos cuando la base no tiene usuarios.

Tablas: `users`, `contracts`, `contract_versions`, `approvals`, `observations`, `contract_events`, `contract_drafts`, `review_periods` y `schema_migrations`.

La migración `002_demo_workspaces` conserva los registros anteriores. Antes de modificar una base SQLite existente, crea automáticamente una copia `backups/pre_migration_*.sqlite` al lado de la base. Los contratos abiertos reciben responsable y un plazo calculado desde su último movimiento; la medición de tiempos comienza al actualizar, sin reconstruir una duración histórica que no existía. Los acuerdos firmados no reciben tareas nuevas.

Las sesiones y archivos están en `storage/sessions/` y `storage/documents/`, fuera de `public/`. Las variables `DB_SQLITE_PATH` y `CALEIDOS_STORAGE_PATH` permiten aislar bases y almacenamiento para pruebas. No se importan automáticamente los datos del antiguo almacenamiento del navegador.

## Respaldos y recuperación

En Administración abre **Respaldos → Crear respaldo completo**. El ZIP contiene un manifiesto de registros y todos los PDF referenciados por contratos y borradores. Descárgalo para guardarlo fuera del equipo. Recuperar contratos conserva los usuarios y sus credenciales actuales, incluidos los creados después del respaldo. Se rechazan respaldos que requieran perfiles inexistentes o áreas incompatibles.

Para recuperar, selecciona el ZIP y escribe **RESTAURAR**. Se verifican formato, nombres, tamaños, integridad y documentos; nunca se extraen rutas arbitrarias. El sistema crea un respaldo de seguridad del estado actual antes de modificar registros y aplica la recuperación con una transacción. Los archivos locales ajenos al respaldo se conservan. Si falla la recuperación, se revierten los registros y se retiran únicamente los archivos recién creados por ese intento.

Límites de esta demostración: ZIP de 64 MB, manifiesto de 25 MB, hasta 1000 documentos de 10 MB y un total de 200 MB descomprimidos. Las sesiones no se incluyen en el respaldo. Los ZIP y bases locales están excluidos de Git.

## SQL Server

Se conserva SQLite como motor de la demostración. Para la conexión futura:

1. Ejecuta `database/schema.sqlserver.sql` y después `database/migrations/002_demo_workspaces.sqlserver.sql` en la base elegida. El segundo script se puede repetir sin aplicar la misma migración dos veces. PHP también lo hace mediante `MigrationRunner` si aún está pendiente.
2. Instala PDO_SQLSRV y el controlador ODBC compatibles con tu PHP.
3. Configura las variables y reinicia el servidor:

```powershell
$env:DB_DRIVER = 'sqlsrv'
$env:DB_SQLSRV_DSN = 'sqlsrv:Server=localhost;Database=CaleidosGestionContractual;TrustServerCertificate=1'
$env:DB_USERNAME = 'tu_usuario'
$env:DB_PASSWORD = 'tu_clave'
.\index.cmd
```

El DSN es un ejemplo de desarrollo local. Las consultas paginadas usan la sintaxis correspondiente a SQLite o SQL Server. **No se ha probado contra una instancia real de SQL Server**; los scripts no transfieren automáticamente los datos de SQLite. Para volver al archivo local, establece `DB_DRIVER=sqlite` y reinicia.

## Organización del código

```text
app/Core/                         Conexión, consultas, coordinación HTTP, CSV y renderizado
app/Database/                     Esquema, migraciones y carga de ejemplos
app/Modules/Auth/                 Acceso y cambio de perfiles demo
app/Modules/Contracts/            Solicitudes, borradores, asignaciones y flujo
app/Modules/Dashboard/            Panel por área y métricas
app/Modules/Reviews/              Acciones de revisión y observaciones
app/Modules/Documents/            Documentos, descarga y comparación
app/Modules/Traceability/         Historial y exportación filtrada
app/Modules/Users/                Creación, edición y gestión de accesos demo
app/Modules/Backups/              Respaldo y recuperación
app/Views/                        Estructura, tablas y paginación compartidas
config/                           Configuración de base y almacenamiento
database/                         Esquemas SQL, migraciones y datos demo
public/assets/css/                Diseño adaptable
public/assets/js/modules/         Interacciones de formularios y navegación
storage/                          Datos y archivos locales; no publicar
tests/                            Verificación aislada
```

`ApplicationController` coordina la petición y delega en controladores por módulo. Los servicios contienen reglas y los repositorios consultas preparadas. Las vistas generan HTML con condiciones de presentación. Clases en PascalCase; métodos y variables PHP en camelCase; tablas, columnas y campos en snake_case.

## Pruebas

```powershell
php tests/migration_test.php
php tests/workflow_test.php
php tests/backup_test.php
node --test tests/http_test.cjs
```

Las pruebas usan bases y archivos temporales: no modifican los contratos del usuario. Verifican migración y respaldo previo, conservación de datos, perfiles fijos, permisos, fechas, asignaciones, paginación SQL, comparación, borradores, PDF y ciclo completo, además de creación/recuperación de respaldos. Node.js solo es necesario para las pruebas HTTP.

El respaldo estático original, en la carpeta exterior `respaldo-estatico/`, permanece fijo. Para historial del código se puede usar Git sobre esta carpeta; no incluyas bases, documentos, sesiones ni respaldos.
