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
    $payload = ensure_confirmation_number($payload);
    $context = reservation_email_context($payload);
    $adminIntro = '';

    if ($audience === 'admin') {
        $adminIntro = '<table width="600" cellpadding="0" cellspacing="0" border="0" class="container">'
            . '<tr><td width="600" class="mobile" align="left" valign="top" style="padding:0 0 18px;">'
            . '<b>Reservation made on</b> ' . e($context['created_at']) . '<br><br>'
            . 'Client heard about you from ' . e($context['source'])
            . '<br><br></td></tr></table>';
    }

    return reservation_email_template($context, $adminIntro);
}

function reservation_email_text(array $payload, string $audience): string
{
    $title = reservation_email_subject($payload, $audience);
    $lines = [$title, ''];

    foreach (reservation_display_rows($payload) as $label => $value) {
        $lines[] = $label . ': ' . $value;
    }

    return implode("\n", $lines);
}

function reservation_email_subject(array $payload, string $audience): string
{
    $payload = ensure_confirmation_number($payload);
    $context = reservation_email_context($payload);

    if ($audience === 'admin') {
        $prefix = $context['is_cruise'] ? '[CRUISE PARKING] - ' : '';
        return $context['customer_name'] . ': ' . $prefix . 'New Reservation Request: '
            . $context['dropoff_date'] . ' to ' . $context['pickup_date'];
    }

    return 'Your Reservation Details at SD Park Shuttle and Fly '
        . ($context['is_cruise'] ? 'Cruise Parking ' : 'Lot A or B ')
        . $context['first_name'];
}

function reservation_email_context(array $payload): array
{
    $payload = ensure_confirmation_number($payload);
    $isCruise = ((string) $payload['parking']['lot_key']) === 'cruise';
    $rate = money_from_cents((int) $payload['parking']['daily_rate_cents'], (string) $payload['payment']['currency']);
    $total = money_from_cents((int) $payload['payment']['amount_total_cents'], (string) $payload['payment']['currency']);
    $dropoffDate = email_date((string) $payload['parking']['dropoff_date']);
    $pickupDate = email_date((string) $payload['parking']['pickup_date']);

    return [
        'is_cruise' => $isCruise,
        'lot_key' => (string) $payload['parking']['lot_key'],
        'title' => $isCruise ? 'SD Park Shuttle and Fly Cruise Parking' : 'SD Park Shuttle and Fly Lot A or B',
        'headline' => 'NO BARCODE/QR CODE TAKE A TICKET',
        'created_at' => email_created_at((string) ($payload['created_at'] ?? '')),
        'first_name' => (string) $payload['customer']['first_name'],
        'customer_name' => (string) $payload['customer']['full_name'],
        'customer_email' => (string) $payload['customer']['email'],
        'customer_phone' => (string) $payload['customer']['phone'],
        'source' => (string) ($payload['customer']['source'] ?: 'Not provided'),
        'lot_name' => (string) $payload['parking']['lot_name'],
        'lot_address' => nl2br(e(email_address_lines((string) $payload['parking']['lot_address']))),
        'lot_phone' => $isCruise ? '619-291-1234' : email_lot_phone((string) $payload['parking']['lot_key']),
        'dropoff_date' => $dropoffDate,
        'dropoff_time' => email_time((string) $payload['parking']['dropoff_time']),
        'pickup_date' => $pickupDate,
        'pickup_time' => email_time((string) $payload['parking']['pickup_time']),
        'days' => (string) $payload['parking']['days'],
        'rate' => $rate,
        'total' => $total,
        'coupon_url' => (string) config('COUPON_URL', 'https://sdparkshuttlefly.com/coupons/'),
        'reservation_validity' => $isCruise
            ? 'Your reservation is only valid at Lot A for Cruise Ship Patrons.'
            : 'A reservation made for a specific parking facility is good for both LOT A and/or LOT B regardless of which property you have chosen in the initial reservation.',
        'arrival_note' => $isCruise
            ? 'PLEASE ARRIVE AT OUR FACILITY AT LEAST 1-2 HOURS PRIOR TO YOUR DEPARTURE TIME TO ASSURE YOU ARRIVE AT THE CRUISE PORT WITH AMPLE TIME!'
            : 'PLEASE ARRIVE AT OUR FACILITY AT LEAST 2-3 HOURS PRIOR TO YOUR DEPARTURE TIME TO ASSURE YOU ARRIVE AT THE AIRPORT WITH AMPLE TIME!',
        'shuttle_note' => $isCruise
            ? 'Courtesy Shuttles to and from the Cruise Port run 24 hours a day 7 days a week ON DEMAND ONLY! Once requested can take approximately 30 to 45 minutes on average.'
            : 'Courtesy Shuttles to and from the airport run 24 hours a day 7 days a week ON DEMAND ONLY! Once requested can take approximately 15 to 25 minutes on average.',
        'access_fee_label' => $isCruise ? 'Cruise/Port Access Fee' : 'Airport/Port Access Fee',
    ];
}

function reservation_email_template(array $context, string $adminIntro): string
{
    $suggestion = $context['is_cruise']
        ? '<table width="600" cellpadding="0" cellspacing="0" border="0" class="container"><tr><td width="600" class="mobile" align="left" valign="top"><p style="color:#ce363f; margin:20px 0;"><b style="color:#ce363f;">SUGGESTION:</b> Due to the overwhelming demand for Cruise ship parking, it is SUGGESTED that parties of 4 or more drop off ALL their family members and luggage at the Cruise Ship Terminal PRIOR to parking.</p></td></tr></table>'
        : '';

    return '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">'
        . '<html lang="en"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta http-equiv="X-UA-Compatible" content="IE=edge"><title>Email Confirmation</title></head>'
        . '<body style="margin:0; padding:0; background-color:#ffffff;"><center>'
        . '<table width="640" cellpadding="0" cellspacing="0" border="0" class="wrapper" bgcolor="#FFFFFF"><tr><td align="center" valign="top">'
        . $adminIntro
        . '<table width="600" cellpadding="0" cellspacing="0" border="0" class="container"><tr><td width="600" class="mobile" align="left" valign="top">'
        . '<h1 style="color:#ce363f;"><b>' . e($context['headline']) . '</b></h1>'
        . 'Thank you for using our online reservation system. We look forward to serving you! <br />'
        . '<b>With 13+ years in airport parking, we provide reliable and secure solutions.</b> <br /><hr>'
        . '</td></tr></table>'
        . '<table width="600" cellpadding="0" cellspacing="0" border="0" class="container"><tr>'
        . '<td width="300" class="mobile" align="left" valign="top">'
        . '<b style="color:#ce363f;">' . e($context['title']) . '</b><br>'
        . $context['lot_address'] . '<br>Phone: ' . e($context['lot_phone']) . ' <br>'
        . 'Pricing: <b>' . e($context['rate']) . '/24 hours</b> <br>'
        . 'Lock $18.95 Rate By Presenting <a href="' . e($context['coupon_url']) . '">Coupon</a> <br>'
        . '**Restrictions Apply** <br><br><br></td>'
        . '<td height="10" class="mobileOn">&nbsp;</td>'
        . '<td width="300" class="mobile" align="left" valign="top">'
        . '<b>Customer Name:</b> ' . e($context['customer_name']) . '<br>'
        . '<b>Customer Email:</b> ' . e($context['customer_email']) . '<br>'
        . '<b>Customer Phone:</b> ' . e($context['customer_phone']) . '<br>'
        . '<b>Drop Off Information:</b> ' . e($context['dropoff_date'] . ' ' . $context['dropoff_time']) . '<br>'
        . '<b>Pick Up Information:</b> ' . e($context['pickup_date'] . ' ' . $context['pickup_time'])
        . '</td></tr></table>'
        . $suggestion
        . reservation_email_rate_table($context)
        . reservation_email_footer($context)
        . '</td></tr></table></center></body></html>';
}

function reservation_email_rate_table(array $context): string
{
    return '<table width="600" cellpadding="0" cellspacing="0" border="0" class="container table table-bordered">'
        . '<tr><th style="text-align:left;">Your Parking Estimate</th><th style="text-align:left;">Amount</th></tr>'
        . '<tr><td>Service Type</td><td></td></tr>'
        . '<tr><td>Parking - Daily (' . e($context['days']) . ' @ ' . e($context['rate']) . ')</td><td>' . e($context['total']) . '</td></tr>'
        . '<tr><td>' . e($context['access_fee_label']) . '</td><td>Due at exit if applicable</td></tr>'
        . '<tr class="table-active"><td><strong>Total Amount</strong><br>'
        . '<span style="color:#ce363f">(Payment due at Exit - Rate By Presenting Coupon - Regular Rate $24.95)</span>'
        . '</td><td><strong>' . e($context['total']) . '</strong></td></tr>'
        . '</table>';
}

function reservation_email_footer(array $context): string
{
    $directions = reservation_email_driving_directions($context);

    return '<table width="600" cellpadding="0" cellspacing="0" border="0" class="container"><tr>'
        . '<td width="600" class="mobile" align="left" valign="top">'
        . '<p><b>Miscellaneous Information:</b><br>' . reservation_email_arrival_note($context) . '</p>'
        . '<p>' . reservation_email_shuttle_note($context) . '</p>'
        . '<p><b>Be advised that Parking is paid at the end of your trip. Please be prepared to pay for parking at the end of your trip.</b></p>'
        . '<p style="color:#ce363f"><b style="color:#ce363f">' . e($context['reservation_validity']) . '</b></p>'
        . '<p><b>Hours of Operation:</b><br>Open 24 Hours a Day, 7 Days a Week!</p>'
        . '<p><b>Modification Policy:</b><br>Need to advise parking facility 24 hours prior to original reservation.</p>'
        . '<p><b>Refund/Cancellation Policy:</b><br>ALL SALES ARE FINAL! NO REFUNDS! Customer cannot cancel or receive a refund once services have been rendered.</p>'
        . $directions
        . '<p>PLEASE DO NOT REPLY TO THIS EMAIL'
        . '<br>This e-mail serves as your receipt. The original e-mail account is not monitored. This address is automated, unattended, and can not answer your questions or requests.</p>'
        . '<p>Thank You</p>'
        . '</td></tr></table>';
}

function reservation_email_arrival_note(array $context): string
{
    if ($context['is_cruise']) {
        return '<b>NOTE:</b> PLEASE ARRIVE AT OUR FACILITY AT LEAST 1-2 HOURS PRIOR TO YOUR DEPARTURE TIME TO ASSURE YOU ARRIVE AT THE CRUISE PORT WITH AMPLE TIME!';
    }

    return '<b>NOTE: PLEASE ARRIVE AT OUR FACILITY AT LEAST 2-3 HOURS PRIOR TO YOUR DEPARTURE TIME TO ASSURE YOU ARRIVE AT THE AIRPORT WITH AMPLE TIME!</b>';
}

function reservation_email_shuttle_note(array $context): string
{
    if ($context['is_cruise']) {
        return 'Courtesy Shuttles to and from the Cruise Port run 24 hours a day 7 days a week ON DEMAND ONLY! Once requested can take approximately 30 to 45 minutes on average to get picked up due to construction at the Airport. Please call us after you claim your baggage and a courtesy shuttle will be sent for you ON DEMAND.';
    }

    return '<b>Courtesy Shuttles to and from the airport run 24 hours a day 7 days a week ON DEMAND ONLY! Once requested can take approximately 15 to 25 minutes on average to get picked up due to construction at the Airport. Please call us after you claim your baggage and a courtesy shuttle will be sent for you ON DEMAND.</b>';
}

function reservation_email_driving_directions(array $context): string
{
    if ($context['is_cruise'] || $context['lot_key'] === 'lot-a') {
        return '<p><b>Driving Directions:</b><br>'
            . '<b>I-5 Freeway Southbound</b> Exit San Diego Airport/Sassafras Street. Turn right onto Sassafras Street at the first traffic signal light then turn right onto Pacific Highway, once on Pacific Highway our entrance is IMMEDIATELY ON THE LEFT.'
            . '</p><p>'
            . '<b>I-5 Freeway Northbound</b> Exit Sassafras/India Street, Turn left onto Sassafras Street at the first traffic signal light then turn right onto Pacific Highway, once on Pacific Highway our entrance is IMMEDIATELY ON THE LEFT.'
            . '</p><p>'
            . '<b>From SR 163 South</b> take I-5 North/ San Diego Freeway toward Los Angeles. Exit Sassafras/India Street, Turn left onto Sassafras Street at the first traffic signal light then turn right onto Pacific Highway, once on Pacific Highway our entrance is IMMEDIATELY ON THE LEFT.'
            . '</p><p>'
            . '<b>I-8 Freeway Westbound</b> Take ramp right for I-5 South/San Diego Freeway. Exit San Diego Airport/ Sassafras Street, Turn right onto Sassafras Street at the first traffic signal light then turn right onto Pacific Highway, once on Pacific Highway our entrance is IMMEDIATELY ON THE LEFT.'
            . '</p>';
    }

    return '<p><b>Driving Directions:</b><br>'
        . '<b>I-5 Freeway Southbound Exit San Diego Airport/Sassafras Street.</b> Turn right onto Sassafras Street at the first traffic signal light then cross the train tracks and our entrance is IMMEDIATELY ON THE RIGHT before Pacific Highway.'
        . '</p><p>'
        . '<b>I-5 Freeway Northbound</b> - Exit Sassafras/India Street, Turn left onto Sassafras Street at the first traffic signal light then cross the train tracks and our entrance is IMMEDIATELY ON THE RIGHT before Pacific Highway.'
        . '</p><p>'
        . '<b>From SR 163 South - take I-5 North/ San Diego Freeway toward Los Angeles.</b> Exit Sassafras/India Street, Turn left onto Sassafras Street at the first traffic signal light then cross the train tracks and our entrance is IMMEDIATELY ON THE RIGHT before Pacific Highway.'
        . '</p><p>'
        . '<b>I-8 Freeway Westbound - Take ramp for I-5 South/San Diego Freeway. </b> Exit San Diego Airport/ Sassafras Street, Turn right onto Sassafras Street at the first traffic signal light then cross the train tracks and our entrance is IMMEDIATELY ON THE RIGHT before Pacific Highway.'
        . '</p>';
}

function email_date(string $date): string
{
    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $dateTime ? $dateTime->format('m/d/Y') : $date;
}

function email_time(string $time): string
{
    $dateTime = DateTimeImmutable::createFromFormat('!H:i', $time);

    return $dateTime ? $dateTime->format('h:i A') : $time;
}

function email_address_lines(string $address): string
{
    return str_replace(', ', "\n", $address);
}

function email_lot_phone(string $lotKey): string
{
    return $lotKey === 'lot-b' ? '619-297-7275' : '619-291-1234';
}

function email_created_at(string $createdAt): string
{
    if ($createdAt === '') {
        return (new DateTimeImmutable())->format('m/d/Y h:i A');
    }

    try {
        return (new DateTimeImmutable($createdAt))->format('m/d/Y h:i A');
    } catch (Exception $exception) {
        return $createdAt;
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
    $adminSubject = reservation_email_subject($payload, 'admin');
    $customerSubject = reservation_email_subject($payload, 'customer');
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
