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

Si el servidor es Nginx (no Apache), los `.htaccess` no se aplican: añada las reglas de
`nginx.conf.example` y compruébelo con «Comprobar ahora» en Ajustes → Seguridad del servidor.

## Uso
- **Contactos**: alta manual, importación y exportación en CSV o Excel (.xlsx), etiquetas y estado.
  La importación acepta directamente la exportación de Mautic (`email`, `firstname`, `lastname`, `company`, `tags`,
  y la columna de «no enviar correos / Do Not Contact», que se importa como baja) y deja revisar las columnas antes de importar.
- **Etiquetas**: todas las etiquetas con cuántos contactos tiene cada una (suscritos, bajas, otros). Al pulsar una se
  abre Contactos filtrado por ella. Cada etiqueta tiene botones para importar contactos directamente a ella y para
  exportar sus contactos en CSV o Excel.
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

### Bajas por listado
El enlace de baja de cada correo da de baja **solo del listado (etiqueta) al que se envió**: quien se da de baja de la
newsletter sigue recibiendo los correos que se envíen a otras etiquetas (clientes, proveedores…). La página de baja ofrece
también «darme de baja de todas las comunicaciones». La baja en un clic de Gmail/Outlook también es del listado.
- Si el correo se envió a «Todos los suscritos», el enlace da de baja de todo.
- La etiqueta se conserva (aparece tachada) y el contacto deja de recibir los envíos a ella, también las campañas NPS
  y sus recordatorios. Importar o añadir la etiqueta de nuevo no anula la baja.
- Volver a un listado exige confirmar por correo (desde la página de baja o el formulario de suscripción). Un
  administrador puede editar las «Bajas de listados» en la ficha del contacto si el contacto se lo pide.
- En Etiquetas, «Bajas» cuenta a quien se dio de baja de todo y a quien se dio de baja solo de esa etiqueta.

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
`subscribe.php?lang=both` es la versión bilingüe: todos los textos en español e inglés y dos casillas, «Boletín en
español» y «Boletín en inglés»; el alta recibe la etiqueta de cada boletín marcado (hay que marcar al menos uno) y el
correo de confirmación va en inglés si solo se marca el inglés, y en español en los demás casos.

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

## Seguridad

- **Dirección fija**: la dirección de la aplicación se indica al instalar y se cambia en Ajustes. Todos los enlaces y las
  cookies dependen de ella; las peticiones que llegan con otro nombre de host se redirigen a ella.
- **HTTPS**: con una dirección `https://` las cookies llevan siempre `Secure`, y «Forzar HTTPS» redirige http → https y
  activa HSTS. Detrás de un proxy inverso, declare su IP en `data/config.php`:
  `'trusted_proxies' => ['10.0.0.5'],`. Solo entonces se aceptan `X-Forwarded-Proto` y `X-Forwarded-For`.
- **Incrustar en otras webs (Nextcloud…)**: por defecto el panel solo se puede mostrar dentro de la propia aplicación.
  En Ajustes → «URLs de confianza» se indican las webs que sí pueden incrustarlo (por ejemplo `https://cloud.bezares.com`
  para la app «External sites» de Nextcloud). Si esa web está en otro dominio, las cookies de sesión pasan a
  `SameSite=None; Secure; Partitioned` para que el inicio de sesión funcione dentro del marco (requiere https).
- **Carpeta de datos**: la base de datos SQLite y los archivos de importación pueden guardarse fuera de la carpeta pública
  (opción del instalador; queda en `data/config.php` como `'data_dir'`). Si se quedan en `data/`, el servidor debe
  bloquearla: `.htaccess` en Apache, `nginx.conf.example` en Nginx. Ajustes → Seguridad del servidor lo comprueba
  pidiendo los archivos por HTTP, y el panel avisa en todas las páginas si están expuestos.
- **Altas y reactivaciones**: un contacto dado de baja solo vuelve a estar activo confirmando por correo, aunque la doble
  confirmación esté desactivada. Los enlaces de confirmación son de un solo uso, caducan a los 7 días y una baja
  posterior los anula.
- **Encuestas**: los enlaces personales van firmados para cada encuesta. Las respuestas del formulario público
  (correo escrito a mano) quedan «sin verificar», no se atribuyen a ningún contacto y no bloquean al contacto real.
  Las encuestas nuevas y las NPS son, por defecto, solo con enlace personal.
- **Límites de frecuencia**: acceso al panel (bloqueo progresivo por cuenta y bloqueo por IP), encuestas, formulario de
  suscripción y correos de confirmación.
- **Descargas de otras webs** (imágenes, WordPress): solo http/https a los puertos 80 y 443 de direcciones públicas;
  la conexión se fija a la IP comprobada y cada redirección se vuelve a validar.
- **Importaciones**: máximo 20 MB, 100.000 filas y 200 columnas; los Excel se leen fila a fila con límites de tamaño
  descomprimido y se rechazan los que contienen definiciones de documento (DOCTYPE).

## Temas propios
Cada tema es un archivo HTML en `themes/`. La primera línea indica el nombre:
`<!-- Tema: Mi tema -->`. Marcadores: `{{indice}}` (índice automático de titulares), `{{cabecera}}`, `{{contenido}}`, `{{pie}}`, `{{redes}}`,
`{{asunto}}`, `{{preheader}}`, `{{color}}`, `{{ver_url}}`, `{{baja_url}}`, `{{remitente}}`.

## Copia de seguridad
Con SQLite basta copiar la carpeta `data` (y `uploads`).
