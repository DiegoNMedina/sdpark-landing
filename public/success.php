<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/integrations.php';

$confirmation = (string) ($_GET['confirmation'] ?? '');
$payload = null;
$processResult = null;
$error = '';

if ($confirmation !== '') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $confirmationData = $_SESSION['reservation_confirmation'] ?? null;

    if (
        is_array($confirmationData)
        && isset($confirmationData['payload'])
        && ($confirmationData['payload']['confirmation_number'] ?? '') === $confirmation
    ) {
        $payload = $confirmationData['payload'];
        $processResult = $confirmationData['process_result'] ?? null;
    } else {
        $error = 'Reservation details could not be found.';
    }
}

$reservation = $payload ? reservation_public_view($payload) : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reservation Confirmed | SD Park Shuttle & Fly</title>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body class="simple-page">
  <main class="confirmation confirmation--wide confirmation--receipt">
    <div class="confirmation-hero">
      <span class="confirmation-hero__mark" aria-hidden="true">✓</span>
      <div>
        <p class="eyebrow">Reservation complete</p>
        <h1>Your parking is reserved.</h1>
        <p>We sent your reservation details to your email. Please keep this page for your records.</p>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <p class="step-error is-visible"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($reservation !== []): ?>
      <section class="receipt-summary" aria-label="Reservation summary">
        <div>
          <span>Status</span>
          <strong><?= htmlspecialchars($reservation['status']) ?></strong>
        </div>
        <div>
          <span>Estimated total</span>
          <strong><?= htmlspecialchars($reservation['estimated_total']) ?></strong>
        </div>
        <div>
          <span>Confirmation #</span>
          <strong><?= htmlspecialchars($reservation['confirmation_number']) ?></strong>
        </div>
        <?php if (!empty($processResult['api_reservation_id'])): ?>
          <div>
            <span>Reservation ID</span>
            <strong><?= htmlspecialchars((string) $processResult['api_reservation_id']) ?></strong>
          </div>
        <?php endif; ?>
      </section>

      <section class="receipt-grid">
        <article class="receipt-panel receipt-panel--accent">
          <span class="receipt-panel__label">Trip</span>
          <h2><?= htmlspecialchars($reservation['lot_name']) ?></h2>
          <p><?= htmlspecialchars($reservation['lot_address']) ?></p>
          <div class="receipt-timeline">
            <div>
              <span>Drop off</span>
              <strong><?= htmlspecialchars($reservation['dropoff']) ?></strong>
            </div>
            <div>
              <span>Pick-up</span>
              <strong><?= htmlspecialchars($reservation['pickup']) ?></strong>
            </div>
          </div>
        </article>

        <article class="receipt-panel">
          <span class="receipt-panel__label">Customer</span>
          <h2><?= htmlspecialchars($reservation['customer_name']) ?></h2>
          <dl class="receipt-list">
            <div>
              <dt>Email</dt>
              <dd><?= htmlspecialchars($reservation['customer_email']) ?></dd>
            </div>
            <div>
              <dt>Phone</dt>
              <dd><?= htmlspecialchars($reservation['customer_phone']) ?></dd>
            </div>
            <div>
              <dt>Source</dt>
              <dd><?= htmlspecialchars($reservation['source']) ?></dd>
            </div>
          </dl>
        </article>

        <article class="receipt-panel">
          <span class="receipt-panel__label">Estimate</span>
          <dl class="receipt-list">
            <div>
              <dt>Days</dt>
              <dd><?= htmlspecialchars($reservation['days']) ?></dd>
            </div>
            <div>
              <dt>Daily rate</dt>
              <dd><?= htmlspecialchars($reservation['daily_rate']) ?></dd>
            </div>
            <div>
              <dt>Estimated total</dt>
              <dd><?= htmlspecialchars($reservation['estimated_total']) ?></dd>
            </div>
          </dl>
        </article>
      </section>
    <?php endif; ?>

    <?php if (is_array($processResult)): ?>
      <p class="receipt-note">
        <?= !empty($processResult['emails']['customer']) ? 'A confirmation email has been sent.' : 'A confirmation email will be sent shortly.' ?>
      </p>
    <?php endif; ?>

    <div class="receipt-actions">
      <a class="button" href="/">Back to home</a>
      <a class="button button--ghost" href="tel:+16192911234">Call SD Park</a>
    </div>
  </main>
</body>
</html>
