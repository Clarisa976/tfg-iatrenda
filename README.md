# Iatrenda - Sistema de Gestión Médica

## Tabla de Contenidos

- [Descripción del Proyecto](#descripción-del-proyecto)
- [Arquitectura del Sistema](#arquitectura-del-sistema)
- [Entorno de Desarrollo](#entorno-de-desarrollo)
- [Entorno de Producción](#entorno-de-producción)
- [Instalación Local](#instalación-local)
- [Configuración](#configuración)
- [Uso del Sistema](#uso-del-sistema)
- [Despliegue](#despliegue)
- [Backups Automáticos](#backups-automáticos)

##  Descripción del Proyecto

Iatrenda es un sistema de gestión médica completo que permite la administración de pacientes, profesionales de la salud, citas médicas y documentación clínica. 

### Características Principales

- Gestión de usuarios (pacientes, profesionales, administradores)
- Sistema de citas médicas con calendario
- Gestión de documentos médicos
- Almacenamiento seguro en AWS S3
- Backups automáticos programados
- Autenticación JWT
- Interfaz responsive y moderna

## Arquitectura del Sistema

### Stack

**Frontend:**
- React 19.1.0
- React Router DOM
- Reactstrap 9.2.3
- Bootstrap 5.3.6
- Axios para comunicación con API
- React Big Calendar
- React Toastify


**Backend:**
- PHP 8.2+
- Slim Framework 4.14
- JWT Authentication
- PDO para PostgreSQL
- PHPMailer
- AWS SDK PHP

**Base de Datos:**
- PostgreSQL 15+
- Timezone: Europe/Madrid

**Servicios en la Nube:**
- AWS S3 (almacenamiento de archivos)
- Supabase (base de datos PostgreSQL)
- Render (backend hosting)
- Netlify (frontend hosting)
- Cron-Job.org (tareas programadas)

## Entorno de Producción

### URLs de Producción
- **Frontend:** https://clinica-petaka.netlify.app
- **Backend API:** Desplegado en Render
- **Base de Datos:** PostgreSQL en Supabase
- **Archivos:** AWS S3 Bucket
- **Backups:** Automatizados con Cron-Job.org

### Servicios Configurados
1. **Netlify** - Hosting del frontend React
2. **Render** - Hosting del backend PHP
3. **Supabase** - Base de datos PostgreSQL
4. **AWS S3** - Almacenamiento de archivos y backups
5. **Cron-Job.org** - Backups automáticos programados

## Instalación Local

### Prerrequisitos - Instalación Paso a Paso

#### 1. Instalar XAMPP (PHP 8.2+ incluido)

**Paso 1:** Ir a https://www.apachefriends.org/download.html
**Paso 2:** Descargar la versión para Windows (xampp-windows-x64-8.2.x-installer.exe)
**Paso 3:** Ejecutar el instalador como administrador
**Paso 4:** Seleccionar componentes:
- Apache
- MySQL  
- PHP
- phpMyAdmin
**Paso 5:** Instalar en `C:\xampp` (ruta por defecto)
**Paso 6:** Abrir XAMPP Control Panel y verificar que Apache inicia correctamente

```powershell
# Verificar que PHP está instalado
C:\xampp\php\php.exe --version
```

#### 2. Instalar Node.js y npm

**Paso 1:** Ir a https://nodejs.org/
**Paso 2:** Descargar la versión LTS (Long Term Support) para Windows
**Paso 3:** Ejecutar el instalador `.msi`
**Paso 4:** Seguir el asistente de instalación (dejar opciones por defecto)
**Paso 5:** Reiniciar el terminal/PowerShell

```powershell
# Verificar instalación
node --version    # Debe mostrar v18+ o superior
npm --version     # Debe mostrar v9+ o superior
```

#### 3. Instalar Composer (Gestor de dependencias PHP)

**Paso 1:** Ir a https://getcomposer.org/download/
**Paso 2:** Descargar "Composer-Setup.exe" para Windows
**Paso 3:** Ejecutar el instalador
**Paso 4:** En la pantalla "Settings Check":
- Seleccionar el PHP ubicado en `C:\xampp\php\php.exe`
**Paso 5:** Completar la instalación
**Paso 6:** Reiniciar el terminal/PowerShell

```powershell
# Verificar instalación
composer --version
```

#### 4. Instalar Git

**Paso 1:** Ir a https://git-scm.com/download/windows
**Paso 2:** Descargar la versión para Windows (64-bit)
**Paso 3:** Ejecutar el instalador
**Paso 4:** Configuraciones importantes durante la instalación:
- **Editor:** Seleccionar tu editor preferido (VS Code recomendado)
- **PATH:** "Git from the command line and also from 3rd-party software"
- **Line endings:** "Checkout Windows-style, commit Unix-style line endings"
**Paso 5:** Completar instalación

```powershell
# Verificar instalación
git --version

# Configurar Git (primera vez)
git config --global user.name "Tu Nombre"
git config --global user.email "tu.email@ejemplo.com"
```

#### 5. Verificar Instalación Completa

```powershell
# Ejecutar todos los comandos para verificar
php --version
node --version
npm --version
composer --version
git --version

# Si todos responden correctamente, estás listo para continuar
```

### Pasos de Instalación

#### 1. Clonar el Repositorio
```bash
# Navegar al directorio de XAMPP
cd C:\xampp\htdocs\Proyectos

# Clonar el proyecto
git clone https://github.com/Clarisa976/tfg-iatrenda.git
cd TFG
```

#### 2. Configurar la Base de Datos

##### Opción A: MySQL Local (XAMPP) - Para desarrollo inicial

**Paso 1:** Iniciar XAMPP Control Panel
**Paso 2:** Hacer clic en "Start" en Apache y MySQL
**Paso 3:** Abrir phpMyAdmin desde el panel o ir a http://localhost/phpmyadmin
**Paso 4:** Crear la base de datos:
- Clic en "Databases" (Bases de datos)
- Nombre: `bd_iatrenda`
- Collation: `utf8mb4_general_ci`
- Clic en "Create"

```powershell
# Alternativa por línea de comandos
C:\xampp\mysql\bin\mysql.exe -u root -p
# (Presionar Enter si no hay contraseña)
```
```sql
CREATE DATABASE bd_iatrenda;
EXIT;
```

##### Opción B: Supabase (Recomendado para producción)

**Paso 1:** Ir a https://supabase.com y crear cuenta
**Paso 2:** Clic en "New Project"
**Paso 3:** Configurar proyecto:
- Organization: seleccionar o crear
- Name: `iatrenda-db`
- Database Password: generar contraseña segura (¡guardarla!)
- Region: "West EU (Ireland)" - eu-west-1

**Paso 4:** Esperar que se complete la creación (2-3 minutos)
**Paso 5:** En el dashboard, ir a "SQL Editor"
**Paso 6:** Abrir el archivo `iatrenda_postgres.sql` y copiar todo el contenido
**Paso 7:** Pegar en el SQL Editor y ejecutar (botón "Run")
**Paso 8:** Ir a "Settings" → "Database" y anotar las credenciales:
- Host
- Database name
- Username  
- Password
- Port

#### 3. Configurar Backend PHP

**Paso 1:** Abrir PowerShell y navegar al directorio backend
```powershell
cd C:\xampp\htdocs\Proyectos\TFG\backend
```

**Paso 2:** Verificar que composer está disponible
```powershell
composer --version
```

**Paso 3:** Instalar dependencias PHP específicas del proyecto
```powershell
# El proyecto requiere estas dependencias específicas:
# - vlucas/phpdotenv: ^5.6 (variables de entorno)
# - slim/slim: ^4.14 (framework API REST)
# - firebase/php-jwt: ^6.11 (autenticación JWT)
# - phpmailer/phpmailer: ^6.10 (envío de emails)
# - aws/aws-sdk-php: ^3.344 (integración con AWS S3)

# Instalar todas las dependencias desde composer.json
composer install

# Si hay problemas, limpiar cache primero
composer clear-cache
composer install

# Verificar que las dependencias se instalaron correctamente
composer show
```

**Paso 4:** Crear archivo de configuración `.env`
```powershell
# Crear archivo .env vacío
New-Item -Name ".env" -ItemType File

```

**Paso 5:** Configurar variables de entorno según tu setup:

**Para MySQL local (XAMPP):**
```env
# Base de datos MySQL local
DB_HOST=localhost
DB_NAME=bd_iatrenda
DB_USER=root
DB_PASS=
DB_PORT=3306

# JWT
JWT_SECRETO=mi_clave_super_secreta_para_jwt_2024
JWT_EXPIRACION=3600

# AWS S3 (completar con tus credenciales reales)
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=wJalr...
AWS_REGION=eu-west-1
AWS_BUCKET=iatrenda-documents
AWS_S3_BUCKET_NAME=iatrenda-documents

# Email - Gmail (configurar App Password - ver instrucciones abajo)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=tu.email@gmail.com
SMTP_PASSWORD=tu_app_password_gmail_16_caracteres
SMTP_FROM_EMAIL=tu.email@gmail.com
SMTP_FROM_NAME="Iatrenda"
```

**Para Supabase (producción):**
```env
# Base de datos PostgreSQL Supabase
DB_HOST=db.xyzzxyzzxyzz.supabase.co
DB_NAME=postgres
DB_USER=postgres
DB_PASS=tu_password_supabase_muy_seguro
DB_PORT=5432

# JWT
JWT_SECRETO=mi_clave_super_secreta_para_jwt_2024
JWT_EXPIRACION=3600

# AWS S3 (mismas credenciales)
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=wJalr...
AWS_REGION=eu-west-1
AWS_BUCKET=iatrenda-documents
AWS_S3_BUCKET_NAME=iatrenda-documents

# Email
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=tu.email@gmail.com
SMTP_PASSWORD=tu_app_password_gmail_16_caracteres
SMTP_FROM_EMAIL=tu.email@gmail.com
SMTP_FROM_NAME="Iatrenda"
```

### Configurar Email SMTP con Gmail

Para que el sistema pueda enviar emails (notificaciones, recuperación de contraseña, etc.), necesitas configurar Gmail con una **Contraseña de Aplicación**:

#### Paso 1: Activar verificación en 2 pasos en Gmail

1. **Ir a tu cuenta de Google:** https://myaccount.google.com/
2. **Seguridad** -> **Verificación en 2 pasos**
3. **Activar la verificación en 2 pasos** (obligatorio para usar contraseñas de aplicación)
4. **Seguir el proceso** de configuración con tu teléfono

#### Paso 2: Generar Contraseña de Aplicación

1. **Volver a Seguridad** en tu cuenta de Google
2. **Buscar "Contraseñas de aplicaciones"** o ir directamente a: https://myaccount.google.com/apppasswords
3. **Seleccionar aplicación:** "Correo"
4. **Seleccionar dispositivo:** "Otro (nombre personalizado)"
5. **Escribir:** "Iatrenda"
6. **Generar** - Te dará una contraseña de 16 caracteres como: `abcd efgh ijkl mnop`
7. **Copiar esta contraseña** (sin espacios) y usarla en el `.env`

#### Paso 3: Configurar en el archivo .env

```env
# Usar tu email real y la contraseña de aplicación generada
SMTP_USERNAME=tu.email.real@gmail.com
SMTP_PASSWORD=abcdefghijklmnop
SMTP_FROM_EMAIL=tu.email.real@gmail.com
```

#### Importante:
- **NO uses tu contraseña normal de Gmail**
- **Usa la contraseña de aplicación de 16 caracteres**
- **Guarda bien esta contraseña, no se puede recuperar**
- **Si no funciona, genera una nueva contraseña de aplicación**

#### Probar configuración:
Una vez configurado, el sistema podrá enviar emails automáticamente para:
- Confirmación de registro
- Recuperación de contraseña  
- Notificaciones de citas
- Alertas del sistema

#### 4. Configurar Frontend React

**Paso 1:** Navegar al directorio frontend
```powershell
cd C:\xampp\htdocs\Proyectos\TFG\frontend
```

**Paso 2:** Verificar Node.js y npm
```powershell
node --version  # Debe ser v18+
npm --version   # Debe ser v9+
```

**Paso 3:** Instalar dependencias específicas de React
```powershell
# El proyecto requiere estas dependencias específicas:
# - react: ^19.1.0 (librería principal)
# - reactstrap: ^9.2.3 (componentes Bootstrap para React)
# - axios: ^1.9.0 (cliente HTTP para API)
# - react-big-calendar: ^1.18.0 (calendario de citas)
# - react-toastify: ^11.0.5 (notificaciones)
# - jwt-decode: ^4.0.0 (decodificar tokens JWT)
# - moment: ^2.30.1 (manejo de fechas)

# Instalar todas las dependencias desde package.json
npm install

# Si hay errores de dependencias (común con React 19), usar forzado
npm install --force

# Si hay problemas persistentes, limpiar completamente
npm cache clean --force
Remove-Item -Recurse -Force node_modules
Remove-Item package-lock.json
npm install --force
```

**Paso 4:** Crear archivo de configuración `.env`
```powershell
# Crear archivo .env para React
New-Item -Name ".env" -ItemType File

# Abrir con editor de texto
notepad .env
```

**Paso 5:** Configurar variables de entorno según tu setup:

**Para desarrollo local con XAMPP:**
```env
# URL del backend PHP (XAMPP)
REACT_APP_API_URL=http://localhost/Proyectos/TFG/backend/public

# Configuración de build
GENERATE_SOURCEMAP=false
CI=false
ESLINT_NO_DEV_ERRORS=true
```

**Para desarrollo con servidor PHP independiente:**
```env
# URL del backend PHP (servidor independiente)
REACT_APP_API_URL=http://localhost:8081

# Configuración de build
GENERATE_SOURCEMAP=false
CI=false
ESLINT_NO_DEV_ERRORS=true
```

**Paso 6:** Verificar que React funciona
```powershell
# Probar que React inicia correctamente
npm start

# Debería abrir automáticamente http://localhost:3000
# Si no, abrirlo manualmente en el navegador
```

#### 5. Configurar XAMPP para el Proyecto

**Paso 1:** Abrir XAMPP Control Panel (ejecutar como administrador si es necesario)

**Paso 2:** Iniciar servicios necesarios
- **Apache:** Clic en "Start" - OBLIGATORIO para el backend PHP
- **MySQL:** Clic en "Start" - SOLO si usas base de datos local (no Supabase)

**Paso 3:** Verificar que el proyecto es accesible
```powershell
# Probar el backend desde PowerShell
Invoke-WebRequest -Uri "http://localhost/Proyectos/TFG/backend/public" -Method GET
```

Si funciona, verás una respuesta HTTP. Si no, revisar:
- ¿Apache está corriendo (verde en XAMPP)?
- ¿La carpeta existe en la ruta correcta?
- ¿Hay conflictos de puerto (Skype, IIS, etc.)?

## Iniciar el Sistema Completo

### Método 1: XAMPP + React (Recomendado para principiantes)

**Terminal 1 - Iniciar XAMPP:**
1. Abrir XAMPP Control Panel
2. Iniciar Apache (y MySQL si usas BD local)
3. Verificar que está verde

**Terminal 2 - Iniciar React:**
```powershell
cd C:\xampp\htdocs\Proyectos\TFG\frontend
npm start
```

**Resultado:**
- Frontend React: http://localhost:3000
- Backend PHP: http://localhost/Proyectos/TFG/backend/public

### Método 2: Servidores Independientes

**Terminal 1 - Backend PHP:**
```powershell
cd C:\xampp\htdocs\Proyectos\TFG\frontend
npm run start-backend
```

**Terminal 2 - Frontend React:**
```powershell
cd C:\xampp\htdocs\Proyectos\TFG\frontend
npm run start-react
```

**Resultado:**
- Frontend React: http://localhost:3000  
- Backend PHP: http://localhost:8081

### Verificar que Todo Funciona

1. **Abrir http://localhost:3000** - Debería cargar la aplicación React
2. **Verificar conexión con backend** - La app debería comunicarse con la API
3. **Probar login/registro** - Verificar que la base de datos funciona
4. **Subir un documento** - Verificar que AWS S3 funciona (si está configurado)

### Compilación para Producción

```bash
# Desde la carpeta frontend
npm run build

# Los archivos estáticos se generarán en la carpeta 'build'
```

## Configuración Detallada

### Variables de Entorno Backend

| Variable | Descripción |
|----------|-------------|
| `DB_HOST` | Host de la base de datos |
| `DB_NAME` | Nombre de la base de datos |
| `DB_USER` | Usuario de la base de datos |
| `DB_PASS` | Contraseña de la base de datos |
| `DB_PORT` | Puerto de la base de datos |
| `JWT_SECRETO` | Clave secreta para JWT |
| `JWT_EXPIRACION` | Tiempo de expiración JWT (segundos) |
| `AWS_ACCESS_KEY_ID` | Access Key de AWS |
| `AWS_SECRET_ACCESS_KEY` | Secret Key de AWS |
| `AWS_REGION` | Región de AWS |
| `AWS_BUCKET` | Nombre del bucket S3 |
| `AWS_S3_BUCKET_NAME` | Nombre del bucket S3 (alternativo) |

### Estructura de la Base de Datos

La base de datos incluye las siguientes 13 tablas principales:

1. **persona** - Información base de todos los usuarios del sistema
2. **tutor** - Datos específicos de tutores/responsables
3. **profesional** - Información de profesionales médicos
4. **paciente** - Datos específicos de pacientes
5. **bloque_agenda** - Bloques de disponibilidad de profesionales
6. **cita** - Gestión de citas médicas
7. **historial_clinico** - Historiales clínicos de pacientes
8. **tratamiento** - Tratamientos asociados a historiales
9. **documento_clinico** - Metadatos de documentos médicos
10. **consentimiento** - Gestión de consentimientos de pacientes
11. **notificacion** - Sistema de notificaciones
12. **log_evento_dato** - Auditoría de eventos del sistema
13. **backup** - Control de backups realizados

### Configuración AWS S3

Para configurar AWS S3:

1. **Crear Bucket S3**
2. **Configurar IAM User** con permisos:
   - `s3:GetObject`
   - `s3:PutObject`
   - `s3:DeleteObject`
   - `s3:ListBucket`

3. **Configurar CORS en S3**:
```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "PUT", "POST", "DELETE"],
        "AllowedOrigins": ["*"],
        "ExposeHeaders": []
    }
]
```

## Despliegue

### Frontend (Netlify)

1. **Conectar repositorio** a Netlify
2. **Configurar build settings**:
   - Build command: `npm run build`
   - Publish directory: `build`
   - Base directory: `frontend`

3. **Variables de entorno en Netlify**:
```
REACT_APP_API_URL=https://tu-backend.render.com
GENERATE_SOURCEMAP=false
CI=false
ESLINT_NO_DEV_ERRORS=true
```

4. **Configurar redirects** - Crear archivo `_redirects` en `frontend/public`:
```
/*    /index.html   200
```

### Backend (Render)

1. **Crear Web Service** en Render
2. **Configurar build settings**:
   - Build command: `composer install --no-dev --optimize-autoloader`
   - Start command: `php -S 0.0.0.0:$PORT -t public`
   - Root directory: `backend`

3. **Variables de entorno en Render**:
   - Todas las variables del archivo `.env`
   - `PORT` se configura automáticamente

### Base de Datos (Supabase)

1. **Crear proyecto** en Supabase
2. **Ejecutar script** `iatrenda_postgres.sql`
3. **Configurar RLS** (Row Level Security) si es necesario
4. **Obtener credentials** de conexión

## Backups Automáticos

### Configuración en Cron-Job.org

1. **Crear cuenta** en https://cron-job.org
2. **Configurar cron job**:
   - URL: `https://tu-backend.render.com/backup`
   - Schedule: `0 1 * * *` (diario a las 1:00 AM)
   - HTTP Method: GET

### Sistema de Backup

El sistema realiza backups automáticos que incluyen:

- Estructura completa de la base de datos
- Todos los datos con integridad referencial
- Metadatos y checksums
- Almacenamiento en AWS S3
- Logs detallados de cada backup

### Restauración desde Backup

```bash
# Descargar backup desde S3
# Restaurar en PostgreSQL
psql -U postgres -d bd_iatrenda -f backup_file.sql
```

---

## Notas Adicionales

- **Timezone:** Configurado para `Europe/Madrid`
- **Encoding:** UTF-8 en todos los componentes
- **SSL:** Requerido para conexiones PostgreSQL (Supabase)
- **CORS:** Configurado para permitir comunicación entre dominios
- **Security:** JWT para autenticación, variables de entorno para secrets

