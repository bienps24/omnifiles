<?php
/**
 * Payment events from the payment bot (@omnigatepaybot).
 *
 * POST JSON, header X-Signature: hex HMAC-SHA256 of the raw body with PAY_SECRET.
 *   {"event": "paid",     "user_id": 123, "product": "pro"|"slot", "stars": 300, "charge_id": "..."}
 *   {"event": "refunded", "charge_id": "..."}
 *
 * Answers {"ok": true, "applied": bool}. Sending the same event twice is safe.
 */
require dirname(__DIR__) . '/src/App.php';

header('Content-Type: application/json');

function reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

$secret = App::env('PAY_SECRET');
if ($secret === null || strlen($secret) < 16) {
    reply(500, ['ok' => false, 'error' => 'PAY_SECRET not set']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'POST only']);
}

$raw = (string) file_get_contents('php://input');
$given = strtolower((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
if (!hash_equals(hash_hmac('sha256', $raw, $secret), $given)) {
    reply(403, ['ok' => false, 'error' => 'bad signature']);
}

$event = json_decode($raw, true);
if (!is_array($event) || empty($event['charge_id'])) {
    reply(400, ['ok' => false, 'error' => 'bad body']);
}

try {
    if (($event['event'] ?? '') === 'paid') {
        $product = (string) ($event['product'] ?? '');
        $stars = (int) ($event['stars'] ?? 0);
        if (!in_array($product, H\Pay::PRODUCTS, true) || (int) ($event['user_id'] ?? 0) <= 0) {
            reply(400, ['ok' => false, 'error' => 'bad product or user']);
        }
        if ($stars < H\Pay::price($product)) {
            App::log("payment {$event['charge_id']}: $stars Stars is below the $product price");
            reply(400, ['ok' => false, 'error' => 'amount below price']);
        }
        $applied = H\Pay::grant((int) $event['user_id'], $product, $stars, (string) $event['charge_id']);
        reply(200, ['ok' => true, 'applied' => $applied]);
    }
    if (($event['event'] ?? '') === 'refunded') {
        $applied = H\Pay::refund((string) $event['charge_id']);
        reply(200, ['ok' => true, 'applied' => $applied]);
    }
    reply(400, ['ok' => false, 'error' => 'unknown event']);
} catch (Throwable $e) {
    App::log('pay.php failed: ' . $e->getMessage());
    reply(500, ['ok' => false, 'error' => 'server error']);
}
