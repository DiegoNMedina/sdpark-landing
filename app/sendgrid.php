<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reservation_payload.php';

function sendgrid_is_configured(): bool
{
    return config('SENDGRID_API_KEY', '') !== ''
        && config('MAIL_FROM_EMAIL', '') !== ''
        && config('ADMIN_EMAIL', '') !== '';
}

function reservation_email_html(array $payload, string $audience): string
{
    $rows = reservation_display_rows($payload);
    $title = $audience === 'admin'
        ? 'New SD Park reservation'
        : 'Your SD Park reservation is confirmed';
    $htmlRows = '';

    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr><th align="left" style="padding:8px;border-bottom:1px solid #eee;color:#555;">'
            . htmlspecialchars($label)
            . '</th><td style="padding:8px;border-bottom:1px solid #eee;">'
            . htmlspecialchars((string) $value)
            . '</td></tr>';
    }

    return '<div style="font-family:Arial,sans-serif;color:#232323;line-height:1.5;">'
        . '<h1 style="color:#d40000;">' . htmlspecialchars($title) . '</h1>'
        . '<p>Reservation details are below.</p>'
        . '<table cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">'
        . $htmlRows
        . '</table>'
        . '</div>';
}

function reservation_email_text(array $payload, string $audience): string
{
    $title = $audience === 'admin'
        ? 'New SD Park reservation'
        : 'Your SD Park reservation is confirmed';
    $lines = [$title, ''];

    foreach (reservation_display_rows($payload) as $label => $value) {
        $lines[] = $label . ': ' . $value;
    }

    return implode("\n", $lines);
}

function sendgrid_payload(string $toEmail, string $toName, string $subject, string $html, string $text): array
{
    return [
        'personalizations' => [[
            'to' => [[
                'email' => $toEmail,
                'name' => $toName,
            ]],
        ]],
        'from' => [
            'email' => (string) config('MAIL_FROM_EMAIL', 'no-reply@example.com'),
            'name' => (string) config('MAIL_FROM_NAME', 'SD Park Shuttle & Fly'),
        ],
        'subject' => $subject,
        'content' => [
            [
                'type' => 'text/plain',
                'value' => $text,
            ],
            [
                'type' => 'text/html',
                'value' => $html,
            ],
        ],
    ];
}

function send_sendgrid_email(array $payload): bool
{
    if (!sendgrid_is_configured()) {
        $log = [
            'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'reason' => 'sendgrid_not_configured',
            'to' => $payload['personalizations'][0]['to'][0]['email'] ?? null,
            'subject' => $payload['subject'] ?? null,
        ];
        file_put_contents(dirname(__DIR__) . '/storage/logs/prepared-emails.log', json_encode($log) . "\n", FILE_APPEND);
        return false;
    }

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . config('SENDGRID_API_KEY'),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status >= 200 && $status < 300;
}

function send_reservation_emails(array $payload): array
{
    $payload = ensure_confirmation_number($payload);
    $customerName = $payload['customer']['full_name'];
    $adminSubject = 'New SD Park parking reservation';
    $customerSubject = 'Your SD Park parking reservation is confirmed';
    $messages = [
        'admin' => sendgrid_payload(
            (string) config('ADMIN_EMAIL', ''),
            (string) config('ADMIN_NAME', 'SD Park Admin'),
            $adminSubject,
            reservation_email_html($payload, 'admin'),
            reservation_email_text($payload, 'admin')
        ),
        'customer' => sendgrid_payload(
            $payload['customer']['email'],
            $customerName,
            $customerSubject,
            reservation_email_html($payload, 'customer'),
            reservation_email_text($payload, 'customer')
        ),
    ];

    return [
        'admin' => send_sendgrid_email($messages['admin']),
        'customer' => send_sendgrid_email($messages['customer']),
    ];
}
