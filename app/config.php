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

function app_timezone(): DateTimeZone
{
    return new DateTimeZone(config('APP_TIMEZONE', 'America/Los_Angeles'));
}

function min_reservation_days(): int
{
    return max(1, (int) config('MIN_RESERVATION_DAYS', '1'));
}

function access_fee_cents(): int
{
    return max(0, (int) config('ACCESS_FEE_CENTS', '475'));
}

function render_tracking_snippet(string $filename): void
{
    $path = dirname(__DIR__) . '/GA/' . basename($filename);

    if (!is_file($path)) {
        return;
    }

    readfile($path);
    echo "\n";
}
