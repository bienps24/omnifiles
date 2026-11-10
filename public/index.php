<?php
/**
 * Health check: https://files.omnigate.info/
 */
require dirname(__DIR__) . '/src/App.php';

header('Content-Type: application/json');
try {
    Db::val('SELECT 1');
    echo json_encode(['ok' => true, 'app' => 'OmniFiles', 'version' => App::VERSION]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'database']);
}
