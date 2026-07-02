<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/integrations.php';
require_once dirname(__DIR__) . '/app/recaptcha.php';

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

$recaptcha = verify_recaptcha_token((string) ($_POST['recaptcha_token'] ?? ''));

if (!$recaptcha['ok']) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode([
        'message' => 'We could not verify this reservation request. Please try again.',
        'errors' => [
            'recaptcha' => $recaptcha['message'],
        ],
    ]);
    exit;
}

try {
    $payload = reservation_payload_from_form($reservation, reservation_id());
    $processResult = process_reservation_submission($payload);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['reservation_confirmation'] = [
        'payload' => $processResult['payload'],
        'process_result' => [
            'api_reservation_id' => $processResult['api_reservation_id'],
            'emails' => $processResult['emails'],
            'constant_contact' => $processResult['constant_contact'],
        ],
    ];

    header('Location: ' . app_url('/success.php?confirmation=' . urlencode($processResult['payload']['confirmation_number'])), true, 303);
    exit;
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Reservation Error | SD Park Shuttle & Fly</title>
      <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/css/styles.css')) ?>">
    </head>
    <body class="simple-page">
      <main class="confirmation">
        <p class="eyebrow">Reservation unavailable</p>
        <h1>We could not complete your reservation.</h1>
        <p>Please try again or call SD Park Shuttle & Fly for assistance.</p>
        <p class="confirmation__meta"><?= htmlspecialchars($exception->getMessage()) ?></p>
        <a class="button" href="<?= htmlspecialchars(app_url('/')) ?>">Back to landing page</a>
      </main>
    </body>
    </html>
    <?php
}
