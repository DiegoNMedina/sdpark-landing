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

## Stripe Setup

Install dependencies:

```bash
composer install
```

Create a local `.env` from `.env.example` and set:

```txt
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
APP_URL=http://127.0.0.1:8087
```

Checkout starts at:

```txt
/api/create-checkout-session.php
```

Webhook endpoint:

```txt
/api/stripe-webhook.php
```

For production, point the web server document root to `public/`.
