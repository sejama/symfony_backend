# Seguridad en API de Envío de Emails

## 📋 Resumen

Se han implementado múltiples capas de seguridad para prevenir el uso malintencionado del endpoint de envío de emails, incluyendo rate limiting, validaciones anti-spam, sanitización de contenido y logging completo.

## 🔒 Medidas de Seguridad Implementadas

### 1. **Rate Limiting (Limitación de Tasa)**

Previene envíos masivos limitando la cantidad de emails que una IP puede enviar en diferentes períodos de tiempo.

**Límites por defecto:**
- **Por minuto:** 2 emails
- **Por hora:** 10 emails  
- **Por día:** 50 emails

**Configuración:** [config/services.yaml](../config/services.yaml)
```yaml
parameters:
    email.rate_limit.per_minute: 2
    email.rate_limit.per_hour: 10
    email.rate_limit.per_day: 50
```

**Respuesta cuando se excede:**
```json
{
    "success": false,
    "error": "Rate limit exceeded",
    "message": "Límite de 2 emails por minuto excedido",
    "retryAfter": 60
}
```
Status Code: `429 Too Many Requests`

### 2. **Validaciones de Seguridad**

#### a) **Prevención de Inyección de Headers**
Detecta intentos de inyectar headers adicionales mediante caracteres CRLF (`\r\n`) en el asunto u otros campos.

#### b) **Detección de Spam**
Analiza el contenido buscando patrones típicos de spam:
- Palabras clave: viagra, cialis, casino, lottery, winner, etc.
- Frases sospechosas: "buy now", "click here", "limited time"
- Exceso de mayúsculas (> 50%)
- Múltiples signos de exclamación o dólares

#### c) **Límite de Contenido**
- Longitud máxima del body: 10,000 caracteres (configurable)
- Número máximo de destinatarios (to + cc + bcc): 5 (configurable)

#### d) **Detección de URLs Maliciosas**
- Bloquea acortadores de URL comunes (bit.ly, tinyurl.com, etc.)
- Limita la cantidad de URLs en el mensaje

#### e) **Sanitización de HTML**
Cuando `isHtml: true`, el contenido HTML se sanitiza para prevenir XSS:
- Solo permite tags seguros: `<p><br><strong><em><u><h1-h4><ul><ol><li><a>`
- Elimina scripts, iframes y otros elementos peligrosos

### 3. **Whitelist de Dominios (Opcional)**

Limita el envío de emails solo a dominios específicos.

**Para habilitar:**
```yaml
# config/services.yaml
parameters:
    email.security.allowed_domains: ['miempresa.com', 'cliente.com']
```

**Deshabilitado por defecto** (array vacío permite todos los dominios).

### 4. **Logging Completo**

Todos los intentos de envío se registran para auditoría y detección de abusos:

**Eventos registrados:**
- ✅ Envíos exitosos (nivel: INFO)
- ⚠️ Rate limit excedido (nivel: WARNING)
- ⚠️ Validación de seguridad fallida (nivel: WARNING)
- ❌ Errores al enviar (nivel: ERROR)

**Ubicación de logs:** `var/log/dev.log` (desarrollo) o `var/log/prod.log` (producción)

**Ejemplo de log:**
```
[2025-12-30 15:30:00] app.INFO: Email sent successfully {"ip":"192.168.1.100","to":"test@ejemplo.com","subject":"Test"}
[2025-12-30 15:30:15] app.WARNING: Rate limit exceeded {"ip":"192.168.1.100","reason":"Límite de 2 emails por minuto excedido"}
```

## 📊 Nuevo Endpoint: Estadísticas

### GET `/api/email/stats`

Permite consultar las estadísticas de envío para la IP actual.

**Respuesta:**
```json
{
    "success": true,
    "stats": {
        "ip": "192.168.1.100",
        "sentLastMinute": 1,
        "sentLastHour": 5,
        "sentLastDay": 15,
        "remainingMinute": 1,
        "remainingHour": 5,
        "remainingDay": 35
    }
}
```

**Uso recomendado:** Llamar a este endpoint antes de enviar un email para mostrar al usuario cuántos envíos le quedan disponibles.

## ⚙️ Configuración

### Ajustar límites de Rate Limiting

Edita [config/services.yaml](../config/services.yaml):

```yaml
parameters:
    # Más restrictivo (para producción)
    email.rate_limit.per_minute: 1
    email.rate_limit.per_hour: 5
    email.rate_limit.per_day: 20
    
    # Menos restrictivo (para desarrollo/pruebas)
    email.rate_limit.per_minute: 10
    email.rate_limit.per_hour: 50
    email.rate_limit.per_day: 200
```

### Ajustar validaciones de seguridad

```yaml
parameters:
    email.security.max_body_length: 20000  # Aumentar límite de caracteres
    email.security.max_recipients: 10       # Permitir más destinatarios
```

### Habilitar whitelist de dominios

```yaml
parameters:
    # Solo permitir envíos a estos dominios
    email.security.allowed_domains: ['miempresa.com', 'socio.com']
```

## 🧪 Probando las Medidas de Seguridad

### 1. Probar Rate Limiting

```bash
# Enviar múltiples emails rápidamente
for i in {1..5}; do
  curl -X POST http://localhost:8080/backend/public/api/email/send \
    -H 'Content-Type: application/json' \
    -d '{
      "to": "test@ejemplo.com",
      "subject": "Test '$i'",
      "body": "Contenido de prueba",
      "from": "noreply@app.com"
    }'
  echo ""
done
```

**Resultado esperado:** Después del 2do email en menos de 1 minuto, debería recibir error 429.

### 2. Probar Detección de Spam

```bash
curl -X POST http://localhost:8080/backend/public/api/email/send \
  -H 'Content-Type: application/json' \
  -d '{
    "to": "test@ejemplo.com",
    "subject": "BUY NOW!!! VIAGRA CASINO!!!",
    "body": "CLICK HERE FREE MONEY $$$$$",
    "from": "spam@test.com"
  }'
```

**Resultado esperado:** Error 400 con mensaje "El contenido contiene patrones sospechosos de spam".

### 3. Probar Inyección de Headers

```bash
curl -X POST http://localhost:8080/backend/public/api/email/send \
  -H 'Content-Type: application/json' \
  -d '{
    "to": "test@ejemplo.com",
    "subject": "Asunto normal\nBcc: hacker@evil.com",
    "body": "Intento de inyección",
    "from": "test@app.com"
  }'
```

**Resultado esperado:** Error 400 con mensaje "Posible inyección de headers detectada".

### 4. Consultar Estadísticas

```bash
curl http://localhost:8080/backend/public/api/email/stats
```

## 🚨 Medidas Adicionales Recomendadas

### 1. Autenticación y Autorización

**Actualmente el endpoint es público.** Para producción, se recomienda:

- Implementar autenticación JWT o API Keys
- Limitar el acceso solo a usuarios autenticados
- Implementar roles y permisos

### 2. CAPTCHA para Formularios Web

Si el formulario es público (como el de contacto), agregar:
- Google reCAPTCHA v3
- hCaptcha
- Cloudflare Turnstile

### 3. Firewall de Aplicación Web (WAF)

Usar un WAF como:
- Cloudflare
- AWS WAF
- ModSecurity

### 4. CORS Restrictivo

Limitar los orígenes permitidos en la configuración de CORS:

```yaml
# config/packages/nelmio_cors.yaml
nelmio_cors:
    defaults:
        origin_regex: true
        allow_origin: ['^https://midominio\.com$']
        allow_methods: ['POST']
        allow_headers: ['Content-Type', 'Authorization']
```

### 5. Monitoreo y Alertas

Implementar alertas cuando:
- Una IP excede el rate limit múltiples veces
- Se detectan muchos intentos de spam
- Hay picos anormales de tráfico

## 📝 Mantenimiento

### Limpieza de Archivos de Rate Limiting

Los archivos se limpian automáticamente (registros > 24 horas se eliminan).

**Ubicación:** `var/cache/dev/email_rate_limiter/` o `var/cache/prod/email_rate_limiter/`

**Limpieza manual:**
```bash
rm -rf var/cache/*/email_rate_limiter/*
```

### Revisión de Logs

```bash
# Ver logs en tiempo real
tail -f var/log/dev.log | grep email

# Buscar intentos bloqueados por rate limit
grep "Rate limit exceeded" var/log/prod.log

# Buscar intentos de spam
grep "Security validation failed" var/log/prod.log
```

## 🔄 Actualización de Código

Después de modificar la configuración, limpiar la caché:

```bash
# Desarrollo
php bin/console cache:clear

# Producción
php bin/console cache:clear --env=prod
```

## 📚 Referencias

- [Documentación de Symfony Mailer](https://symfony.com/doc/current/mailer.html)
- [OWASP Email Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Email_Security_Cheat_Sheet.html)
- [RFC 5321 - SMTP](https://tools.ietf.org/html/rfc5321)
