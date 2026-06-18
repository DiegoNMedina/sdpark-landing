<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reservation_payload.php';
require_once __DIR__ . '/sendgrid.php';

function future_api_payload(array $payload): array
{
    $payload = ensure_confirmation_number($payload);

    return [
        'reservation_id' => $payload['reservation_id'],
        'confirmation_number' => $payload['confirmation_number'],
        'status' => $payload['status'],
        'customer' => $payload['customer'],
        'parking' => $payload['parking'],
        'payment' => $payload['payment'],
        'source_system' => 'sdpark_landing',
    ];
}

function push_reservation_to_future_api(array $payload): bool
{
    $apiPayload = future_api_payload($payload);
    $endpoint = config('RESERVATION_API_ENDPOINT', '');

    file_put_contents(
        dirname(__DIR__) . '/storage/api-payloads/' . $payload['reservation_id'] . '.json',
        json_encode($apiPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    if ($endpoint === '') {
        return false;
    }

    $headers = ['Content-Type: application/json'];
    $token = config('RESERVATION_API_TOKEN', '');

    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($apiPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status >= 200 && $status < 300;
}

function process_completed_reservation(array $payload): array
{
    $payload = ensure_confirmation_number($payload);
    $sessionId = (string) ($payload['payment']['checkout_session_id'] ?? '');
    $processedPath = processed_storage_path($sessionId);

    if ($sessionId !== '' && is_file($processedPath)) {
        $existing = json_decode((string) file_get_contents($processedPath), true);
        return is_array($existing) ? $existing : ['already_processed' => true];
    }

    $result = [
        'processed_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'reservation_id' => $payload['reservation_id'],
        'confirmation_number' => $payload['confirmation_number'],
        'checkout_session_id' => $sessionId,
        'emails' => send_reservation_emails($payload),
        'future_api' => push_reservation_to_future_api($payload),
    ];

    if ($sessionId !== '') {
        file_put_contents($processedPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $result;
}
