<?php

declare(strict_types=1);

namespace Transar;

use RuntimeException;
use Throwable;

require __DIR__ . '/jobs.php';

final class JobFailure extends RuntimeException
{
}

final class WorkerStopping extends RuntimeException
{
}

final class Worker
{
    private bool $stopping = false;
    private string $root;
    private int $maxDuration;

    public function __construct()
    {
        $this->root = jobRoot();
        $this->maxDuration = positiveInt('TRANSAR_MAX_DURATION_SECONDS', 7200);
    }

    public function run(): void
    {
        if (!is_dir($this->root) || !is_writable($this->root)) {
            throw new RuntimeException('The jobs directory is not writable.');
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }

        $nextCleanup = 0;
        while (!$this->stopping) {
            $pending = [];
            foreach (jobDirs($this->root) as $directory) {
                try {
                    $state = readState($directory);
                    if ($state === null) {
                        continue;
                    }
                    if (isPending($state)) {
                        $pending[$directory] = $state['createdAt'];
                    } elseif (isArticlePending($state)) {
                        $pending[$directory] = $state['article']['requestedAt'];
                    }
                } catch (Throwable $error) {
                    error_log('[transar] ' . $error->getMessage());
                }
            }
            asort($pending, SORT_NUMERIC);
            foreach (array_keys($pending) as $directory) {
                if ($this->stopping) {
                    break;
                }
                try {
                    $this->withJobLock($directory, fn () => $this->process($directory));
                } catch (Throwable $error) {
                    error_log('[transar] ' . basename($directory) . ': ' . $error->getMessage());
                }
            }
            if (!$this->stopping && time() >= $nextCleanup) {
                $this->cleanup();
                $nextCleanup = time() + 600;
            }
            if (!$this->stopping) {
                sleep(2);
            }
        }
    }

    private function withJobLock(string $directory, callable $operation): void
    {
        $handle = @fopen($directory . '/.lock', 'c');
        if ($handle === false) {
            return;
        }
        try {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $operation();
            }
        } finally {
            fclose($handle);
        }
    }

    private function process(string $directory): void
    {
        $state = readState($directory);
        if ($state === null) {
            return;
        }
        if (isArticlePending($state)) {
            $this->generateArticle($directory, $state);
            return;
        }
        if (!isPending($state)) {
            return;
        }

        $work = $directory . '/work';
        $source = $directory . '/source';
        $text = $directory . '/transcript.txt';
        removeTree($work);
        if (is_file($text)) {
            $this->finish($directory, $state, 'completed', 'La transcripción está lista.');
            return;
        }
        if ($state['attempts'] >= 2) {
            $this->finish($directory, $state, 'failed', 'El procesamiento se interrumpió. Iniciá una nueva transcripción.');
            return;
        }

        $state['attempts']++;
        $state['status'] = 'processing';
        try {
            if (!mkdir($work, 0700)) {
                throw new RuntimeException('Unable to create the job working directory.');
            }
            if ($state['url'] !== null) {
                $state['message'] = 'Estamos obteniendo el audio de YouTube.';
                writeState($directory, $state);
                $source = $this->download($state['url'], $work);
            }

            $state['message'] = 'Estamos preparando el audio.';
            writeState($directory, $state);
            $audio = $this->convert($source, $work);

            $state['message'] = 'Estamos transcribiendo el audio. La primera vez puede demorar más mientras se descarga el modelo.';
            writeState($directory, $state);
            $this->transcribe($audio, $work, $text);
        } catch (WorkerStopping) {
            $state['attempts']--;
            $state['status'] = 'queued';
            unset($state['message']);
            writeState($directory, $state);
            return;
        } catch (JobFailure $error) {
            $this->finish($directory, $state, 'failed', $error->getMessage());
            return;
        } catch (Throwable $error) {
            error_log('[transar] ' . basename($directory) . ': ' . $error->getMessage());
            $this->finish($directory, $state, 'failed', 'No pudimos completar la transcripción. Intentá de nuevo.');
            return;
        } finally {
            removeTree($work);
        }

        $this->finish($directory, $state, 'completed', 'La transcripción está lista.');
    }

    private function generateArticle(string $directory, array $state): void
    {
        $article = $state['article'];
        if ($article['attempts'] >= 2) {
            $state['article'] = ['status' => 'failed', 'message' => 'La generación se interrumpió. Intentá de nuevo.'];
            writeState($directory, $state);
            return;
        }

        $article['attempts']++;
        $article['status'] = 'processing';
        $state['article'] = $article;
        writeState($directory, $state);
        $work = $directory . '/work';
        try {
            removeTree($work);
            if (!mkdir($work, 0700)) {
                throw new RuntimeException('Unable to create the article working directory.');
            }
            $output = $work . '/article.json';
            $code = $this->command([
                setting('TRANSAR_PYTHON', '/opt/venv/bin/python'),
                __DIR__ . '/article.py', $directory . '/transcript.txt', $output,
            ], positiveInt('TRANSAR_ARTICLE_TIMEOUT', 1800), $work);
            if ($code !== 0) {
                $this->logCommandError('Ollama', $work);
                throw new JobFailure(match ($code) {
                    3 => 'No pudimos conectarnos a Ollama. Comprobá que esté abierto en la Mac e intentá de nuevo.',
                    4 => 'Ollama no tiene disponible el modelo de redacción configurado.',
                    5 => 'El modelo no devolvió una nota válida. Intentá de nuevo.',
                    default => 'No pudimos generar la nota. Intentá de nuevo.',
                });
            }
            $result = json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
            $state['article'] = ['status' => 'completed', ...$result];
        } catch (WorkerStopping) {
            $article['attempts']--;
            $article['status'] = 'queued';
            $state['article'] = $article;
        } catch (JobFailure $error) {
            $state['article'] = ['status' => 'failed', 'message' => $error->getMessage()];
        } catch (Throwable $error) {
            error_log('[transar] article ' . basename($directory) . ': ' . $error->getMessage());
            $state['article'] = ['status' => 'failed', 'message' => 'No pudimos generar la nota. Intentá de nuevo.'];
        } finally {
            removeTree($work);
        }
        writeState($directory, $state);
    }

    private function download(string $url, string $work): string
    {
        $code = $this->command([
            setting('TRANSAR_YTDLP', '/opt/venv/bin/yt-dlp'),
            '--ignore-config', '--no-plugin-dirs', '--no-remote-components',
            '--use-extractors', 'youtube', '--no-playlist', '--no-progress', '--check-formats',
            '--socket-timeout', '20', '--retries', '2',
            '--match-filters', 'duration <=?' . $this->maxDuration . ' & !is_live',
            '--max-filesize', (string) maxUploadBytes(),
            '--format', 'bestaudio[protocol=https]/best[protocol=https]',
            '--output', $work . '/download.%(ext)s',
            '--', $url,
        ], 1800, $work);

        foreach (glob($work . '/download.*') ?: [] as $file) {
            if ($code === 0 && preg_match('/^download\.[a-z0-9]{1,8}$/D', basename($file)) === 1
                && is_file($file) && filesize($file) > 0 && filesize($file) <= maxUploadBytes()
            ) {
                return $file;
            }
        }
        $this->logCommandError('yt-dlp', $work);
        throw new JobFailure('No pudimos obtener el audio de YouTube. Verificá que el video sea público, haya terminado y cumpla los límites de tamaño y duración. También podés subir el archivo.');
    }

    private function convert(string $source, string $work): string
    {
        $audio = $work . '/audio.wav';
        $formats = 'aac,ac3,aiff,amr,ape,asf,au,avi,caf,eac3,flac,flv,matroska,webm,mov,mp4,m4a,3gp,3g2,mj2,mp3,mpeg,mpegts,ogg,rm,tta,voc,w64,wav,wv';
        $code = $this->command([
            'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-protocol_whitelist', 'file,pipe', '-format_whitelist', $formats,
            '-i', $source, '-map', '0:a:0', '-vn', '-sn', '-dn', '-map_metadata', '-1',
            '-t', (string) ($this->maxDuration + 1), '-ac', '1', '-ar', '16000',
            '-c:a', 'pcm_s16le', '-f', 'wav', $audio,
        ], 1800, $work);
        if ($code !== 0 || !is_file($audio)) {
            $this->logCommandError('ffmpeg', $work);
            throw new JobFailure('No pudimos leer el audio. Probá con otro archivo de audio o con un video que tenga pista de audio.');
        }
        return $audio;
    }

    private function transcribe(string $audio, string $work, string $text): void
    {
        $tempText = $work . '/transcript.txt';
        $code = $this->command([
            setting('TRANSAR_PYTHON', '/opt/venv/bin/python'),
            __DIR__ . '/transcribe.py', $audio, $tempText, (string) $this->maxDuration,
        ], positiveInt('TRANSAR_TRANSCRIPTION_TIMEOUT', 21600), $work);

        if ($code === 3) {
            throw new JobFailure('El audio supera la duración máxima permitida.');
        }
        if ($code === 4) {
            throw new JobFailure('No detectamos voz en el audio.');
        }
        if ($code !== 0 || !is_file($tempText) || !rename($tempText, $text)) {
            $this->logCommandError('faster-whisper', $work);
            throw new JobFailure('No pudimos transcribir el audio. Intentá de nuevo en unos minutos.');
        }
    }

    private function command(array $argv, int $seconds, string $work): int
    {
        if ($this->stopping) {
            throw new WorkerStopping();
        }
        $process = proc_open(['timeout', '--kill-after=5s', $seconds . 's', ...$argv], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $work . '/command.log', 'w'],
            2 => ['redirect', 1],
        ], $pipes, $work);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch ' . basename($argv[0]));
        }

        $terminating = false;
        try {
            while (($status = proc_get_status($process))['running']) {
                if ($this->stopping && !$terminating) {
                    proc_terminate($process);
                    $terminating = true;
                }
                usleep(250_000);
            }
        } finally {
            proc_close($process);
        }

        if ($this->stopping) {
            throw new WorkerStopping();
        }
        if (in_array($status['exitcode'], [124, 137], true) || $status['termsig'] === 9) {
            throw new JobFailure('Se interrumpió el procesamiento. Intentá con un archivo más corto.');
        }
        return $status['exitcode'];
    }

    private function logCommandError(string $program, string $work): void
    {
        $log = $work . '/command.log';
        $size = filesize($log) ?: 0;
        $tail = file_get_contents($log, offset: max(0, $size - 3000));
        error_log('[transar] ' . $program . ': ' . trim(preg_replace('/[[:cntrl:]]/', ' ', $tail ?: '')));
    }

    private function finish(string $directory, array $state, string $status, string $message): void
    {
        unset($state['url']);
        $state['status'] = $status;
        $state['message'] = $message;
        writeState($directory, $state);
        @unlink($directory . '/source');
    }

    private function cleanup(): void
    {
        $cutoff = time() - positiveInt('TRANSAR_RETENTION_SECONDS', 172800);
        foreach (jobDirs($this->root) as $directory) {
            try {
                $state = readState($directory);
                if ($state === null || isPending($state) || isArticlePending($state)) {
                    continue;
                }
                $this->withJobLock($directory, function () use ($directory, $cutoff): void {
                    $state = readState($directory);
                    if ($state === null || isPending($state) || isArticlePending($state)) {
                        return;
                    }
                    if ($state['updatedAt'] < $cutoff) {
                        removeTree($directory);
                        return;
                    }
                    @unlink($directory . '/source');
                    removeTree($directory . '/work');
                });
            } catch (Throwable $error) {
                error_log('[transar] ' . $error->getMessage());
            }
        }
        foreach (glob($this->root . '/.upload-*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (filemtime($directory) < $cutoff) {
                removeTree($directory);
            }
        }
    }
}

umask(0077);
(new Worker())->run();
