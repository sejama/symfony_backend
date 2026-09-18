# Skill Symfony para `src/backend`

## Contexto y Configuración

- **Ubicación local**: `src/backend` | **Ubicación en contenedor**: `/var/www/html/backend`
- **Servicio Docker**: `server-php-apache`
- **Acceso HTTP**: http://localhost:8080/backend/public/
- **Ruta de Comandos**: `php /var/www/html/backend/bin/console`
- **Documentación de Seguridad**: Consulta [`README_SECURITY.md`](README_SECURITY.md) y [`SECURITY_EMAIL.md`](SECURITY_EMAIL.md).

## Reglas Específicas del Subproyecto

1. **Entorno de Trabajo**: Verifica el modo (`APP_ENV=dev` o `APP_ENV=prod`) en `.env` / `.env.local` antes de correr comandos.
2. **Gestión de Caché y Dependencias**:
   ```bash
   docker compose exec server-php-apache composer install --working-dir /var/www/html/backend
   docker compose exec server-php-apache php /var/www/html/backend/bin/console cache:clear
   ```
3. **Pruebas Automatizadas**:
   ```bash
   docker compose exec server-php-apache php /var/www/html/backend/vendor/bin/phpunit -c /var/www/html/backend/phpunit.xml.dist
   ```

## Mejores Prácticas de Desarrollo y Seguridad (Backend API)

- **Sanitización y Validación de Entradas**: Validar siempre el payload de los endpoints usando componentes de validación Symfony (`Symfony\Component\Validator\Constraints`) o DTOs validados.
- **Respuestas JSON Estandarizadas**: Retornar respuestas estructuradas con códigos HTTP adecuados (`200 OK`, `201 Created`, `400 Bad Request`, `401 Unauthorized`, `403 Forbidden`, `404 Not Found`, `500 Internal Error`).
- **Seguridad en Endpoints**: Evitar la exposición innecesaria de stack traces en entornos de desarrollo cuando se prueben respuestas de error públicas.
- **Preparación de Consultas ORM/DBAL**: Utilizar siempre binding de parámetros en Doctrine (`->setParameter()`) para prevenir ataques de inyección SQL.
- **Manejo de Sensibles**: Nunca commitear claves API ni secretos en `.env`. Usar `.env.local` para desarrollo local aislado.
