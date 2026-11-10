<?php
/**
 * Background worker (Railway service with ROLE=worker). Runs forever, once per second:
 *   1. albums that finished arriving → one link each
 *   2. "Files so far" counters on Many Files / Add files screens
 *   3. auto-delete of delivered files
 */
require dirname(__DIR__) . '/src/App.php';

App::log('worker started');

/** Build a context for a bot + user outside of a webhook. */
function workerCtx(int $botId, int $userId): ?Ctx
{
    $bot = Db::one('SELECT * FROM bots WHERE id = ?', [$botId]);
    if (!$bot) {
        return null;
    }
    $c = new Ctx($bot, []);
    $row = Db::one('SELECT * FROM users WHERE bot_id = ? AND user_id = ?', [$botId, $userId]);
    if (!$row) {
        return null;
    }
    $row['state_data'] = json_decode($row['state_data'] ?? '{}', true) ?: [];
    $row['defaults'] = json_decode($row['defaults'] ?? '{}', true) ?: [];
    $c->uid = $userId;
    $c->user = $row;
    $c->chatId = $userId;
    return $c;
}

function finalizeAlbums(): void
{
    $groups = Db::all(
        "SELECT bot_id, user_id, bucket FROM pending_files
         WHERE bucket LIKE 'mg:%'
         GROUP BY bot_id, user_id, bucket
         HAVING MAX(created_at) < now() - interval '2 seconds'
         LIMIT 50"
    );
    foreach ($groups as $g) {
        $c = workerCtx((int) $g['bot_id'], (int) $g['user_id']);
        if ($c === null) {
            Db::q('DELETE FROM pending_files WHERE bot_id = ? AND user_id = ? AND bucket = ?', [$g['bot_id'], $g['user_id'], $g['bucket']]);
            continue;
        }
        H\Upload::finalizeAlbum($c, $g['bucket']);
    }
}

function refreshCounters(): void
{
    $rows = Db::all(
        "SELECT bot_id, user_id FROM users
         WHERE state IN ('many', 'add') AND state_data->>'dirty' IS NOT NULL AND last_seen < now() - interval '700 milliseconds'
         LIMIT 50"
    );
    foreach ($rows as $r) {
        $c = workerCtx((int) $r['bot_id'], (int) $r['user_id']);
        if ($c !== null) {
            H\Upload::refreshCounter($c);
        }
    }
}

function runDeletions(): void
{
    $rows = Db::all('SELECT * FROM deletions WHERE due_at <= now() ORDER BY due_at LIMIT 100');
    $tokens = [];
    foreach ($rows as $r) {
        Db::q('DELETE FROM deletions WHERE id = ?', [(int) $r['id']]);
        $botId = (int) $r['bot_id'];
        if (!array_key_exists($botId, $tokens)) {
            $bot = Db::one('SELECT token_enc FROM bots WHERE id = ?', [$botId]);
            $tokens[$botId] = $bot ? Crypto::decrypt($bot['token_enc']) : null;
        }
        if (!$tokens[$botId]) {
            continue;
        }
        $ids = array_map('intval', explode(',', trim((string) $r['message_ids'], '{}')));
        foreach (array_chunk($ids, 100) as $chunk) {
            (new Tg($tokens[$botId]))->call('deleteMessages', ['chat_id' => (int) $r['chat_id'], 'message_ids' => $chunk]);
        }
    }
}

function cleanup(): void
{
    // Forgotten Many Files / Add files sessions
    Db::q("DELETE FROM pending_files WHERE bucket NOT LIKE 'mg:%' AND created_at < now() - interval '1 day'");
}

$lastCleanup = 0;
while (true) {
    try {
        finalizeAlbums();
        refreshCounters();
        runDeletions();
        if (time() - $lastCleanup > 3600) {
            cleanup();
            $lastCleanup = time();
        }
    } catch (Throwable $e) {
        App::log('worker error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        Db::reset();
        sleep(3);
    }
    usleep(1000000);
}
