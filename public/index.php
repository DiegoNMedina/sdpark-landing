<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/reservations.php';

$lots = parking_lots();
$times = [];
$now = new DateTimeImmutable('now', app_timezone());
$recaptchaSiteKey = config('RECAPTCHA_SITE_KEY', '');
$couponUrl = config('COUPON_URL', 'https://sdparkshuttlefly.com/coupons/');

for ($hour = 0; $hour < 24; $hour++) {
    foreach ([0, 30] as $minute) {
        $value = sprintf('%02d:%02d', $hour, $minute);
        $label = DateTimeImmutable::createFromFormat('H:i', $value)->format('h:i A');
        $times[$value] = $label;
    }
}

function render_reservation_form(
    array $lots,
    array $times,
    DateTimeImmutable $now,
    string $recaptchaSiteKey,
    array $lotKeys,
    string $defaultLot,
    string $heading,
    string $subheading
): void {
    $availableLots = array_intersect_key($lots, array_flip($lotKeys));

    if (!isset($availableLots[$defaultLot])) {
        $defaultLot = array_key_first($availableLots);
    }
    ?>
    <aside class="reservation-card" aria-label="<?= htmlspecialchars($heading) ?>">
      <div class="reservation-card__header">
        <p class="eyebrow">Lock your rate</p>
        <h2><?= htmlspecialchars($heading) ?></h2>
        <p><?= htmlspecialchars($subheading) ?></p>
      </div>

      <form
        class="reservation-form"
        action="/api/create-reservation.php"
        method="post"
        data-reservation-form
        data-current-date="<?= htmlspecialchars($now->format('Y-m-d')) ?>"
        data-current-time="<?= htmlspecialchars($now->format('H:i')) ?>"
        data-min-reservation-days="<?= min_reservation_days() ?>"
        data-recaptcha-site-key="<?= htmlspecialchars($recaptchaSiteKey) ?>"
        novalidate
      >
        <input type="hidden" name="recaptcha_token" value="" data-recaptcha-token>
        <div class="stepper" aria-label="Reservation steps">
          <span class="stepper__item is-active" data-step-indicator="0">Trip</span>
          <span class="stepper__item" data-step-indicator="1">Details</span>
          <span class="stepper__item" data-step-indicator="2">Review</span>
        </div>

        <fieldset class="form-step is-active" data-form-step="0">
          <legend>Trip Details</legend>
          <div class="form-grid form-grid--two">
            <label>
              Drop Off
              <input type="date" name="dropoff_date" required data-start-date>
            </label>
            <label>
              Drop Off Time
              <select name="dropoff_time" required data-start-time>
                <?php foreach ($times as $value => $label): ?>
                  <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <div class="form-grid form-grid--two">
            <label>
              Pick-Up
              <input type="date" name="pickup_date" required data-end-date>
            </label>
            <label>
              Pick-Up Time
              <select name="pickup_time" required data-end-time>
                <?php foreach ($times as $value => $label): ?>
                  <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <p class="step-error" data-step-error="0" aria-live="polite"></p>
          <button class="button button--full" type="button" data-next-step>Continue</button>
        </fieldset>

        <fieldset class="form-step" data-form-step="1">
          <legend>Your Information</legend>
          <div class="form-grid form-grid--two">
            <label>
              First Name
              <input name="first_name" autocomplete="given-name" required>
            </label>
            <label>
              Last Name
              <input name="last_name" autocomplete="family-name" required>
            </label>
          </div>

          <div class="form-grid form-grid--two">
            <label>
              Email
              <input type="email" name="email" autocomplete="email" required>
            </label>
            <label>
              Phone
              <input type="tel" name="phone" autocomplete="tel" required>
            </label>
          </div>

          <label>
            How did you hear about us?
            <select name="source">
              <option value="">Select one</option>
              <option>Google</option>
              <option>Yelp</option>
              <option>Friend</option>
              <option>Street Sign</option>
              <option>Repeat Customer</option>
            </select>
          </label>

          <p class="step-error" data-step-error="1" aria-live="polite"></p>
          <div class="form-actions">
            <button class="button button--ghost" type="button" data-prev-step>Back</button>
            <button class="button" type="button" data-next-step>Review</button>
          </div>
        </fieldset>

        <fieldset class="form-step" data-form-step="2">
          <legend>Review & Reserve</legend>
          <div class="lot-choice" data-lot-choice>
            <span class="lot-choice__label">Parking Lot</span>
            <div class="lot-pills" role="radiogroup" aria-label="Parking Lot">
              <?php foreach ($availableLots as $key => $lot): ?>
                <?php
                  $shortName = match ($key) {
                      'lot-a' => 'Lot A',
                      'lot-b' => 'Lot B',
                      'cruise' => 'Cruise',
                      default => $lot['name'],
                  };
                ?>
                <button
                  class="lot-pill<?= $key === $defaultLot ? ' is-active' : '' ?>"
                  type="button"
                  role="radio"
                  aria-checked="<?= $key === $defaultLot ? 'true' : 'false' ?>"
                  data-lot-pill="<?= htmlspecialchars($key) ?>"
                >
                  <strong><?= htmlspecialchars($shortName) ?></strong>
                  <span>$<?= number_format($lot['daily_rate_cents'] / 100, 2) ?>/day</span>
                </button>
              <?php endforeach; ?>
            </div>
            <select class="visually-hidden" name="lot" required data-rate-source aria-hidden="true" tabindex="-1">
              <?php foreach ($availableLots as $key => $lot): ?>
                <option value="<?= htmlspecialchars($key) ?>" data-rate="<?= (int) $lot['daily_rate_cents'] ?>"<?= $key === $defaultLot ? ' selected' : '' ?>>
                  <?= htmlspecialchars($lot['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="reservation-summary">
            <div>
              <span>Drop off</span>
              <strong data-summary-dropoff>--</strong>
            </div>
            <div>
              <span>Pick-up</span>
              <strong data-summary-pickup>--</strong>
            </div>
            <div>
              <span>Customer</span>
              <strong data-summary-customer>--</strong>
            </div>
          </div>

          <div class="estimate" aria-live="polite">
            <span>Estimated total</span>
            <strong data-estimate-total>$18.95</strong>
          </div>

          <p class="step-error" data-step-error="2" aria-live="polite"></p>
          <div class="form-actions">
            <button class="button button--ghost" type="button" data-prev-step>Back</button>
            <button class="button" type="submit">Complete Reservation</button>
          </div>
          <p class="form-note">Free shuttle included. Your confirmation will be sent by email.</p>
        </fieldset>
      </form>
    </aside>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Airport Parking in San Diego | SD Park Shuttle & Fly</title>
  <meta name="description" content="Reserve affordable San Diego airport and cruise parking with free shuttle service from SD Park Shuttle & Fly.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body>
  <header class="site-header">
    <div class="topbar">
      <div class="container topbar__inner">
        <span class="topbar__label">Located at:</span>
        <a href="https://goo.gl/maps/" class="topbar__link">Lot A: 3405 Pacific Highway</a>
        <a href="https://goo.gl/maps/" class="topbar__link">Lot B: 3275 Pacific Highway</a>
      </div>
    </div>

    <nav class="nav container" aria-label="Main navigation">
      <div class="brand-block">
        <a href="/" class="brand" aria-label="SD Park Shuttle & Fly home">
          <img class="brand__logo" src="/assets/images/logosdpark.png" alt="SD Park Shuttle & Fly Airport Parking">
        </a>
        <a class="brand__phone" href="tel:+16192911234">(619) 291-1234</a>
      </div>
      <div class="nav__links">
        <a href="#rates">Rates</a>
        <a href="#airport-parking">Airport</a>
        <a href="#cruise-parking">Cruise</a>
        <a href="#airport-parking" class="button button--small">Reserve</a>
      </div>
    </nav>
  </header>

  <main>
    <section class="rate-band" id="rates">
      <div class="container rate-band__grid">
        <div class="rate-band__content">
          <p class="promo-badge">Coupon savings</p>
          <h2>Save $6 every day you park</h2>
          <p>Reserve with the SD Park coupon rate and keep more money for your trip. Airport and cruise shuttle service is included.</p>
          <div class="rate-band__actions">
            <a class="button button--light" href="#airport-parking">Reserve Airport Parking</a>
            <a class="button button--outline-light" href="#cruise-parking">Reserve Cruise Parking</a>
          </div>
        </div>
        <a class="savings-card" href="<?= htmlspecialchars((string) $couponUrl) ?>" aria-label="View SD Park coupon">
          <div>
            <span>Regular</span>
            <del>$24.95</del>
          </div>
          <strong>$18.95</strong>
          <small>Coupon daily rate</small>
          <em>$6/day savings</em>
        </a>
      </div>
    </section>

    <section class="hero reservation-section reservation-section--airport" id="airport-parking">
      <div class="container hero__grid">
        <div class="hero__content">
          <p class="eyebrow">San Diego's Park, Shuttle & Fly</p>
          <h1>Airport Parking for SAN</h1>
          <p class="hero__lead">Choose Lot A or Lot B on Pacific Highway and reserve your airport parking minutes from San Diego International Airport.</p>
          <div class="hero__actions">
            <a class="button" href="#airport-parking">Reserve Airport Parking</a>
          </div>
          <div class="trust-row" aria-label="Highlights">
            <span>Family owned</span>
            <span>Free shuttle</span>
            <span>Lot A & Lot B</span>
          </div>
        </div>

        <?php render_reservation_form($lots, $times, $now, $recaptchaSiteKey, ['lot-a', 'lot-b'], 'lot-a', '$18.95 daily rate', 'Reserve Lot A or Lot B now. Payment is not required today.'); ?>
      </div>
    </section>

    <section class="reservation-section reservation-section--cruise" id="cruise-parking">
      <div class="container reservation-section__grid reservation-section__grid--reverse">
        <?php render_reservation_form($lots, $times, $now, $recaptchaSiteKey, ['cruise'], 'cruise', 'Cruise parking reservation', 'Reserve your cruise parking now. Payment is not required today.'); ?>
        <div class="reservation-section__content">
          <p class="eyebrow">Cruise parking</p>
          <h2>Parking for the Port of San Diego</h2>
          <p>Reserve cruise parking before boarding and ride the courtesy shuttle to and from the cruise terminals.</p>
          <ul class="check-list">
            <li>Free shuttle service to and from cruise terminals</li>
            <li>Competitive daily rates</li>
            <li>Secure parking with convenient access</li>
            <li>Regular-size parking spots for standard vehicles</li>
          </ul>
        </div>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container footer__grid">
      <div>
        <strong>SD Park Shuttle & Fly</strong>
        <p>Airport and cruise parking in San Diego.</p>
      </div>
    </div>
  </footer>

  <?php if ($recaptchaSiteKey !== ''): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?= urlencode($recaptchaSiteKey) ?>" defer></script>
  <?php endif; ?>
  <script src="/assets/js/app.js" defer></script>
</body>
</html>
