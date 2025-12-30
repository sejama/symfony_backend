# 🔒 Mejoras de Seguridad Implementadas en la API de Email

## Resumen Ejecutivo

Se han implementado **5 capas de seguridad** para proteger el endpoint de envío de emails contra uso malintencionado, spam y ataques automatizados.

## 📦 Archivos Creados/Modificados

### Nuevos Servicios
1. **[src/Service/EmailRateLimiter.php](src/Service/EmailRateLimiter.php)**
   - Implementa limitación de tasa por IP
   - Límites: 2/min, 10/hora, 50/día
   - Persistencia en archivos

2. **[src/Service/EmailSecurityValidator.php](src/Service/EmailSecurityValidator.php)**
   - Detección de spam
   - Prevención de inyección de headers
   - Validación de contenido
   - Whitelist de dominios (opcional)
   - Sanitización de HTML

### Controladores Actualizados
3. **[src/Controller/Api/EmailController.php](src/Controller/Api/EmailController.php)**
   - Integración de rate limiting
   - Integración de validaciones de seguridad
   - Logging completo
   - Nuevo endpoint: GET `/api/email/stats`

### Configuración
4. **[config/services.yaml](config/services.yaml)**
   - Parámetros configurables de rate limiting
   - Parámetros de seguridad
   - Inyección de dependencias

### Documentación
5. **[SECURITY_EMAIL.md](SECURITY_EMAIL.md)**
   - Documentación completa de medidas de seguridad
   - Guía de configuración
   - Ejemplos de prueba
   - Recomendaciones adicionales

6. **[EJEMPLO_FRONTEND_SEGURO.js](EJEMPLO_FRONTEND_SEGURO.js)**
   - Ejemplo de implementación frontend
   - Manejo de rate limiting en el cliente
   - Gestión de errores mejorada

## 🛡️ Capas de Seguridad

### 1. Rate Limiting ⏱️
```
✓ 2 emails por minuto
✓ 10 emails por hora  
✓ 50 emails por día
✓ Por IP (considera X-Forwarded-For)
```

### 2. Validación Anti-Spam 🚫
```
✓ Detección de palabras clave de spam
✓ Análisis de patrones sospechosos
✓ Límite de URLs (max 5)
✓ Bloqueo de acortadores de URL
✓ Detección de exceso de mayúsculas
```

### 3. Prevención de Inyecciones 💉
```
✓ Inyección de headers (CRLF)
✓ Caracteres de control
✓ Caracteres nulos
✓ Sanitización HTML (XSS)
```

### 4. Límites de Contenido 📏
```
✓ Max 10,000 caracteres en body
✓ Max 5 destinatarios (to + cc + bcc)
✓ Max 255 caracteres en subject
```

### 5. Logging y Auditoría 📝
```
✓ Todos los envíos registrados
✓ Intentos bloqueados registrados
✓ Detección de spam registrada
✓ Errores registrados con stack trace
```

## 🚀 Inicio Rápido

### 1. Probar la API (sin medidas de seguridad)

```bash
curl -X POST http://localhost:8080/backend/public/api/email/send \
  -H 'Content-Type: application/json' \
  -d '{
    "to": "test@ejemplo.com",
    "subject": "Email de prueba",
    "body": "Este es un mensaje válido",
    "from": "contacto@miapp.com",
    "isHtml": false
  }'
```

### 2. Verificar Rate Limiting

```bash
# Ejecutar este comando 3 veces seguidas (debería fallar la 3ra vez)
for i in {1..3}; do
  curl -X POST http://localhost:8080/backend/public/api/email/send \
    -H 'Content-Type: application/json' \
    -d '{"to":"test@ejemplo.com","subject":"Test '$i'","body":"Test","from":"test@app.com"}'
  echo -e "\n---"
done
```

### 3. Consultar Estadísticas

```bash
curl http://localhost:8080/backend/public/api/email/stats
```

## ⚙️ Configuración

### Ajustar Límites

Edita `config/services.yaml`:

```yaml
parameters:
    # Rate Limiting
    email.rate_limit.per_minute: 2    # Cambiar según necesidad
    email.rate_limit.per_hour: 10
    email.rate_limit.per_day: 50
    
    # Seguridad
    email.security.max_body_length: 10000
    email.security.max_recipients: 5
    
    # Whitelist (vacío = todos permitidos)
    email.security.allowed_domains: []
```

### Habilitar Whitelist de Dominios

```yaml
parameters:
    email.security.allowed_domains: ['miempresa.com', 'cliente.com']
```

### Aplicar Cambios

```bash
php bin/console cache:clear
```

## 📊 Nuevos Endpoints

### POST `/api/email/send`
*Ya existía, ahora con medidas de seguridad*

**Posibles respuestas:**
- `200 OK` - Email enviado
- `400 Bad Request` - Error de validación o seguridad
- `429 Too Many Requests` - Rate limit excedido
- `500 Internal Server Error` - Error del servidor

### GET `/api/email/stats` ⭐ NUEVO
*Consultar estadísticas de envío*

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

## 🧪 Pruebas de Seguridad

### Test 1: Rate Limiting
```bash
# Ver: SECURITY_EMAIL.md sección "Probando las Medidas de Seguridad"
```

### Test 2: Detección de Spam
```bash
curl -X POST http://localhost:8080/backend/public/api/email/send \
  -H 'Content-Type: application/json' \
  -d '{
    "to": "test@ejemplo.com",
    "subject": "BUY NOW VIAGRA",
    "body": "FREE MONEY $$$$",
    "from": "test@app.com"
  }'
```
**Esperado:** Error 400 - "patrones sospechosos de spam"

### Test 3: Inyección de Headers
```bash
curl -X POST http://localhost:8080/backend/public/api/email/send \
  -H 'Content-Type: application/json' \
  -d '{
    "to": "test@ejemplo.com",
    "subject": "Test\nBcc: hacker@evil.com",
    "body": "Intento de inyección",
    "from": "test@app.com"
  }'
```
**Esperado:** Error 400 - "inyección de headers detectada"

## 📈 Monitoreo

### Ver Logs en Tiempo Real
```bash
tail -f var/log/dev.log | grep -i email
```

### Buscar Intentos Bloqueados
```bash
grep "Rate limit exceeded" var/log/dev.log
grep "Security validation failed" var/log/dev.log
```

### Limpiar Rate Limiter
```bash
rm -rf var/cache/*/email_rate_limiter/*
```

## 🔐 Recomendaciones Adicionales

### Para Producción

1. **Implementar Autenticación**
   - JWT tokens
   - API Keys
   - OAuth 2.0

2. **Agregar CAPTCHA**
   - Google reCAPTCHA v3
   - hCaptcha
   - Cloudflare Turnstile

3. **Configurar WAF**
   - Cloudflare
   - AWS WAF
   - Fail2ban

4. **Restringir CORS**
   ```yaml
   nelmio_cors:
       defaults:
           allow_origin: ['^https://midominio\.com$']
   ```

5. **Habilitar HTTPS**
   - Certificado SSL/TLS
   - Forzar redirección HTTPS

## 📚 Documentación Adicional

- **[SECURITY_EMAIL.md](SECURITY_EMAIL.md)** - Documentación completa
- **[EJEMPLO_FRONTEND_SEGURO.js](EJEMPLO_FRONTEND_SEGURO.js)** - Ejemplo frontend
- [Symfony Mailer Docs](https://symfony.com/doc/current/mailer.html)
- [OWASP Email Security](https://cheatsheetseries.owasp.org/cheatsheets/Email_Security_Cheat_Sheet.html)

## 🆘 Soporte

Si tienes problemas:

1. Revisa los logs: `var/log/dev.log`
2. Verifica la configuración: `config/services.yaml`
3. Limpia la caché: `php bin/console cache:clear`
4. Revisa la documentación: `SECURITY_EMAIL.md`

## 📝 Changelog

### v2.0 - Seguridad (2025-12-30)

#### Añadido
- ✅ Rate Limiting por IP (2/min, 10/hora, 50/día)
- ✅ Validador de seguridad anti-spam
- ✅ Prevención de inyección de headers
- ✅ Sanitización HTML
- ✅ Whitelist de dominios (opcional)
- ✅ Logging completo
- ✅ Endpoint de estadísticas `/api/email/stats`

#### Mejorado
- ✅ EmailController con múltiples capas de validación
- ✅ Manejo de errores más detallado
- ✅ Respuestas HTTP apropiadas (429, 400, etc.)

---

**¡La API ahora está protegida contra uso malintencionado!** 🎉
