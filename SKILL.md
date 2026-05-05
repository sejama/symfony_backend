# Skill Symfony para `src/backend`

## Contexto

- Proyecto Symfony ubicado en `src/backend`.
- La carpeta se monta en Docker en `/var/www/html/backend`.
- El contenedor principal es `server-php-apache`.
- Se usa Composer en `/var/www/html/backend` y la entrada de comandos es `php /var/www/html/backend/bin/console`.

## Reglas específicas

- Antes de ejecutar cualquier comando, verifica si el proyecto está en modo dev o prod según `.env`.
- Para limpiar caches usa `php /var/www/html/backend/bin/console cache:clear`.
- Para instalar dependencias usa `composer install --working-dir /var/www/html/backend`.
- Para pruebas unitarias o funcionales, busca `phpunit.xml.dist` o `phpunit.xml` en `src/backend`.
- Usa las variables de entorno en `src/backend/.env` o `src/backend/.env.local` cuando describas comandos.

## Buenas prácticas

- No modifiques configuraciones globales fuera de `src/backend` sin necesidad.
- Documenta cualquier ajuste local en `src/backend/README` o `src/backend/README_SECURITY.md` si aplica.
