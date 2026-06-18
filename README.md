# SD Park Landing

Promotional landing page for SD Park Shuttle & Fly built with PHP, HTML, CSS, and small vanilla JavaScript.

## Local Run

```bash
php -S 127.0.0.1:8087 -t public
```

Then open:

```txt
http://127.0.0.1:8087
```

## Reservation Flow

Install dependencies:

```bash
composer install
```

Create a local `.env` from `.env.example` and set:

```txt
APP_URL=http://127.0.0.1:8087
APP_TIMEZONE=America/Los_Angeles
MIN_RESERVATION_DAYS=1
SENDGRID_API_KEY=SG_...
MAIL_FROM_EMAIL=no-reply@sdparkshuttlefly.com
ADMIN_EMAIL=reservations@sdparkshuttlefly.com
RESERVATION_API_ENDPOINT=https://tudominio.com/api/reservation
RESERVATION_API_KEY=...
RECAPTCHA_SITE_KEY=...
RECAPTCHA_SECRET_KEY=...
RECAPTCHA_MIN_SCORE=0.5
```

Reservations submit to:

```txt
/api/create-reservation.php
```

The reservation is posted to `RESERVATION_API_ENDPOINT` with the `api-key` header, then confirmation emails are prepared/sent through SendGrid. The current flow does not persist reservations locally; the API is the source of truth.

Stripe files remain in the project for a future payment phase, but the current flow does not collect payment.

For production, point the web server document root to `public/`.
