<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/config.php';
require_once dirname(__DIR__) . '/app/integrations.php';

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhookSecret = config('STRIPE_WEBHOOK_SECRET', '');
$event = null;

try {
    if (class_exists(Stripe\Webhook::class) && $webhookSecret !== '') {
        $event = Stripe\Webhook::constructEvent($payload, $signature, $webhookSecret);
    } else {
        $event = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
    }
} catch (Throwable $exception) {
    http_response_code(400);
    echo 'Invalid webhook payload';
    exit;
}

if (($event->type ?? '') === 'checkout.session.completed') {
    $session = $event->data->object;
    $payload = reservation_payload_from_stripe_session($session);

    if (($payload['payment']['payment_status'] ?? '') === 'paid') {
        $payload['status'] = 'paid';
        save_reservation_payload($payload);
        process_completed_reservation($payload);
    }

    $logLine = sprintf(
        "[%s] checkout.session.completed %s\n",
        (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        json_encode($payload)
    );

    file_put_contents(dirname(__DIR__) . '/storage/logs/stripe-webhooks.log', $logLine, FILE_APPEND);
}

http_response_code(200);
echo 'ok';
