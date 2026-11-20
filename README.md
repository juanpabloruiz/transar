# Transar

Transcribí un archivo de audio, de video o un video público de YouTube. Leé la transcripción en párrafos debajo del formulario y usala para generar el borrador de una nota periodística. La interfaz usa los archivos locales de Bootstrap.

## Arranque local

```sh
docker compose up -d --build --wait
```

Abrí [Transar](http://127.0.0.1:8083). Para cambiar el puerto, definí `TRANSAR_PORT` en un archivo `.env` junto a `compose.yaml`. Si usás el proyecto en otro equipo, creá allí la red externa `proxy` con `docker network create proxy` si todavía no existe.

Para acceder desde otros dispositivos de tu red local, configurá `TRANSAR_LAN_IP` en `.env` con la IP del equipo que ejecuta Docker y aplicá el cambio con `docker compose up -d --no-deps --wait transar`. Por ejemplo, con `TRANSAR_LAN_IP=192.168.1.75` podés abrir [Transar desde la red local](http://192.168.1.75:8083).

La aplicación separa Nginx, PHP-FPM 8.5 y el proceso que ejecuta FFmpeg, yt-dlp y faster-whisper. Usa el modelo multilingüe `large-v3-turbo` en CPU, con detección automática del idioma. Los trabajos y los modelos se guardan en volúmenes de Docker. El primer uso de cada modelo incluye la descarga de sus pesos. El archivo original se elimina al terminar y la transcripción caduca a las 48 horas. El límite local es de 500 MiB por archivo y dos horas de duración. Algunos videos privados, restringidos o bloqueados por YouTube pueden requerir que subas el archivo directamente.

Podés configurar `TRANSAR_MODEL=large-v3` en `.env` para usar el modelo completo, que requiere más tiempo y memoria en CPU. Aplicá el cambio con `docker compose up -d --no-deps --wait worker`. [Modelos de Whisper](https://github.com/openai/whisper#available-models-and-languages)

Para actualizar las imágenes base y las dependencias a las versiones estables disponibles, reconstruí los servicios.

```sh
docker compose build --pull --no-cache
docker compose up -d --wait
```

## Borradores de notas periodísticas

En macOS, abrí Ollama y asegurate de tener el modelo `qwen3.5:4b`, que se puede descargar con `ollama pull qwen3.5:4b`. Al terminar una transcripción, **Generar nota periodística** prepara un borrador con título, bajada y cuerpo debajo del texto original. La generación usa Ollama en la Mac desde el worker de Docker y queda disponible durante el mismo plazo que la transcripción. Revisá los nombres, cifras y citas antes de publicar. [Ollama en macOS](https://docs.ollama.com/macos)

El worker usa `http://host.docker.internal:11434` y `qwen3.5:4b` por defecto. Si tu instalación usa otro servidor local o modelo, configurá `TRANSAR_OLLAMA_URL` o `TRANSAR_ARTICLE_MODEL` en `.env` y recreá el worker con `docker compose up -d --no-deps --wait worker`.

## Dominio `trans.ar`

Ejecutá `docker compose up -d --build --wait` y abrí [trans.ar](https://trans.ar). El dominio está definido en Compose y Nginx, sin variables de dominio en `.env`. Cloudflare Tunnel envía las solicitudes con `Host: trans.ar` a `http://nginx:80`. El proxy compartido recibe ese dominio y dirige el tráfico al servicio `transar` por la red Docker `proxy`.

Para admitir cargas que superen los 32 MiB actuales del proxy central, configurá `client_max_body_size` para `trans.ar` en su directorio `vhost`. Por ejemplo, `client_max_body_size 95m;` en un plan Free o Pro, donde Cloudflare limita cada solicitud a 100 MB. Si querés aplicar el mismo límite desde la interfaz, definí `TRANSAR_MAX_UPLOAD_BYTES=94371840` para aceptar archivos de hasta 90 MiB, con margen para el resto de la solicitud. [Configuración por dominio de nginx-proxy](https://github.com/nginx-proxy/nginx-proxy/blob/main/docs/README.md#per-virtual_host) · [Límites de carga de Cloudflare](https://developers.cloudflare.com/support/troubleshooting/http-status-codes/4xx-client-error/error-413/)
