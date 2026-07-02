<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/stripe.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$reservation = sanitize_reservation($_POST);
$errors = validate_reservation($reservation);

if ($errors !== []) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode([
        'message' => 'Please review the reservation details.',
        'errors' => $errors,
    ]);
    exit;
}

try {
    $session = create_reservation_checkout_session($reservation);
    header('Location: ' . $session->url, true, 303);
    exit;
} catch (Throwable $exception) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Checkout Setup Needed | SD Park Shuttle & Fly</title>
      <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/css/styles.css')) ?>">
    </head>
    <body class="simple-page">
      <main class="confirmation">
        <p class="eyebrow">Checkout unavailable</p>
        <h1>Stripe needs to be configured.</h1>
        <p>The landing page is working, but checkout requires Composer dependencies and Stripe environment keys before payments can be created.</p>
        <p class="confirmation__meta"><?= htmlspecialchars($exception->getMessage()) ?></p>
        <a class="button" href="<?= htmlspecialchars(app_url('/')) ?>">Back to landing page</a>
      </main>
    </body>
    </html>
    <?php
}
