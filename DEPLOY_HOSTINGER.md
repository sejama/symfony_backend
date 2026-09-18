# 🚀 Guía de Despliegue en Hostinger Premium (API de Email)

Esta guía detalla paso a paso cómo desplegar la API de email de Symfony en un plan **Hostinger Premium Web Hosting** (panel hPanel).

---

## 1. 📋 Requisitos Previos en Hostinger (hPanel)

### A. Verificar versión de PHP
1. Ingresa a tu panel de Hostinger (hPanel) > **Sitios web** > Administrar.
2. En la barra lateral, ve a **Avanzado** > **Configuración de PHP**.
3. Selecciona **PHP 8.2** o **PHP 8.3**.
4. En la pestaña **Extensiones de PHP**, asegúrate de que estén activas:
   - `intl`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `iconv`, `ctype`, `tokenizer`, `xml`, `fileinfo`.

### B. Crear la cuenta de Email para SMTP
Para que Hostinger autorice el envío y pase las validaciones de SPF, DKIM y DMARC:
1. En hPanel, ve a **Emails** > **Cuentas de email**.
2. Crea una casilla, por ejemplo: `notificaciones@tudominio.com` o `contacto@tudominio.com`.
3. Anota la contraseña asignada.
4. Datos de conexión SMTP de Hostinger:
   - **Servidor SMTP**: `smtp.hostinger.com`
   - **Puerto SSL**: `465` (o TLS `587`)
   - **Usuario**: Tu dirección completa (`notificaciones@tudominio.com`)
   - **Contraseña**: La contraseña que definiste

---

## 2. 📂 Estructura de Directorios y Document Root

En los planes compartidos de Hostinger, el servidor web (LiteSpeed / Apache) necesita que el **Document Root** apunte a la carpeta `public/` de Symfony para que el archivo `public/index.php` atienda las peticiones y los archivos sensibles (`.env`, `src/`, `var/`, `vendor/`) **queden protegidos fuera del acceso público web**.

### Opción A (Recomendada): Usar un Subdominio (ej: `api.tudominio.com`)
1. En hPanel > **Dominios** > **Subdominios**.
2. Nombre del subdominio: `api`
3. Marca la casilla **Carpeta personalizada para el subdominio**.
4. Ruta de la carpeta: `public_html/backend/public` (o `subdomains/api/backend/public`).
5. Así, todas las peticiones a `https://api.tudominio.com/api/...` entrarán directamente a `public/index.php`.

### Opción B: Carpeta dedicada fuera o dentro de `public_html`
Si colocas la carpeta `backend` en `public_html/backend`:
- Tu acceso HTTP será: `https://tudominio.com/backend/public/api/...`
- Verifica que el archivo `.htaccess` esté presente en `backend/public/.htaccess`.

---

## 3. 📤 Subida de Archivos al Servidor

### Método 1: Vía SSH / Git (El más rápido y profesional)
1. En hPanel > **Avanzado** > **Acceso SSH**, activa el acceso SSH y copia los datos de conexión.
2. Conéctate desde tu terminal:
   ```bash
   ssh uXXXXXX@tu-ip-o-dominio -p 65002
   ```
3. Navega al directorio deseado y clona o sincroniza el repositorio:
   ```bash
   cd public_html
   git clone <url-de-tu-repositorio> backend
   cd backend
   ```
4. Instala las dependencias sin paquetes de desarrollo:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

### Método 2: Vía Administrador de Archivos (Archivo ZIP)
1. En tu máquina local, genera un ZIP del proyecto excluyendo `var/cache/*`, `var/log/*` y `tests/`.
   *(Puedes incluir la carpeta `vendor` si la instalaste con `--no-dev`, o instalarla por SSH).*
2. En hPanel > **Archivos** > **Administrador de Archivos**, sube el ZIP y descomprímelo.

---

## 4. ⚙️ Configuración del Entorno de Producción (`.env.local`)

En la raíz del proyecto en Hostinger (junto a `.env`), crea o edita el archivo `.env.local`:

```env
###> symfony/framework-bundle ###
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=genera_un_hash_seguro_de_32_caracteres_aqui
###< symfony/framework-bundle ###

###> symfony/routing ###
DEFAULT_URI=https://api.tudominio.com
###< symfony/routing ###

###> symfony/mailer ###
# NOTA: Reemplaza '@' por '%40' en el usuario del DSN
MAILER_DSN=smtps://notificaciones%40tudominio.com:TuPasswordSeguro@smtp.hostinger.com:465
MAILER_DEFAULT_FROM_EMAIL=notificaciones@tudominio.com
MAILER_DEFAULT_FROM_NAME="Tu Empresa o Proyecto"
###< symfony/mailer ###

###> api/security ###
# Clave secreta para proteger el endpoint (debes enviarla en header X-API-KEY)
# Si se deja vacía, el endpoint opera público (solo protegido por rate limiter por IP)
MAIL_API_KEY=tu_clave_secreta_para_la_api_12345

# Dominios autorizados para CORS (tu frontend o web)
CORS_ALLOW_ORIGIN=https://tudominio.com,https://www.tudominio.com

# Filtro anti-spam activo
EMAIL_SPAM_CHECK_ENABLED=true
###< api/security ###

###> doctrine/doctrine-bundle ###
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_prod.db"
###< doctrine/doctrine-bundle ###
```

---

## 5. 🛠️ Permisos y Compilación de Caché

Ejecuta los siguientes comandos en el servidor (vía SSH):

```bash
# 1. Asegurar permisos de escritura en la carpeta var (para logs y rate limiting)
chmod -R 775 var
chmod -R 775 var/rate_limiter 2>/dev/null || mkdir -p var/rate_limiter && chmod -R 775 var/rate_limiter

# 2. Limpiar y calentar la caché en modo producción
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

# 3. (Opcional) Volcar variables de entorno a PHP puro para máximo rendimiento:
composer dump-env prod
```

---

## 6. 🧪 Verificación Post-Despliegue

### 1. Comprobar Health Check
```bash
curl -i https://api.tudominio.com/api/health
```
**Respuesta esperada:** HTTP `200 OK` con `{"success":true,"status":"ok","service":"email-api",...}`.

### 2. Comprobar Estadísticas de Rate Limiting
```bash
curl -i https://api.tudominio.com/api/email/stats
```
**Respuesta esperada:** HTTP `200 OK` con los cupos restantes de la IP.

### 3. Enviar un Email de Prueba
```bash
curl -i -X POST https://api.tudominio.com/api/email/send \
  -H 'Content-Type: application/json' \
  -H 'X-API-KEY: tu_clave_secreta_para_la_api_12345' \
  -d '{
    "to": "tu_email_personal@gmail.com",
    "subject": "Prueba de API en Hostinger",
    "body": "Hola, este es un email de prueba desde el nuevo despliegue en Hostinger."
  }'
```
**Respuesta esperada:** HTTP `200 OK` con `{"success":true,"message":"Email enviado correctamente"}`.

---

## 7. 🛡️ Monitoreo y Solución de Problemas Frecuentes

### ¿Dónde ver los logs en producción?
- **Auditoría de emails**: `var/log/email.log` (registra cada envío, IP, destinatario y bloqueos).
- **Errores del sistema**: `var/log/prod.log`.

### Error: `550 Sender address rejected`
- **Causa**: El campo `From` no coincide con la casilla SMTP autenticada en Hostinger.
- **Solución**: Asegúrate de que `MAILER_DEFAULT_FROM_EMAIL` en `.env.local` sea exactamente la misma casilla creada en Hostinger (ej: `notificaciones@tudominio.com`). La API automáticamente enruta el email del visitante a `Reply-To`.

### Error: `500 Internal Server Error`
1. Revisa `var/log/prod.log`.
2. Verifica que la carpeta `var/` tenga permisos `775`.
3. Ejecuta `php bin/console cache:clear --env=prod`.
