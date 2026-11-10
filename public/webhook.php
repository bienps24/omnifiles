<?php
/**
 * Telegram webhook for the main bot and every clone: /webhook.php?bot=<bot id>
 */
require dirname(__DIR__) . '/src/App.php';

$botId = (int) ($_GET['bot'] ?? 0);
$bot = $botId ? Db::one('SELECT * FROM bots WHERE id = ?', [$botId]) : null;
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if ($bot === null || !hash_equals($bot['webhook_secret'], $secret)) {
    http_response_code(403);
    exit;
}

$update = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($update)) {
    http_response_code(400);
    exit;
}

// Answer Telegram right away, then keep working (so slow uploads never cause retries)
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: 2');
echo '{}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}
ignore_user_abort(true);
set_time_limit(120);

try {
    Router::handle($bot, $update);
} catch (Throwable $e) {
    App::log('update ' . ($update['update_id'] ?? '?') . ' failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
