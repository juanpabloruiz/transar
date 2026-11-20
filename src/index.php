<?php
declare(strict_types=1);

use function Transar\maxUploadBytes;
use function Transar\positiveInt;

require dirname(__DIR__) . '/app/jobs.php';

$maxUploadBytes = maxUploadBytes();
$maxUploadMiB = intdiv($maxUploadBytes, 1048576);
$maxDurationSeconds = positiveInt('TRANSAR_MAX_DURATION_SECONDS', 7200);
$maxDuration = match (true) {
    $maxDurationSeconds % 3600 === 0 => intdiv($maxDurationSeconds, 3600) . ' h',
    $maxDurationSeconds % 60 === 0 => intdiv($maxDurationSeconds, 60) . ' min',
    default => $maxDurationSeconds . ' s',
};
?>
<!doctype html>
<html lang="es-AR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Transcribí archivos de audio, video y enlaces de YouTube con Transar.">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#edf3f4" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#172631" media="(prefers-color-scheme: dark)">
    <title>Transar · Audio y video en texto</title>
    <link rel="icon" href="img/transar.png" type="image/png">
    <script src="js/tema.js"></script>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/transar.css?v=4">
    <script type="module" src="js/bootstrap.bundle.min.js"></script>
    <script src="js/transar.js?v=4" defer></script>
</head>
<body>
    <div class="page-shell">
        <header class="site-header">
            <a class="brand" href="./" aria-label="Transar, inicio">
                <img src="img/transar.png" alt="" width="36" height="36">
                Transar
            </a>
        </header>

        <main class="workspace">
            <section class="intro" aria-labelledby="page-title">
                <h1 id="page-title">Audio y video <em>en texto.</em></h1>
                <p>Pasá una grabación o un video de YouTube a texto y leé la transcripción en la página.</p>

                <div class="waveform" aria-hidden="true">
                    <div class="waveform-bars">
                        <span></span><span></span><span></span><span></span><span></span><span></span>
                        <span></span><span></span><span></span><span></span><span></span><span></span>
                        <span></span><span></span><span></span><span></span><span></span><span></span>
                        <span></span><span></span><span></span><span></span><span></span><span></span>
                    </div>
                </div>
            </section>

            <section class="tool-card" aria-labelledby="form-title">
                <h2 id="form-title">Nueva transcripción</h2>
                <p class="card-description">Elegí cómo querés cargar el audio.</p>

                <form id="transcription-form" data-max-bytes="<?= $maxUploadBytes ?>" novalidate>
                    <div class="source-tabs" role="tablist" aria-label="Origen del audio">
                        <button class="source-tab active" id="file-tab" type="button" role="tab"
                                data-bs-toggle="tab" data-bs-target="#file-panel" aria-controls="file-panel" aria-selected="true">
                            <span class="media-icon" aria-hidden="true"></span> Archivo
                        </button>
                        <button class="source-tab" id="youtube-tab" type="button" role="tab"
                                data-bs-toggle="tab" data-bs-target="#youtube-panel" aria-controls="youtube-panel"
                                aria-selected="false" tabindex="-1">
                            <span class="media-icon video-icon" aria-hidden="true"></span> YouTube
                        </button>
                    </div>

                    <div class="tab-content">
                        <div class="tab-pane tab-pane-instant active" id="file-panel" role="tabpanel" aria-labelledby="file-tab">
                            <label class="drop-zone" id="drop-zone" for="media-file">
                                <input class="visually-hidden" id="media-file" name="file" type="file"
                                       accept="audio/*,video/*,.m4a,.mkv,.webm,.ogg,.opus,.flac,.mp3,.wav,.mp4,.mov,.avi,.aac,.wma,.wmv,.aiff,.m4v,.mpeg,.mpg,.3gp,.ts,.mka,.amr,.ogv"
                                       aria-labelledby="file-label" aria-describedby="file-hint file-selection">
                                <span class="drop-icon" aria-hidden="true"><span class="media-icon"></span></span>
                                <span class="drop-title" id="file-label">Elegí un archivo</span>
                                <span class="file-selection" id="file-selection" aria-live="polite">o arrastralo hasta acá</span>
                                <span class="input-hint" id="file-hint">Audio o video · hasta <?= $maxUploadMiB ?> MiB y <?= $maxDuration ?></span>
                            </label>
                        </div>

                        <div class="tab-pane tab-pane-instant" id="youtube-panel" role="tabpanel" aria-labelledby="youtube-tab">
                            <div class="youtube-panel">
                                <label class="youtube-label" for="youtube-url">Enlace del video</label>
                                <input class="form-control youtube-url" id="youtube-url" name="url" type="url"
                                       placeholder="https://www.youtube.com/watch?v=…" autocomplete="url"
                                       spellcheck="false" aria-describedby="youtube-hint">
                                <p class="input-hint" id="youtube-hint">Video público · hasta <?= $maxDuration ?></p>
                            </div>
                        </div>
                    </div>

                    <button class="action-button submit-button" type="submit" id="transcribe" disabled>Transcribir</button>
                    <noscript><p class="no-script">Activá JavaScript para transcribir y leer el resultado.</p></noscript>

                    <div class="job-state" id="job-state" data-kind="progress" hidden>
                        <span class="status-icon" aria-hidden="true"></span>
                        <p id="status-message" role="status" aria-atomic="true"></p>
                    </div>
                    <progress id="upload-progress" max="100" aria-label="Progreso de la carga" hidden></progress>
                </form>
            </section>

            <article class="tool-card result-card" id="result" aria-labelledby="result-heading" hidden>
                <h2 id="result-heading">Transcripción</h2>
                <div class="transcript" id="transcription"></div>

                <div class="article-tools">
                    <button class="action-button article-button" id="generate-article" type="button" aria-controls="article-draft">Generar nota periodística</button>
                    <div class="job-state" id="article-state" hidden>
                        <span class="status-icon" aria-hidden="true"></span>
                        <p id="article-status" role="status" aria-atomic="true"></p>
                    </div>
                </div>

                <section class="article-draft" id="article-draft" aria-labelledby="article-title" lang="es" hidden>
                    <p class="article-label">Borrador de nota periodística</p>
                    <h3 id="article-title"></h3>
                    <p class="article-subtitle" id="article-subtitle"></p>
                    <div class="transcript" id="article-paragraphs"></div>
                </section>
            </article>
        </main>
    </div>
</body>
</html>
