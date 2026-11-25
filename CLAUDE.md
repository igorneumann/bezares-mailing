# Bezares Mailing — contexto para Claude Code

Sistema de envío de correos tipo Mautic, sencillo, para BEZARES SA. En producción en https://mailing.bezares.com
(Plesk, nginx + PHP-FPM 8.5, sin Composer). Interfaz y textos en español; los correos y encuestas pueden ser bilingües (es/en).
El usuario (Igor) escribe en español: responder en español.

## Arquitectura
- PHP 8 sin dependencias ni Composer. PDO con SQLite (por defecto) o MySQL. Debe funcionar en hosting compartido.
- Entradas públicas en la raíz: `index.php` (panel), `cron.php` (cola y automatizaciones, cada 5 min con clave),
  `s.php` (encuestas, enlaces personales firmados con HMAC), `u.php` (baja por listado), `o.php` (píxel de apertura),
  `c.php` (clics, redirección firmada), `v.php` (ver en el navegador), `subscribe.php` (formulario incrustable, modo kiosco).
- `lib/`: `boot.php` (esquema, ajustes, layout del panel con barra lateral), `security.php` (cabeceras, throttle, SSRF,
  canonical host), `mailer.php` (render de correos, cola, SMTP, encuestas, agradecimientos), `modern.php` (temas
  «Moderno» y bloques), `media.php` (biblioteca y migraciones `migrate()`), `auto.php` (NPS trimestral, generador de
  newsletter desde WordPress), `xlsx.php`, `seed.php`.
- `pages/`: una por pestaña del panel (contactos, etiquetas, correos, estadísticas, biblioteca, encuestas, automatización, ajustes).
- `themes/*.html`: plantillas de correo con marcadores `{{...}}` (tablas, estilos en línea, compatibles con Outlook).
- `data/` (NO está en git): `config.php` (secreto, `base_url`, BD) y la base SQLite. No subir nunca a GitHub.

## Convenciones
- Esquema versionado en `migrate()` (lib/media.php), ahora v10. Cada cambio de esquema: añadir columnas en el CREATE
  de `lib/boot.php` y un paso `$try('ALTER ...')` + `set_setting('schema', 'N')`.
- Seguridad: CSRF en todos los POST, `e()` para escapar, enlaces públicos firmados con `sign()`, consultas con parámetros.
- Correos: el color de botones va en la celda (`bgcolor`) para Outlook; nada de flexbox; clase `mstack`/`col2` para
  columnas que se apilan en móvil (no usar `.stack`, choca con el CSS del panel).
- Cada versión: commit en español con descripción, push a `main`, y el usuario sube por FTP los archivos cambiados
  (decirle siempre qué archivos subir).

## Probar en local
`PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080` en la carpeta (la primera visita abre el instalador si no hay `data/config.php`).
Para pruebas de envío se puede usar un SMTP falso local. Plesk tiene la caché de nginx: todas las respuestas llevan `no-store`.
