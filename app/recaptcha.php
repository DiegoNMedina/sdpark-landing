<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function recaptcha_is_configured(): bool
{
    return config('RECAPTCHA_SECRET_KEY', '') !== '';
}

function verify_recaptcha_token(string $token, string $expectedAction = 'reservation_submit'): array
{
    if (!recaptcha_is_configured()) {
        return [
            'ok' => true,
            'skipped' => true,
            'message' => 'reCAPTCHA is not configured.',
        ];
    }

    if ($token === '') {
        return [
            'ok' => false,
            'message' => 'reCAPTCHA token is missing.',
        ];
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => config('RECAPTCHA_SECRET_KEY'),
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'message' => 'reCAPTCHA verification request failed.',
            'error' => $error,
        ];
    }

    $data = json_decode((string) $response, true);

    if (!is_array($data) || empty($data['success'])) {
        return [
            'ok' => false,
            'message' => 'reCAPTCHA verification failed.',
            'response' => $data,
        ];
    }

    $score = (float) ($data['score'] ?? 0);
    $minScore = (float) config('RECAPTCHA_MIN_SCORE', '0.5');
    $action = (string) ($data['action'] ?? '');

    if ($action !== '' && $action !== $expectedAction) {
        return [
            'ok' => false,
            'message' => 'reCAPTCHA action mismatch.',
            'response' => $data,
        ];
    }

    if ($score < $minScore) {
        return [
            'ok' => false,
            'message' => 'reCAPTCHA score is too low.',
            'response' => $data,
        ];
    }

    return [
        'ok' => true,
        'score' => $score,
        'response' => $data,
    ];
}
