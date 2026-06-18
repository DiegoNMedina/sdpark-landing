<?php

declare(strict_types=1);

function parking_lots(): array
{
    return [
        'lot-a' => [
            'name' => 'Lot A - Airport Parking',
            'address' => '3405 Pacific Highway, San Diego, CA 92101',
            'daily_rate_cents' => 1895,
        ],
        'lot-b' => [
            'name' => 'Lot B - Airport Parking',
            'address' => '3275 Pacific Highway, San Diego, CA 92101',
            'daily_rate_cents' => 1895,
        ],
        'cruise' => [
            'name' => 'Cruise Parking',
            'address' => '3405 Pacific Highway, San Diego, CA 92101',
            'daily_rate_cents' => 1895,
        ],
    ];
}

function sanitize_reservation(array $input): array
{
    $lotKey = (string) ($input['lot'] ?? 'lot-a');
    $lots = parking_lots();

    if (!isset($lots[$lotKey])) {
        $lotKey = 'lot-a';
    }

    return [
        'first_name' => trim((string) ($input['first_name'] ?? '')),
        'last_name' => trim((string) ($input['last_name'] ?? '')),
        'email' => filter_var((string) ($input['email'] ?? ''), FILTER_SANITIZE_EMAIL),
        'phone' => trim((string) ($input['phone'] ?? '')),
        'lot' => $lotKey,
        'dropoff_date' => trim((string) ($input['dropoff_date'] ?? '')),
        'dropoff_time' => trim((string) ($input['dropoff_time'] ?? '')),
        'pickup_date' => trim((string) ($input['pickup_date'] ?? '')),
        'pickup_time' => trim((string) ($input['pickup_time'] ?? '')),
        'source' => trim((string) ($input['source'] ?? '')),
    ];
}

function validate_reservation(array $reservation): array
{
    $errors = [];

    foreach (['first_name', 'last_name', 'email', 'phone', 'dropoff_date', 'dropoff_time', 'pickup_date', 'pickup_time'] as $field) {
        if ($reservation[$field] === '') {
            $errors[$field] = 'This field is required.';
        }
    }

    if ($reservation['email'] !== '' && !filter_var($reservation['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    $start = reservation_datetime($reservation['dropoff_date'], $reservation['dropoff_time']);
    $end = reservation_datetime($reservation['pickup_date'], $reservation['pickup_time']);

    if (!$start) {
        $errors['dropoff_date'] = 'Please choose a valid drop-off date and time.';
    }

    if (!$end) {
        $errors['pickup_date'] = 'Please choose a valid pick-up date and time.';
    }

    if ($start && $end && $end <= $start) {
        $errors['pickup_date'] = 'Pick-up must be after drop-off.';
    }

    return $errors;
}

function reservation_datetime(string $date, string $time): ?DateTimeImmutable
{
    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time);

    return $dateTime ?: null;
}

function reservation_days(array $reservation): int
{
    $start = reservation_datetime($reservation['dropoff_date'], $reservation['dropoff_time']);
    $end = reservation_datetime($reservation['pickup_date'], $reservation['pickup_time']);

    if (!$start || !$end || $end <= $start) {
        return 1;
    }

    $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;

    return max(1, (int) ceil($hours / 24));
}

function reservation_total_cents(array $reservation): int
{
    $lots = parking_lots();
    $lot = $lots[$reservation['lot']] ?? $lots['lot-a'];

    return reservation_days($reservation) * $lot['daily_rate_cents'];
}
