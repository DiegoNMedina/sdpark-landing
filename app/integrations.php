<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reservation_payload.php';
require_once __DIR__ . '/sendgrid.php';

function future_api_payload(array $payload): array
{
    $payload = ensure_confirmation_number($payload);

    return [
        'first_name' => $payload['customer']['first_name'],
        'last_name' => $payload['customer']['last_name'],
        'email' => $payload['customer']['email'],
        'phone' => $payload['customer']['phone'],
        'parking_lot' => api_parking_lot_value((string) $payload['parking']['lot_key']),
        'start_date' => $payload['parking']['dropoff_date'],
        'drop_off_time' => api_time_value((string) $payload['parking']['dropoff_time']),
        'end_date' => $payload['parking']['pickup_date'],
        'pick_up_time' => api_time_value((string) $payload['parking']['pickup_time']),
        'how_did_you_hear_about_us' => $payload['customer']['source'] ?: 'Landing Page',
    ];
}

function api_parking_lot_value(string $lotKey): int
{
    return match ($lotKey) {
        'lot-a' => 1,
        'lot-b' => 2,
        'cruise' => 3,
        default => 1,
    };
}

function api_time_value(string $time): string
{
    if ($time === '') {
        return '';
    }

    return strlen($time) === 5 ? $time . ':00' : $time;
}

function push_reservation_to_future_api(array $payload): bool
{
    return submit_reservation_to_api($payload)['ok'];
}

function submit_reservation_to_api(array $payload): array
{
    $payload = ensure_confirmation_number($payload);
    $apiPayload = future_api_payload($payload);
    $endpoint = config('RESERVATION_API_ENDPOINT', '');
    $lotKey = (string) ($payload['parking']['lot_key'] ?? 'lot-a');

    if ($endpoint === '') {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'message' => 'Reservation API endpoint is not configured.',
        ];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $token = reservation_api_key_for_lot($lotKey);

    if ($token !== '') {
        $headers[] = 'api-key: ' . $token;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($apiPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $decoded = is_string($response) ? json_decode($response, true) : null;
    $ok = $status >= 200 && $status < 300;

    file_put_contents(
        dirname(__DIR__) . '/storage/logs/reservation-api.log',
        json_encode([
            'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'reservation_id' => $payload['reservation_id'],
            'confirmation_number' => $payload['confirmation_number'] ?? null,
            'parking_lot' => $lotKey,
            'api_key_scope' => reservation_api_key_scope_for_lot($lotKey),
            'status' => $status,
            'ok' => $ok,
            'error' => $error,
            'response' => is_string($response) ? mb_substr($response, 0, 1000) : null,
        ], JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND
    );

    return [
        'ok' => $ok,
        'status' => $status,
        'data' => is_array($decoded) ? $decoded : null,
        'message' => is_array($decoded) ? (string) ($decoded['msg'] ?? $decoded['message'] ?? '') : $error,
    ];
}

function reservation_api_key_for_lot(string $lotKey): string
{
    $scopedKey = config(reservation_api_key_config_name_for_lot($lotKey), '');

    if ($scopedKey !== '') {
        return $scopedKey;
    }

    return config('RESERVATION_API_KEY', config('RESERVATION_API_TOKEN', '')) ?? '';
}

function reservation_api_key_scope_for_lot(string $lotKey): string
{
    $configName = reservation_api_key_config_name_for_lot($lotKey);

    if (config($configName, '') !== '') {
        return $configName;
    }

    return config('RESERVATION_API_KEY', '') !== '' ? 'RESERVATION_API_KEY' : 'RESERVATION_API_TOKEN';
}

function reservation_api_key_config_name_for_lot(string $lotKey): string
{
    return $lotKey === 'cruise' ? 'RESERVATION_API_KEY_CRUISE' : 'RESERVATION_API_KEY_AIRPORT';
}

function process_reservation_submission(array $payload): array
{
    $payload = ensure_confirmation_number($payload);
    $apiResult = submit_reservation_to_api($payload);

    if (!$apiResult['ok']) {
        throw new RuntimeException('Reservation API error: ' . ($apiResult['message'] ?: 'Unable to create reservation.'));
    }

    $apiReservationId = $apiResult['data']['data']['id'] ?? null;

    if ($apiReservationId !== null) {
        $payload['api_reservation_id'] = $apiReservationId;
    }

    return [
        'processed_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'reservation_id' => $payload['reservation_id'],
        'confirmation_number' => $payload['confirmation_number'],
        'api_reservation_id' => $apiReservationId,
        'emails' => send_reservation_emails($payload),
        'api' => $apiResult,
        'payload' => $payload,
    ];
}

function process_completed_reservation(array $payload): array
{
    $payload = ensure_confirmation_number($payload);
    $sessionId = (string) ($payload['payment']['checkout_session_id'] ?? '');
    $processedKey = $sessionId !== '' ? $sessionId : (string) $payload['reservation_id'];
    $processedPath = processed_storage_path($processedKey);

    if (is_file($processedPath)) {
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

    file_put_contents($processedPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $result;
}
