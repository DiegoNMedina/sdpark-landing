<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

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
    $now = new DateTimeImmutable('now', app_timezone());
    $today = $now->setTime(0, 0);

    if (!$start) {
        $errors['dropoff_date'] = 'Please choose a valid drop-off date and time.';
    }

    if (!$end) {
        $errors['pickup_date'] = 'Please choose a valid pick-up date and time.';
    }

    if ($start && $start < $now) {
        $errors['dropoff_time'] = 'Drop-off date and time cannot be in the past.';
    }

    if ($end && $end < $now) {
        $errors['pickup_time'] = 'Pick-up date and time cannot be in the past.';
    }

    if ($start && $start->setTime(0, 0) < $today) {
        $errors['dropoff_date'] = 'Drop-off date cannot be in the past.';
    }

    if ($end && $end->setTime(0, 0) < $today) {
        $errors['pickup_date'] = 'Pick-up date cannot be in the past.';
    }

    if ($start && $end && $end <= $start) {
        $errors['pickup_date'] = 'Pick-up must be after drop-off.';
    }

    if ($start && $end && $end > $start && reservation_duration_days($start, $end) < min_reservation_days()) {
        $days = min_reservation_days();
        $errors['pickup_date'] = sprintf(
            'Reservation must be at least %d day%s.',
            $days,
            $days === 1 ? '' : 's'
        );
    }

    return $errors;
}

function reservation_datetime(string $date, string $time): ?DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        return null;
    }

    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, app_timezone());

    if (!$dateTime) {
        return null;
    }

    if ($dateTime->format('Y-m-d H:i') !== $date . ' ' . $time) {
        return null;
    }

    return $dateTime;
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

function reservation_duration_days(DateTimeImmutable $start, DateTimeImmutable $end): float
{
    return ($end->getTimestamp() - $start->getTimestamp()) / 86400;
}

function reservation_total_cents(array $reservation): int
{
    $lots = parking_lots();
    $lot = $lots[$reservation['lot']] ?? $lots['lot-a'];

    return reservation_days($reservation) * $lot['daily_rate_cents'];
}
