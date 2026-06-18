<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reservations.php';

function reservation_storage_path(string $reservationId): string
{
    return dirname(__DIR__) . '/storage/reservations/' . preg_replace('/[^A-Za-z0-9_-]/', '', $reservationId) . '.json';
}

function processed_storage_path(string $sessionId): string
{
    return dirname(__DIR__) . '/storage/processed/' . preg_replace('/[^A-Za-z0-9_-]/', '', $sessionId) . '.json';
}

function reservation_id(): string
{
    return 'sdpark_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
}

function confirmation_number_from_reservation_id(string $reservationId): string
{
    return 'SDP-' . strtoupper(substr(hash('crc32b', $reservationId), 0, 8));
}

function ensure_confirmation_number(array $payload): array
{
    if (empty($payload['confirmation_number'])) {
        $payload['confirmation_number'] = confirmation_number_from_reservation_id((string) $payload['reservation_id']);
    }

    return $payload;
}

function reservation_payload_from_form(array $reservation, string $reservationId): array
{
    $lots = parking_lots();
    $lot = $lots[$reservation['lot']] ?? $lots['lot-a'];
    $days = reservation_days($reservation);
    $totalCents = reservation_total_cents($reservation);

    return [
        'reservation_id' => $reservationId,
        'confirmation_number' => confirmation_number_from_reservation_id($reservationId),
        'status' => 'pending_payment',
        'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'customer' => [
            'first_name' => $reservation['first_name'],
            'last_name' => $reservation['last_name'],
            'full_name' => trim($reservation['first_name'] . ' ' . $reservation['last_name']),
            'email' => $reservation['email'],
            'phone' => $reservation['phone'],
            'source' => $reservation['source'],
        ],
        'parking' => [
            'lot_key' => $reservation['lot'],
            'lot_name' => $lot['name'],
            'lot_address' => $lot['address'],
            'dropoff_date' => $reservation['dropoff_date'],
            'dropoff_time' => $reservation['dropoff_time'],
            'pickup_date' => $reservation['pickup_date'],
            'pickup_time' => $reservation['pickup_time'],
            'days' => $days,
            'daily_rate_cents' => $lot['daily_rate_cents'],
        ],
        'payment' => [
            'provider' => 'stripe',
            'currency' => config('STRIPE_CURRENCY', 'usd'),
            'amount_total_cents' => $totalCents,
            'checkout_session_id' => null,
            'payment_status' => 'unpaid',
        ],
    ];
}

function save_reservation_payload(array $payload): void
{
    $payload = ensure_confirmation_number($payload);

    file_put_contents(
        reservation_storage_path((string) $payload['reservation_id']),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function load_reservation_payload(string $reservationId): ?array
{
    $path = reservation_storage_path($reservationId);

    if (!is_file($path)) {
        return null;
    }

    $payload = json_decode((string) file_get_contents($path), true);

    return is_array($payload) ? ensure_confirmation_number($payload) : null;
}

function update_reservation_with_stripe_session(array $payload, object $session): array
{
    $payload = ensure_confirmation_number($payload);
    $payload['status'] = (($session->payment_status ?? '') === 'paid') ? 'paid' : 'pending_payment';
    $payload['payment']['checkout_session_id'] = (string) ($session->id ?? '');
    $payload['payment']['payment_status'] = (string) ($session->payment_status ?? 'unpaid');
    $payload['payment']['amount_total_cents'] = (int) ($session->amount_total ?? $payload['payment']['amount_total_cents']);
    $payload['payment']['currency'] = (string) ($session->currency ?? $payload['payment']['currency']);
    $payload['payment']['stripe_payment_intent'] = (string) ($session->payment_intent ?? '');
    $payload['updated_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

    return $payload;
}

function stripe_metadata_to_array(object $session): array
{
    $metadata = $session->metadata ?? [];

    if (is_object($metadata) && method_exists($metadata, 'toArray')) {
        return $metadata->toArray();
    }

    return (array) $metadata;
}

function reservation_payload_from_stripe_session(object $session): array
{
    $metadata = stripe_metadata_to_array($session);
    $reservationId = (string) ($metadata['reservation_id'] ?? '');
    $payload = $reservationId !== '' ? load_reservation_payload($reservationId) : null;

    if (!$payload) {
        $payload = reservation_payload_from_form([
            'first_name' => (string) ($metadata['first_name'] ?? ''),
            'last_name' => (string) ($metadata['last_name'] ?? ''),
            'email' => (string) ($session->customer_email ?? $metadata['email'] ?? ''),
            'phone' => (string) ($metadata['phone'] ?? ''),
            'lot' => (string) ($metadata['lot'] ?? 'lot-a'),
            'dropoff_date' => (string) ($metadata['dropoff_date'] ?? ''),
            'dropoff_time' => (string) ($metadata['dropoff_time'] ?? ''),
            'pickup_date' => (string) ($metadata['pickup_date'] ?? ''),
            'pickup_time' => (string) ($metadata['pickup_time'] ?? ''),
            'source' => (string) ($metadata['source'] ?? ''),
        ], $reservationId !== '' ? $reservationId : reservation_id());
    }

    $payload = update_reservation_with_stripe_session($payload, $session);
    save_reservation_payload($payload);

    return $payload;
}

function money_from_cents(int $cents, string $currency = 'usd'): string
{
    return strtoupper($currency) . ' $' . number_format($cents / 100, 2);
}

function reservation_display_rows(array $payload): array
{
    $payload = ensure_confirmation_number($payload);

    return [
        'Confirmation #' => $payload['confirmation_number'],
        'Status' => ucwords(str_replace('_', ' ', (string) $payload['status'])),
        'Name' => $payload['customer']['full_name'],
        'Email' => $payload['customer']['email'],
        'Phone' => $payload['customer']['phone'],
        'Parking Lot' => $payload['parking']['lot_name'],
        'Address' => $payload['parking']['lot_address'],
        'Drop Off' => $payload['parking']['dropoff_date'] . ' ' . $payload['parking']['dropoff_time'],
        'Pick-Up' => $payload['parking']['pickup_date'] . ' ' . $payload['parking']['pickup_time'],
        'Days' => (string) $payload['parking']['days'],
        'Daily Rate' => money_from_cents((int) $payload['parking']['daily_rate_cents'], (string) $payload['payment']['currency']),
        'Total Paid' => money_from_cents((int) $payload['payment']['amount_total_cents'], (string) $payload['payment']['currency']),
        'How Did You Hear About Us?' => $payload['customer']['source'] ?: 'Not provided',
    ];
}

function reservation_public_view(array $payload): array
{
    $payload = ensure_confirmation_number($payload);

    return [
        'confirmation_number' => $payload['confirmation_number'],
        'status' => ucwords(str_replace('_', ' ', (string) $payload['status'])),
        'customer_name' => $payload['customer']['full_name'],
        'customer_email' => $payload['customer']['email'],
        'customer_phone' => $payload['customer']['phone'],
        'lot_name' => $payload['parking']['lot_name'],
        'lot_address' => $payload['parking']['lot_address'],
        'dropoff' => $payload['parking']['dropoff_date'] . ' at ' . $payload['parking']['dropoff_time'],
        'pickup' => $payload['parking']['pickup_date'] . ' at ' . $payload['parking']['pickup_time'],
        'days' => (string) $payload['parking']['days'],
        'daily_rate' => money_from_cents((int) $payload['parking']['daily_rate_cents'], (string) $payload['payment']['currency']),
        'total_paid' => money_from_cents((int) $payload['payment']['amount_total_cents'], (string) $payload['payment']['currency']),
        'source' => $payload['customer']['source'] ?: 'Not provided',
    ];
}
