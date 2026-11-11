<?php
/**
 * Runs on every web deploy (docker/start.sh):
 *   - database migrations
 *   - registers the main bot (MAIN_BOT_TOKEN) and points its webhook here
 *
 * Usage: php bin/setup.php
 */
require dirname(__DIR__) . '/src/App.php';

Db::migrate();

$token = App::env('MAIN_BOT_TOKEN');
if ($token === null) {
    App::log('MAIN_BOT_TOKEN not set, skipping bot setup');
    exit(0);
}
if (App::publicUrl() === '') {
    App::log('PUBLIC_URL not set, skipping webhook');
    exit(0);
}

$tg = new Tg($token);
$me = $tg->get('getMe');
if (!is_array($me)) {
    App::log('getMe failed, check MAIN_BOT_TOKEN');
    exit(1);
}

$existing = Db::one('SELECT * FROM bots WHERE id = ?', [(int) $me['id']]);
$secret = $existing['webhook_secret'] ?? bin2hex(random_bytes(16));

// Only one main bot
Db::q('UPDATE bots SET is_main = FALSE WHERE is_main AND id <> ?', [(int) $me['id']]);
Db::q(
    'INSERT INTO bots (id, username, name, token_enc, owner_id, is_main, webhook_secret, status)
     VALUES (?, ?, ?, ?, NULL, TRUE, ?, \'active\')
     ON CONFLICT (id) DO UPDATE SET username = EXCLUDED.username, name = EXCLUDED.name,
        token_enc = EXCLUDED.token_enc, is_main = TRUE, owner_id = NULL, status = \'active\'',
    [(int) $me['id'], (string) $me['username'], (string) $me['first_name'], Crypto::encrypt($token), $secret]
);

$res = $tg->call('setWebhook', [
    'url' => App::publicUrl() . '/webhook.php?bot=' . (int) $me['id'],
    'secret_token' => $secret,
    'allowed_updates' => Router::ALLOWED_UPDATES,
    'max_connections' => 40,
]);
App::log('main bot @' . $me['username'] . ' webhook: ' . ($res['ok'] ? 'ok' : ($res['description'] ?? 'failed')));

if (empty($me['can_manage_bots'])) {
    App::log('NOTE: "Create my bot" needs Bot Management Mode on in @BotFather for @' . $me['username']);
}

$commands = [
    'en' => [
        ['command' => 'start', 'description' => 'Start'],
        ['command' => 'links', 'description' => 'My links'],
        ['command' => 'many', 'description' => 'Many files, one link'],
        ['command' => 'clone', 'description' => 'Your own file bot'],
        ['command' => 'settings', 'description' => 'Default settings'],
        ['command' => 'pro', 'description' => 'Pro: no ads'],
        ['command' => 'help', 'description' => 'Help'],
    ],
    'tl' => [
        ['command' => 'start', 'description' => 'Simulan'],
        ['command' => 'links', 'description' => 'Mga link ko'],
        ['command' => 'many', 'description' => 'Maraming file, isang link'],
        ['command' => 'clone', 'description' => 'Sariling file bot'],
        ['command' => 'settings', 'description' => 'Default settings'],
        ['command' => 'pro', 'description' => 'Pro: walang ads'],
        ['command' => 'help', 'description' => 'Tulong'],
    ],
];
$tg->call('setMyCommands', ['commands' => $commands['en']]);
$tg->call('setMyCommands', ['commands' => $commands['tl'], 'language_code' => 'tl']);

// Admin: the same menu plus the admin commands, shown only in the admin's own chat
$admin = App::adminId();
if ($admin) {
    $adminCommands = array_merge($commands['en'], [
        ['command' => 'admin', 'description' => '🛠 Admin panel: stats, ads, reports'],
        ['command' => 'emojis', 'description' => '🛠 Premium emoji: set / still normal'],
        ['command' => 'setemoji', 'description' => '🛠 /setemoji + Premium emoji'],
        ['command' => 'delemoji', 'description' => '🛠 /delemoji 🔗 → normal emoji again'],
        ['command' => 'link', 'description' => '🛠 /link <code>: who uploaded it'],
        ['command' => 'user', 'description' => '🛠 /user <id or @username>: user info'],
        ['command' => 'banlink', 'description' => '🛠 /banlink <code or link>'],
        ['command' => 'unbanlink', 'description' => '🛠 /unbanlink <code or link>'],
        ['command' => 'banuser', 'description' => '🛠 /banuser <user id> (with links and bots)'],
        ['command' => 'unbanuser', 'description' => '🛠 /unbanuser <user id>'],
        ['command' => 'banbot', 'description' => '🛠 /banbot <@clone bot>'],
        ['command' => 'unbanbot', 'description' => '🛠 /unbanbot <@clone bot>'],
    ]);
    $res = $tg->call('setMyCommands', [
        'commands' => $adminCommands,
        'scope' => ['type' => 'chat', 'chat_id' => $admin],
    ]);
    App::log('admin commands: ' . ($res['ok'] ? 'ok' : ($res['description'] ?? 'failed (open the bot once as admin, then redeploy)')));
}
$tg->call('setMyDescription', ['description' => "Send any file, get a share link in seconds. Free and fast ⚡\n\nPhotos, videos, documents — one file or many at once."]);
$tg->call('setMyShortDescription', ['short_description' => 'Send any file, get a share link in seconds. Free and fast ⚡']);
