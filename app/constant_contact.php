<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function constant_contact_sync_reservation(array $payload): array
{
    $settings = constant_contact_settings((string) ($payload['parking']['lot_key'] ?? 'lot-a'));
    $token = $settings['access_token'];
    $listIds = $settings['list_ids'];

    if ($token === '' || $listIds === []) {
        return constant_contact_log([
            'ok' => false,
            'skipped' => true,
            'status' => 0,
            'message' => 'Constant Contact is not configured.',
            'list_ids' => $listIds,
            'settings_source' => $settings['source'],
        ], $payload);
    }

    if ($settings['refresh'] !== []) {
        $refreshedToken = constant_contact_refresh_access_token($settings['refresh']);

        if ($refreshedToken !== '') {
            $token = $refreshedToken;
        }
    }

    $results = [];

    foreach ($listIds as $listId) {
        $results[] = constant_contact_create_contact($token, $payload, $listId);
    }

    $ok = $results !== [] && array_reduce($results, static function (bool $carry, array $result): bool {
        return $carry && $result['ok'];
    }, true);
    $statuses = array_map(static function (array $result): int {
        return $result['status'];
    }, $results);
    $messages = array_filter(array_map(static function (array $result): string {
        return $result['message'];
    }, $results));

    return constant_contact_log([
        'ok' => $ok,
        'skipped' => false,
        'status' => $statuses !== [] ? max($statuses) : 0,
        'message' => $messages !== [] ? implode(' | ', $messages) : '',
        'response' => json_encode($results),
        'list_ids' => $listIds,
        'settings_source' => $settings['source'],
    ], $payload);
}

function constant_contact_create_contact(string $token, array $payload, string $listId): array
{
    return constant_contact_post_contact($token, constant_contact_payload($payload, $listId), $listId);
}

function constant_contact_post_contact(string $token, array $requestPayload, string $listId): array
{
    $ch = curl_init('https://api.cc.email/v3/contacts/sign_up_form');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($requestPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $decoded = is_string($response) ? json_decode($response, true) : null;
    $ok = $status >= 200 && $status < 300;

    return [
        'ok' => $ok,
        'status' => $status,
        'message' => is_array($decoded) ? (string) ($decoded['action'] ?? $decoded['message'] ?? '') : $error,
        'response' => is_string($response) ? mb_substr($response, 0, 1000) : null,
        'list_id' => $listId,
    ];
}

function constant_contact_payload(array $payload, string $listId): array
{
    return [
        'email_address' => (string) $payload['customer']['email'],
        'first_name' => (string) $payload['customer']['first_name'],
        'last_name' => (string) $payload['customer']['last_name'],
        'phone_number' => (string) $payload['customer']['phone'],
        'company_name' => (string) ($payload['customer']['source'] ?: 'Landing Page'),
        'create_source' => config('CONSTANT_CONTACT_CREATE_SOURCE', 'Account'),
        'list_memberships' => [
            $listId,
        ],
    ];
}

function constant_contact_list_ids(string $lotKey): array
{
    $generalListId = config('CONSTANT_CONTACT_GENERAL_LIST_ID', config('CONSTANT_CONTACT_LIST_IDS_RESERVATION', config('CONSTANT_CONTACT_LIST_IDS', '')));
    $cruiseListId = config('CONSTANT_CONTACT_CRUISE_LIST_ID', config('CONSTANT_CONTACT_LIST_IDS_CRUISE', ''));
    $ids = constant_contact_split_ids($generalListId);

    if ($lotKey === 'cruise') {
        $ids = array_merge($ids, constant_contact_split_ids($cruiseListId));
    }

    return array_values(array_unique(array_filter($ids)));
}

function constant_contact_split_ids(string $raw): array
{
    $ids = array_map('trim', explode(',', $raw));

    return array_values(array_filter($ids, static function (string $id): bool {
        return $id !== '';
    }));
}

function constant_contact_settings(string $lotKey): array
{
    $envToken = constant_contact_env_access_token($lotKey);
    $envListIds = constant_contact_list_ids($lotKey);

    return [
        'access_token' => $envToken,
        'list_ids' => $envListIds,
        'source' => 'env',
        'refresh' => constant_contact_refresh_credentials($lotKey),
    ];
}

function constant_contact_env_access_token(string $lotKey): string
{
    $scoped = $lotKey === 'cruise'
        ? config('CONSTANT_CONTACT_ACCESS_TOKEN_CRUISE', '')
        : config('CONSTANT_CONTACT_ACCESS_TOKEN_LOTS', '');

    return $scoped !== '' ? $scoped : config('CONSTANT_CONTACT_ACCESS_TOKEN', '');
}

function constant_contact_refresh_credentials(string $lotKey): array
{
    $suffix = $lotKey === 'cruise' ? '_CRUISE' : '_LOTS';
    $refreshToken = config('CONSTANT_CONTACT_REFRESH_TOKEN' . $suffix, config('CONSTANT_CONTACT_REFRESH_TOKEN', ''));
    $clientId = config('CONSTANT_CONTACT_CLIENT_ID' . $suffix, config('CONSTANT_CONTACT_CLIENT_ID', ''));
    $clientSecret = config('CONSTANT_CONTACT_CLIENT_SECRET' . $suffix, config('CONSTANT_CONTACT_CLIENT_SECRET', ''));

    if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
        return [];
    }

    return [
        'refresh_token' => $refreshToken,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ];
}

function constant_contact_refresh_access_token(array $credentials): string
{
    $url = 'https://authz.constantcontact.com/oauth2/default/v1/token?refresh_token='
        . rawurlencode((string) $credentials['refresh_token'])
        . '&grant_type=refresh_token';
    $auth = base64_encode((string) $credentials['client_id'] . ':' . (string) $credentials['client_secret']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = is_string($response) ? json_decode($response, true) : null;

    if ($status >= 200 && $status < 300 && is_array($decoded)) {
        return (string) ($decoded['access_token'] ?? '');
    }

    return '';
}

function constant_contact_log(array $result, array $payload): array
{
    $log = [
        'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'reservation_id' => $payload['reservation_id'] ?? null,
        'confirmation_number' => $payload['confirmation_number'] ?? null,
        'email' => $payload['customer']['email'] ?? null,
        'lot_key' => $payload['parking']['lot_key'] ?? null,
        'ok' => $result['ok'],
        'skipped' => $result['skipped'],
        'status' => $result['status'],
        'message' => $result['message'],
        'list_ids' => $result['list_ids'],
        'settings_source' => $result['settings_source'] ?? null,
    ];

    if (isset($result['response'])) {
        $log['response'] = $result['response'];
    }

    file_put_contents(
        dirname(__DIR__) . '/storage/logs/constant-contact.log',
        json_encode($log, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND
    );

    return $result;
}
