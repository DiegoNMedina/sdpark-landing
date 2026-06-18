<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;

    if (class_exists(Dotenv\Dotenv::class)) {
        Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    }
}

function config(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function app_url(string $path = ''): string
{
    $base = rtrim(config('APP_URL', 'http://localhost:8000'), '/');
    $path = '/' . ltrim($path, '/');

    return $base . ($path === '/' ? '' : $path);
}
