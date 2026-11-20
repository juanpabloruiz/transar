<?php

declare(strict_types=1);

namespace Transar;

use FilesystemIterator;
use RuntimeException;

function setting(string $name, string $default): string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function positiveInt(string $name, int $default): int
{
    $value = filter_var(getenv($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? $default : $value;
}

function jobRoot(): string
{
    return rtrim(setting('TRANSAR_JOBS_DIR', '/var/lib/transar/jobs'), '/');
}

function maxUploadBytes(): int
{
    $serverLimit = 500 * 1024 * 1024;
    return min(positiveInt('TRANSAR_MAX_UPLOAD_BYTES', $serverLimit), $serverLimit);
}

function jobDirs(string $root): array
{
    return glob($root . '/[0-9a-f]*', GLOB_ONLYDIR) ?: [];
}

function readState(string $directory): ?array
{
    $json = @file_get_contents($directory . '/state.json');
    return $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

function writeState(string $directory, array $state): void
{
    $temp = $directory . '/state.tmp';
    $state['updatedAt'] = time();
    if (file_put_contents($temp, json_encode($state, JSON_THROW_ON_ERROR)) === false
        || !rename($temp, $directory . '/state.json')
    ) {
        throw new RuntimeException('Unable to persist the job state.');
    }
}

function isPending(array $state): bool
{
    return in_array($state['status'], ['queued', 'processing'], true);
}

function isArticlePending(array $state): bool
{
    return in_array($state['article']['status'] ?? null, ['queued', 'processing'], true);
}

function removeTree(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory) as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) {
            removeTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}

function youtubeUrl(string $input): ?string
{
    $parts = parse_url($input);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
    ) {
        return null;
    }

    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '';
    if ($host === 'youtu.be') {
        $id = substr($path, 1);
    } elseif (!in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
        return null;
    } elseif ($path === '/watch') {
        parse_str($parts['query'] ?? '', $query);
        $id = $query['v'] ?? null;
    } elseif (preg_match('~^/(?:shorts|live|embed)/([^/]+)/?$~D', $path, $match) === 1) {
        $id = $match[1];
    } else {
        return null;
    }

    return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{11}$/D', $id) === 1
        ? 'https://www.youtube.com/watch?v=' . $id
        : null;
}
