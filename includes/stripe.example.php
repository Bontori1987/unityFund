<?php
// Copy this file to stripe.php and fill in your Stripe TEST keys.
// Get them at: https://dashboard.stripe.com/test/apikeys
// NEVER use live keys in development.
define('STRIPE_SECRET_KEY',      'sk_test_REPLACE_ME');
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_REPLACE_ME');
define('STRIPE_CURRENCY',        'usd');

function stripeRequest(string $method, string $endpoint, array $params = []): array {
    $url = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => ['message' => 'cURL error: ' . $err]];
    return json_decode($body, true) ?? ['error' => ['message' => 'Invalid Stripe response']];
}

function stripeCreateIntent(int $amountCents, array $metadata = []): array {
    return stripeRequest('POST', 'payment_intents', [
        'amount'   => $amountCents,
        'currency' => STRIPE_CURRENCY,
        'metadata' => $metadata,
        'automatic_payment_methods' => ['enabled' => 'true'],
    ]);
}

function stripeRetrieveIntent(string $intentId): array {
    return stripeRequest('GET', 'payment_intents/' . urlencode($intentId));
}
