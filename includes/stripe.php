<?php
// Stripe API credentials - TEST keys from dashboard.stripe.com.
// This file is ignored by git in this repo, so local test keys can stay local.
if (file_exists(__DIR__ . '/stripe.local.php')) {
    require_once __DIR__ . '/stripe.local.php';
}

if (!defined('STRIPE_SECRET_KEY')) {
    define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');
}
if (!defined('STRIPE_PUBLISHABLE_KEY')) {
    define('STRIPE_PUBLISHABLE_KEY', getenv('STRIPE_PUBLISHABLE_KEY') ?: '');
}
if (!defined('STRIPE_CURRENCY')) {
    define('STRIPE_CURRENCY', getenv('STRIPE_CURRENCY') ?: 'usd');
}

function stripeIsConfigured(): bool {
    return STRIPE_SECRET_KEY !== '' && STRIPE_PUBLISHABLE_KEY !== '';
}

function stripeIsTestMode(): bool {
    return str_starts_with(STRIPE_SECRET_KEY, 'sk_test_');
}

// Flatten nested array into Stripe's bracket notation: transfer_data[destination]=acct_xxx
function _stripeEncodeParams(array $params, string $prefix = ''): string {
    $parts = [];
    foreach ($params as $key => $value) {
        $fullKey = $prefix !== '' ? "{$prefix}[{$key}]" : $key;
        if (is_array($value)) {
            $parts[] = _stripeEncodeParams($value, $fullKey);
        } else {
            $parts[] = urlencode($fullKey) . '=' . urlencode((string)$value);
        }
    }
    return implode('&', $parts);
}

// Send a cURL request to the Stripe API.
function stripeRequest(string $method, string $endpoint, array $params = [], array $extraHeaders = []): array {
    if (!stripeIsConfigured()) {
        return ['error' => ['message' => 'Stripe is not configured']];
    }

    $url     = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');
    $headers = array_merge(['Content-Type: application/x-www-form-urlencoded'], $extraHeaders);
    $ch      = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, _stripeEncodeParams($params));
    }
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => ['message' => 'cURL error: ' . $err]];
    return json_decode($body, true) ?? ['error' => ['message' => 'Invalid Stripe response']];
}

// Create a PaymentIntent for the given amount (in USD cents).
function stripeCreateIntent(int $amountCents, array $metadata = [], string $idempotencyKey = ''): array {
    $params = [
        'amount'   => $amountCents,
        'currency' => STRIPE_CURRENCY,
        'metadata' => $metadata,
        'automatic_payment_methods' => ['enabled' => 'true'],
    ];
    $extraHeaders = $idempotencyKey !== ''
        ? ['Idempotency-Key: ' . $idempotencyKey]
        : [];
    return stripeRequest('POST', 'payment_intents', $params, $extraHeaders);
}

// Retrieve a PaymentIntent by ID - used server-side to verify status.
function stripeRetrieveIntent(string $intentId): array {
    return stripeRequest('GET', 'payment_intents/' . urlencode($intentId));
}

// Create a PaymentIntent that transfers funds to an organizer's connected account.
// Platform fee is taken from the transfer (5% of amount).
function stripeCreateConnectIntent(int $amountCents, string $connectedAccountId, array $metadata = [], string $idempotencyKey = ''): array {
    $platformFeeCents = (int)round($amountCents * 0.05);
    $params = [
        'amount'                    => $amountCents,
        'currency'                  => STRIPE_CURRENCY,
        'metadata'                  => $metadata,
        'automatic_payment_methods' => ['enabled' => 'true'],
        'transfer_data'             => ['destination' => $connectedAccountId],
        'application_fee_amount'    => $platformFeeCents,
    ];
    $extraHeaders = $idempotencyKey !== '' ? ['Idempotency-Key: ' . $idempotencyKey] : [];
    return stripeRequest('POST', 'payment_intents', $params, $extraHeaders);
}

// Create a Stripe Express Connect account for an organizer.
// Express: Stripe hosts the onboarding UI and the organizer enters their own details.
function stripeCreateConnectAccount(string $email): array {
    return stripeRequest('POST', 'accounts', [
        'type'             => 'express',
        'country'          => 'US',
        'default_currency' => 'usd',
        'email'            => $email,
        'capabilities'     => [
            'card_payments' => ['requested' => 'true'],
            'transfers'     => ['requested' => 'true'],
        ],
    ]);
}

// Create an Account Link for the organizer to complete Stripe onboarding.
function stripeCreateAccountLink(string $accountId, string $returnUrl, string $refreshUrl): array {
    return stripeRequest('POST', 'account_links', [
        'account'     => $accountId,
        'return_url'  => $returnUrl,
        'refresh_url' => $refreshUrl,
        'type'        => 'account_onboarding',
    ]);
}

// Retrieve a Connect account to check onboarding status.
function stripeRetrieveAccount(string $accountId): array {
    return stripeRequest('GET', 'accounts/' . urlencode($accountId));
}

// Create a sandbox-only Custom connected account with test KYC and payout details.
// This is for local/demo use to reduce Stripe Connect onboarding friction.
function stripeCreateFastTestConnectAccount(string $email, string $displayName, string $acceptIp = '127.0.0.1'): array {
    $displayName = trim($displayName) !== '' ? trim($displayName) : 'Test Organizer';
    $nameParts = preg_split('/\s+/', $displayName, 2);
    $firstName = $nameParts[0] ?? 'Test';
    $lastName = $nameParts[1] ?? 'Organizer';

    return stripeRequest('POST', 'accounts', [
        'type' => 'custom',
        'country' => 'US',
        'email' => $email,
        'business_type' => 'individual',
        'capabilities' => [
            'card_payments' => ['requested' => 'true'],
            'transfers' => ['requested' => 'true'],
        ],
        'tos_acceptance' => [
            'date' => (string)time(),
            'ip' => $acceptIp !== '' ? $acceptIp : '127.0.0.1',
        ],
        'business_profile' => [
            'mcc' => '8398',
            'product_description' => 'Fundraising campaigns on UnityFund',
        ],
        'individual' => [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => '8888675309',
            'dob' => [
                'day' => '01',
                'month' => '01',
                'year' => '1902',
            ],
            'id_number' => '222222222',
            'address' => [
                'line1' => 'address_full_match',
                'city' => 'Schenectady',
                'state' => 'NY',
                'postal_code' => '12345',
            ],
            'verification' => [
                'document' => [
                    'front' => 'file_identity_document_success',
                ],
            ],
        ],
        'external_account' => [
            'object' => 'bank_account',
            'country' => 'US',
            'currency' => 'usd',
            'routing_number' => '110000000',
            'account_number' => '000999999991',
        ],
        'metadata' => [
            'unityfund_fasttrack' => '1',
        ],
    ]);
}
