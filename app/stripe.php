<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reservations.php';
require_once __DIR__ . '/reservation_payload.php';

function stripe_is_ready(): bool
{
    $secretKey = (string) config('STRIPE_SECRET_KEY', '');

    return class_exists(Stripe\StripeClient::class) && strpos($secretKey, 'sk_') === 0;
}

function create_reservation_checkout_session(array $reservation): object
{
    if (!stripe_is_ready()) {
        throw new RuntimeException('Stripe is not configured. Run composer install and set STRIPE_SECRET_KEY.');
    }

    $reservationId = reservation_id();
    $payload = reservation_payload_from_form($reservation, $reservationId);
    $lot = [
        'name' => $payload['parking']['lot_name'],
        'address' => $payload['parking']['lot_address'],
    ];
    $days = (int) $payload['parking']['days'];
    $totalCents = (int) $payload['payment']['amount_total_cents'];

    $stripe = new Stripe\StripeClient(config('STRIPE_SECRET_KEY'));

    $session = $stripe->checkout->sessions->create([
        'mode' => 'payment',
        'customer_email' => $reservation['email'],
        'client_reference_id' => $reservationId,
        'success_url' => config('RESERVATION_SUCCESS_URL', app_url('/success.php?session_id={CHECKOUT_SESSION_ID}')),
        'cancel_url' => config('RESERVATION_CANCEL_URL', app_url('/?checkout=cancelled')),
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => config('STRIPE_CURRENCY', 'usd'),
                'unit_amount' => $totalCents,
                'product_data' => [
                    'name' => $lot['name'] . ' Reservation',
                    'description' => sprintf('%d day%s at %s', $days, $days === 1 ? '' : 's', $lot['address']),
                ],
            ],
        ]],
        'metadata' => [
            'reservation_id' => $reservationId,
            'first_name' => $reservation['first_name'],
            'last_name' => $reservation['last_name'],
            'email' => $reservation['email'],
            'phone' => $reservation['phone'],
            'lot' => $reservation['lot'],
            'lot_name' => $payload['parking']['lot_name'],
            'dropoff_date' => $reservation['dropoff_date'],
            'dropoff_time' => $reservation['dropoff_time'],
            'pickup_date' => $reservation['pickup_date'],
            'pickup_time' => $reservation['pickup_time'],
            'days' => (string) $days,
            'source' => $reservation['source'],
        ],
    ]);

    $payload = update_reservation_with_stripe_session($payload, $session);
    save_reservation_payload($payload);

    return $session;
}

function retrieve_checkout_session(string $sessionId): object
{
    if (!stripe_is_ready()) {
        throw new RuntimeException('Stripe is not configured. Run composer install and set STRIPE_SECRET_KEY.');
    }

    $stripe = new Stripe\StripeClient(config('STRIPE_SECRET_KEY'));

    return $stripe->checkout->sessions->retrieve($sessionId, []);
}
