# Bezares Mailing

Aplicación sencilla de envío de correos (tipo Mautic, pero ligera) para hosting compartido.

## Requisitos
- PHP 8.0 o superior con las extensiones PDO (SQLite o MySQL), mbstring, openssl y zip (para Excel).
- No necesita Composer ni Node: se sube tal cual.

## Instalación
1. Suba la carpeta completa por FTP (por ejemplo a `mailing.sudominio.com` o a `/mailing`).
2. Dé permisos de escritura a las carpetas `data` y `uploads` (normalmente 755 o 775).
3. Abra la dirección en el navegador: aparece el asistente. Cree el usuario administrador y elija
   SQLite (sin configuración) o MySQL (creando antes la base de datos en el panel del hosting).
4. En **Ajustes** indique el remitente y el servidor SMTP, y pulse «Enviar prueba».
5. (Recomendado) Cree la tarea programada (cron) que se muestra en Ajustes para que la cola
   se envíe sola, y ponga el límite de correos por hora de su hosting.

Si el servidor es Nginx (no Apache), los `.htaccess` no se aplican: bloquee el acceso web a
`/data`, `/lib`, `/pages` y `/themes`.

## Uso
- **Contactos**: alta manual, importación y exportación en CSV o Excel (.xlsx), etiquetas y estado.
  La importación acepta directamente la exportación de Mautic (`email`, `firstname`, `lastname`, `company`, `tags`,
  y la columna de «no enviar correos / Do Not Contact», que se importa como baja) y deja revisar las columnas antes de importar.
- **Correos**: editor con temas. Campos disponibles: `{{nombre}}`, `{{apellidos}}`, `{{empresa}}`,
  `{{email}}`, `{{encuesta}}`, `{{encuesta_url}}`, `{{baja_url}}`. El pie con el enlace de baja
  se añade siempre.
- **Estadísticas de cada correo**: quién lo ha recibido, quién lo ha abierto (y cuándo), fallidos,
  respuestas a la encuesta y bajas, con filtros, búsqueda y exportación.
- **Biblioteca**: imágenes y documentos (PDF, Word, Excel) propios: subir, copiar desde una URL, elegir desde el editor.
  Cualquier archivo de la biblioteca se puede **adjuntar** a un correo desde el editor («Archivos adjuntos»). Detecta las
  imágenes que los correos cargan desde otros servidores y las copia a la biblioteca con un clic.
- **Encuestas**: preguntas de escala 0–10 (NPS), 1–5, texto y opciones. Límite de respuestas por
  contacto (por correo electrónico). Cada encuesta tiene su página de resultados con el NPS.

### Automatización (requiere la tarea cron)
- **NPS automático**: en las fechas configuradas (día/mes; la primera del año es Q1, la segunda Q2…) crea un correo y una
  encuesta `NPS-Q1-2027` copiando la plantilla, los envía al listado, manda un recordatorio a quien no haya respondido
  (por defecto a los 7 días) y después envía los resultados con el Excel de respuestas a los administradores
  (por defecto 7 días después del recordatorio). Fechas, hora, plazos, listado, plantilla y administradores son configurables,
  y las fechas de cada campaña en curso se pueden cambiar.
- **Generador de newsletter**: a partir de la dirección de un WordPress y un número de entradas crea una newsletter con
  las 2 primeras en grande (imagen, titular con enlace y los dos primeros párrafos) y las siguientes en pequeño, de dos en dos
  lado a lado, como el correo de ejemplo (API REST de WordPress o, si no, el canal RSS).

### Aviso por correo de las respuestas
Cada encuesta puede avisar por correo de sus respuestas: de todas, o solo de las que tengan una nota igual o menor que un
umbral, con un destinatario para las respuestas en español y otro para las respuestas en inglés. La encuesta NPS viene
configurada para avisar de las notas de 6 o menos (español: j.adalia@bezares.com; inglés: p.hermo@bezares.com) y las
campañas automáticas heredan esa configuración.

### Formulario de suscripción
`subscribe.php` (español) y `subscribe.php?lang=en` (inglés): formulario limpio, sin cabecera ni marco y con fondo transparente,
para incrustar con un iframe (el código está en Automatización). Con la doble confirmación, el contacto queda «Pendiente de
confirmar» hasta que pulsa el enlace del correo. A cada alta se le pone la etiqueta del idioma (`newsletter-es` / `newsletter-en`).

### Encuestas en dos idiomas (español e inglés)
Cada encuesta es una sola y lleva su traducción al inglés (título, textos y preguntas). El correo tiene un campo
«Idioma»: desde un correo en inglés, las notas, el formulario y la página de baja salen en inglés. Las respuestas de los dos
idiomas se guardan en la misma encuesta, así que el resultado y el NPS se calculan juntos (con desglose por idioma).
El NPS automático tiene dos listados y dos plantillas («Encuesta NPS» y «Encuesta NPS (en)»).

### Encuesta desde el correo
Enlace una encuesta al correo y coloque `{{encuesta}}`: se muestran las notas como botones.
Al pulsar una nota se abre el formulario con el correo y la nota ya rellenados; la respuesta se
registra al pulsar «Enviar» (así los antivirus que abren enlaces automáticamente no generan
respuestas falsas).

### Índice de la newsletter
El tema «Bezares · Newsletter» muestra al principio un índice automático con los 4 primeros titulares
del correo (2 a la izquierda, logo en el centro, 2 a la derecha), cada uno con su enlace. Se toma de los
títulos de los bloques «Artículo» y «2 columnas»; con menos de 2 titulares no se muestra.

## Temas propios
Cada tema es un archivo HTML en `themes/`. La primera línea indica el nombre:
`<!-- Tema: Mi tema -->`. Marcadores: `{{indice}}` (índice automático de titulares), `{{cabecera}}`, `{{contenido}}`, `{{pie}}`, `{{redes}}`,
`{{asunto}}`, `{{preheader}}`, `{{color}}`, `{{ver_url}}`, `{{baja_url}}`, `{{remitente}}`.

## Copia de seguridad
Con SQLite basta copiar la carpeta `data` (y `uploads`).
