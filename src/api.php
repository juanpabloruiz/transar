<?php

declare(strict_types=1);

namespace Transar;

use RuntimeException;
use Throwable;

require dirname(__DIR__) . '/app/jobs.php';

final class HttpError extends RuntimeException
{
}

const TRANSCRIPT_NOT_FOUND = 'La transcripción no está disponible. Puede haber vencido.';

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function uploadedFile(): ?string
{
    $upload = $_FILES['file'] ?? [];
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        || ($error === UPLOAD_ERR_OK && $upload['size'] > maxUploadBytes())
    ) {
        throw new HttpError('El archivo supera el tamaño máximo permitido.', 413);
    }
    if ($error !== UPLOAD_ERR_OK || $upload['size'] < 1 || !is_uploaded_file($upload['tmp_name'])) {
        throw new HttpError('No pudimos recibir el archivo. Seleccioná un audio o video e intentá de nuevo.', 400);
    }

    return $upload['tmp_name'];
}

function createJob(string $root): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        throw new HttpError('Usá POST para iniciar una transcripción.', 405);
    }
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        throw new HttpError('Iniciá la transcripción desde Transar.', 403);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > maxUploadBytes() + 2 * 1024 * 1024) {
        throw new HttpError('El archivo supera el tamaño máximo permitido.', 413);
    }

    $file = uploadedFile();
    $input = is_string($_POST['url'] ?? null) ? trim($_POST['url']) : '';
    if (($file === null) === ($input === '')) {
        throw new HttpError('Seleccioná un archivo o ingresá un enlace de YouTube.', 400);
    }
    $url = null;
    if ($file === null) {
        $url = strlen($input) <= 2048 ? youtubeUrl($input) : null;
        if ($url === null) {
            throw new HttpError('Ingresá un enlace válido a un video de YouTube.', 400);
        }
    }

    $lock = fopen($root . '/.queue.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Unable to open the queue lock.');
    }
    $id = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));
    $staging = $root . '/.upload-' . $id;
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the queue.');
        }
        $pending = 0;
        foreach (jobDirs($root) as $directory) {
            $state = readState($directory);
            if ($state !== null && isPending($state)) {
                $pending++;
            }
        }
        if ($pending >= positiveInt('TRANSAR_MAX_PENDING_JOBS', 6)) {
            throw new HttpError('Hay varias transcripciones en espera. Intentá de nuevo en unos minutos.', 429);
        }
        if (!mkdir($staging, 0700)) {
            throw new RuntimeException('Unable to stage the upload.');
        }
        if ($file !== null && !move_uploaded_file($file, $staging . '/source')) {
            throw new RuntimeException('Unable to save the uploaded file.');
        }
        writeState($staging, [
            'tokenHash' => hash('sha256', $token),
            'status' => 'queued',
            'createdAt' => time(),
            'attempts' => 0,
            'url' => $url,
        ]);
        if (!rename($staging, $root . '/' . $id)) {
            throw new RuntimeException('Unable to publish the queued job.');
        }
    } finally {
        removeTree($staging);
        fclose($lock);
    }

    respond(202, ['id' => $id, 'token' => $token, 'status' => 'queued']);
}

function jobStatus(string $root): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        throw new HttpError('Usá GET para consultar una transcripción.', 405);
    }
    $directory = requestedJobDirectory($root);
    $state = authorizedJobState($directory);
    $data = ['status' => $state['status']];
    if (isset($state['message'])) {
        $data['message'] = $state['message'];
    }
    if ($state['status'] === 'completed') {
        $text = @file_get_contents($directory . '/transcript.txt');
        if ($text === false) {
            throw new HttpError(TRANSCRIPT_NOT_FOUND, 404);
        }
        $data['text'] = $text;
    }
    if (isset($state['article'])) {
        $data['article'] = publicArticle($state);
    }

    respond(200, $data);
}

function requestedJobDirectory(string $root): string
{
    $id = $_GET['id'] ?? null;
    if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
        throw new HttpError(TRANSCRIPT_NOT_FOUND, 404);
    }
    return $root . '/' . $id;
}

function authorizedJobState(string $directory): array
{
    $token = $_SERVER['HTTP_X_JOB_TOKEN'] ?? '';
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        throw new HttpError(TRANSCRIPT_NOT_FOUND, 404);
    }
    $state = readState($directory);
    if ($state === null || !hash_equals($state['tokenHash'], hash('sha256', $token))) {
        throw new HttpError(TRANSCRIPT_NOT_FOUND, 404);
    }
    return $state;
}

function publicArticle(array $state): ?array
{
    if (!isset($state['article'])) {
        return null;
    }
    return array_intersect_key($state['article'], array_flip(['status', 'title', 'subtitle', 'paragraphs', 'message']));
}

function articleStatusCode(array $article): int
{
    return in_array($article['status'], ['queued', 'processing'], true) ? 202 : 200;
}

function queueArticle(string $root, string $directory): array
{
    $queueLock = fopen($root . '/.queue.lock', 'c');
    if ($queueLock === false) {
        throw new RuntimeException('Unable to open the queue lock.');
    }
    try {
        if (!flock($queueLock, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the queue.');
        }
        $jobLock = @fopen($directory . '/.lock', 'c');
        if ($jobLock === false) {
            throw new HttpError(TRANSCRIPT_NOT_FOUND, 404);
        }
        try {
            $locked = flock($jobLock, LOCK_EX | LOCK_NB);
            $state = authorizedJobState($directory);
            $article = publicArticle($state);
            if ($article !== null && $article['status'] !== 'failed') {
                return $article;
            }
            if ($state['status'] !== 'completed' || !$locked) {
                throw new HttpError('Esperá a que la transcripción esté lista y volvé a intentar.', 409);
            }
            if (!is_file($directory . '/transcript.txt')) {
                throw new HttpError(TRANSCRIPT_NOT_FOUND, 404);
            }
            $pending = 0;
            foreach (jobDirs($root) as $jobDirectory) {
                $job = readState($jobDirectory);
                if ($job !== null && isArticlePending($job)) {
                    $pending++;
                }
            }
            if ($pending >= positiveInt('TRANSAR_MAX_PENDING_JOBS', 6)) {
                throw new HttpError('Hay varias notas en espera. Intentá de nuevo en unos minutos.', 429);
            }
            $state['article'] = ['status' => 'queued', 'attempts' => 0, 'requestedAt' => time()];
            writeState($directory, $state);
            return ['status' => 'queued'];
        } finally {
            fclose($jobLock);
        }
    } finally {
        fclose($queueLock);
    }
}

function articleRequest(string $root): never
{
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new HttpError('Usá GET para consultar una nota o POST para generarla.', 405);
    }
    if ($method === 'POST' && ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        throw new HttpError('Generá la nota desde Transar.', 403);
    }
    $directory = requestedJobDirectory($root);
    $state = authorizedJobState($directory);
    $article = publicArticle($state);
    if ($method === 'GET') {
        respond(200, ['article' => $article]);
    }
    if ($article === null || $article['status'] === 'failed') {
        $article = queueArticle($root, $directory);
    }
    respond(articleStatusCode($article), ['article' => $article]);
}

umask(0077);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $root = jobRoot();
    match ($_GET['action'] ?? '') {
        'create' => createJob($root),
        'status' => jobStatus($root),
        'article' => articleRequest($root),
        default => throw new HttpError('Solicitud desconocida.', 404),
    };
} catch (HttpError $error) {
    respond($error->getCode(), ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('[transar] ' . $error->getMessage());
    respond(500, ['error' => 'No pudimos completar la solicitud. Intentá de nuevo en unos minutos.']);
}
