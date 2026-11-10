<?php
namespace H;

use App;
use Crypto;
use Ctx;
use Db;
use Router;
use Tg;
use Ui;

/**
 * Clone Bots: users get their own file bot, run by this same code.
 * Two ways: Telegram managed bots (one tap, "Create my bot") or a BotFather token ("I have a token").
 */
final class Clones
{
    private static function myBots(Ctx $c): array
    {
        return Db::all('SELECT * FROM bots WHERE owner_id = ? ORDER BY created_at', [$c->uid]);
    }

    /** How many clones this user may have: 1 free + bought slots. */
    public static function allowed(int $userId): int
    {
        return 1 + (int) Ctx::account($userId)['extra_bot_slots'];
    }

    public static function home(Ctx $c): void
    {
        if (!$c->isMain) {
            return;
        }
        $c->setState(null);
        $caption = Ui::title($c, 'clone', '🤖', $c->t('t_clone_bots')) . "\n\n"
            . Ui::quote(
                $c->t('clone_intro') . "\n\n"
                . Ui::num(1) . ' ' . $c->t('clone_step1') . "\n"
                . Ui::num(2) . ' ' . $c->t('clone_step2') . "\n"
                . Ui::num(3) . ' ' . $c->t('clone_step3') . "\n\n"
                . $c->t('clone_have_token')
            );
        $rows = [];
        foreach (self::myBots($c) as $b) {
            $icon = $b['status'] === 'active' ? '🟢' : ($b['status'] === 'paused' ? '⏸' : '🚫');
            $rows[] = [Ui::btn($c, $icon . ' @' . $b['username'], 'cv:' . $b['id'])];
        }
        $rows[] = [Ui::btn($c, '➕ ' . $c->t('btn_add_clone'), 'ca', 'success', 'add')];
        Ui::show($c, 'clone_bots', $caption, $rows, $c->callback === null);
    }

    public static function add(Ctx $c): void
    {
        if (count(self::myBots($c)) >= self::allowed($c->uid)) {
            self::limitScreen($c);
            return;
        }
        if (!Ctx::account($c->uid)['rules_accepted']) {
            self::rules($c);
            return;
        }
        self::createScreen($c);
    }

    private static function rules(Ctx $c): void
    {
        $channel = App::newsChannel();
        $caption = Ui::title($c, 'rules', '📋', $c->t('t_before_start')) . "\n\n"
            . Ui::quote('• ' . $c->t('rule1') . "\n• " . $c->t('rule2') . "\n• " . $c->t('rule3')) . "\n"
            . Ui::num(1) . ' ' . $c->t('rules_join') . "\n"
            . Ui::num(2) . ' ' . $c->t('rules_agree');
        Ui::show($c, 'clone_rules', $caption, [
            [Ui::urlBtn($c, '📢 ' . $c->t('btn_join_channel'), 'https://t.me/' . ltrim($channel, '@'), 'primary', 'ad')],
            [Ui::btn($c, '✅ ' . $c->t('btn_agree'), 'cra', 'success', 'done')],
            [Ui::back($c, 'cb')],
        ]);
    }

    public static function agree(Ctx $c): void
    {
        $res = $c->tg->call('getChatMember', ['chat_id' => App::newsChannel(), 'user_id' => $c->uid]);
        if ($res['ok']) {
            $status = $res['result']['status'] ?? 'left';
            if (!in_array($status, ['creator', 'administrator', 'member', 'restricted'], true)) {
                $c->answer($c->t('join_first'), true);
                return;
            }
        } else {
            // The bot must be an admin of the channel to check; don't block people if it isn't
            App::log('cannot check channel membership: ' . ($res['description'] ?? ''));
        }
        Db::q('UPDATE accounts SET rules_accepted = TRUE WHERE user_id = ?', [$c->uid]);
        self::createScreen($c);
    }

    private static function suggestedUsername(Ctx $c): string
    {
        $base = strtolower((string) ($c->user['username'] ?: 'user' . $c->uid));
        $base = preg_replace('/[^a-z0-9_]/', '', $base);
        if ($base === '' || !preg_match('/^[a-z]/', $base)) {
            $base = 'u' . $base;
        }
        return substr($base, 0, 22) . '_files_bot';
    }

    private static function createScreen(Ctx $c): void
    {
        $name = mb_substr(($c->user['first_name'] ?: 'My') . "'s " . $c->t('clone_name_suffix'), 0, 60);
        $url = 'https://t.me/newbot/' . $c->bot['username'] . '/' . self::suggestedUsername($c) . '?name=' . rawurlencode($name);
        $caption = Ui::title($c, 'add', '➕', $c->t('t_create_bot')) . "\n\n"
            . Ui::quote(
                Ui::num(1) . ' ' . $c->t('create_step1') . "\n"
                . Ui::num(2) . ' ' . $c->t('create_step2') . "\n"
                . Ui::num(3) . ' ' . $c->t('create_step3') . "\n\n"
                . $c->t('clone_have_token_short')
            );
        Ui::show($c, 'create_bot', $caption, [
            [Ui::urlBtn($c, '⚡ ' . $c->t('btn_create_my_bot'), $url, 'success', 'bolt')],
            [Ui::btn($c, '🔑 ' . $c->t('btn_have_token'), 'ct', null, 'password')],
            [Ui::back($c, 'cb')],
        ]);
    }

    private static function limitScreen(Ctx $c): void
    {
        $caption = Ui::title($c, 'clone', '🤖', $c->t('t_bot_limit')) . "\n\n"
            . Ui::quote($c->t('bot_limit_text', ['n' => self::allowed($c->uid)])) . "\n\n"
            . '⭐ <b>' . $c->t('bot_limit_more') . "</b>\n"
            . '• ' . $c->t('bot_limit_price', ['n' => App::slotPrice()]) . "\n"
            . '• ' . $c->t('bot_limit_pro') . "\n"
            . '• ' . $c->t('bot_limit_again');
        Ui::show($c, 'bot_limit', $caption, [
            [Ui::btn($c, '⭐ ' . $c->t('btn_buy_slot', ['n' => App::slotPrice()]), 'buy:slot', 'success', 'star')],
            [Ui::urlBtn($c, '🎧 ' . $c->t('btn_contact'), App::contactUrl(), null, 'contact')],
            [Ui::back($c, 'cb')],
        ]);
    }

    public static function tokenPrompt(Ctx $c, string $error = ''): void
    {
        if (!Ctx::account($c->uid)['rules_accepted']) {
            self::rules($c);
            return;
        }
        $c->setState('token');
        $caption = ($error !== '' ? '⚠️ ' . $error . "\n\n" : '')
            . Ui::title($c, 'password', '🔑', $c->t('t_connect_bot')) . "\n\n"
            . Ui::quote(
                Ui::num(1) . ' ' . $c->t('token_step1') . "\n"
                . Ui::num(2) . ' ' . $c->t('token_step2') . "\n"
                . Ui::num(3) . ' ' . $c->t('token_step3') . "\n\n"
                . '💡 ' . $c->t('token_deleted_note')
            );
        Ui::show($c, 'connect_token', $caption, [
            [Ui::urlBtn($c, '🤖 ' . $c->t('btn_open_botfather'), 'https://t.me/BotFather', 'primary')],
            [Ui::btn($c, '✖️ ' . $c->t('btn_cancel'), 'cn', null, 'cancel')],
        ], $error !== '');
    }

    public static function tokenText(Ctx $c, string $text): bool
    {
        $c->tg->call('deleteMessage', ['chat_id' => $c->chatId, 'message_id' => $c->message['message_id']]);
        $token = trim($text);
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token)) {
            self::tokenPrompt($c, $c->t('token_bad'));
            return true;
        }
        $me = (new Tg($token))->get('getMe');
        if (!is_array($me) || empty($me['is_bot'])) {
            self::tokenPrompt($c, $c->t('token_bad'));
            return true;
        }
        $existing = Db::one('SELECT * FROM bots WHERE id = ?', [(int) $me['id']]);
        if ($existing && ((int) $existing['owner_id'] !== $c->uid || $existing['is_main'])) {
            self::tokenPrompt($c, $c->t('token_taken'));
            return true;
        }
        if (!$existing && count(self::myBots($c)) >= self::allowed($c->uid)) {
            $c->setState(null);
            self::limitScreen($c);
            return true;
        }
        $c->setState(null);
        $bot = self::register($token, $me, $c->uid);
        if ($bot === null) {
            self::tokenPrompt($c, $c->t('token_setup_failed'));
            return true;
        }
        self::readyScreen($c, $bot, true);
        return true;
    }

    /** Update from Telegram: a managed bot was created (or its token changed). */
    public static function onManagedBot(array $mainBot, array $upd): void
    {
        $ownerId = (int) $upd['user']['id'];
        $botUser = $upd['bot'];
        $main = new Tg((string) Crypto::decrypt($mainBot['token_enc']));
        $token = $main->get('getManagedBotToken', ['user_id' => (int) $botUser['id']]);
        if (!is_string($token) || $token === '') {
            App::log('getManagedBotToken failed for ' . $botUser['id']);
            return;
        }

        $c = new Ctx($mainBot, []);
        $c->chatId = $ownerId;
        $c->loadUser($upd['user']);

        $existing = Db::one('SELECT * FROM bots WHERE id = ?', [(int) $botUser['id']]);
        if (!$existing && count(self::myBots($c)) >= self::allowed($ownerId)) {
            self::limitScreen($c);
            return;
        }
        $bot = self::register($token, $botUser, $ownerId);
        if ($bot !== null && !$existing) {
            self::readyScreen($c, $bot, true);
        }
    }

    /** Save the bot, point its webhook here and set its profile. */
    public static function register(string $token, array $me, int $ownerId): ?array
    {
        $secret = bin2hex(random_bytes(16));
        $tg = new Tg($token);
        $res = $tg->call('setWebhook', [
            'url' => App::publicUrl() . '/webhook.php?bot=' . (int) $me['id'],
            'secret_token' => $secret,
            'allowed_updates' => Router::ALLOWED_UPDATES,
            'drop_pending_updates' => true,
        ]);
        if (!$res['ok']) {
            return null;
        }
        Db::q(
            'INSERT INTO bots (id, username, name, token_enc, owner_id, is_main, webhook_secret, status)
             VALUES (?, ?, ?, ?, ?, FALSE, ?, \'active\')
             ON CONFLICT (id) DO UPDATE SET username = EXCLUDED.username, name = EXCLUDED.name, token_enc = EXCLUDED.token_enc, webhook_secret = EXCLUDED.webhook_secret',
            [(int) $me['id'], (string) ($me['username'] ?? ''), (string) ($me['first_name'] ?? ''), Crypto::encrypt($token), $ownerId, $secret]
        );
        $tg->call('setMyCommands', ['commands' => [
            ['command' => 'start', 'description' => 'Start'],
            ['command' => 'links', 'description' => 'My links'],
            ['command' => 'many', 'description' => 'Many files, one link'],
            ['command' => 'settings', 'description' => 'Default settings'],
            ['command' => 'help', 'description' => 'Help'],
        ]]);
        $tg->call('setMyDescription', ['description' => "Send any file, get a share link in seconds.\n\nPowered by @OmniFilesApp"]);
        $tg->call('setMyShortDescription', ['short_description' => 'Send any file, get a share link in seconds. Powered by @OmniFilesApp']);
        return Db::one('SELECT * FROM bots WHERE id = ?', [(int) $me['id']]);
    }

    private static function readyScreen(Ctx $c, array $bot, bool $forceNew): void
    {
        $caption = Ui::title($c, 'party', '🎉', $c->t('t_bot_ready')) . "\n\n"
            . Ui::quote(
                $c->t('bot_ready_yours', ['bot' => '@' . Ui::esc($bot['username'])]) . "\n"
                . Ui::num(1) . ' ' . $c->t('bot_ready_step1') . "\n"
                . Ui::num(2) . ' ' . $c->t('bot_ready_step2', ['bot' => '@' . Ui::esc($bot['username'])]) . "\n\n"
                . '💡 ' . $c->t('bot_ready_same')
            );
        $url = 'https://t.me/' . $bot['username'];
        Ui::show($c, 'bot_ready', $caption, [
            [Ui::urlBtn($c, '✈️ ' . $c->t('btn_open_bot', ['bot' => '@' . $bot['username']]), $url, 'success')],
            [
                Ui::urlBtn($c, '📤 ' . $c->t('btn_share_bot'), Ui::shareUrl($url), null, 'share'),
                Ui::btn($c, '⚙️ ' . $c->t('btn_bot_settings'), 'cv:' . $bot['id'], null, 'settings'),
            ],
        ], $forceNew);
    }

    private static function mine(Ctx $c, int $botId): ?array
    {
        return Db::one('SELECT * FROM bots WHERE id = ? AND owner_id = ?', [$botId, $c->uid]);
    }

    public static function view(Ctx $c, int $botId): void
    {
        $bot = self::mine($c, $botId);
        if (!$bot) {
            return;
        }
        $status = match ($bot['status']) {
            'active' => '🟢 ' . $c->t('bot_working'),
            'paused' => '⏸ ' . $c->t('bot_paused_status'),
            default => '🚫 ' . $c->t('bot_removed_status'),
        };
        $users = (int) Db::val('SELECT COUNT(*) FROM users WHERE bot_id = ?', [$botId]);
        $links = (int) Db::val('SELECT COUNT(*) FROM links WHERE bot_id = ?', [$botId]);
        $caption = Ui::title($c, 'clone', '🤖', $c->t('t_your_bot')) . "\n\n"
            . Ui::quote('@' . Ui::esc($bot['username']) . ' · ' . Ui::esc($bot['name']) . "\n" . $status . "\n"
                . '👥 ' . $c->t('n_users', ['n' => $users]) . ' · 🔗 ' . $c->t('n_links', ['n' => $links]));
        $url = 'https://t.me/' . $bot['username'];
        $rows = [
            [Ui::urlBtn($c, '✈️ ' . $c->t('btn_open_bot', ['bot' => '@' . $bot['username']]), $url, 'primary')],
            [Ui::urlBtn($c, '📤 ' . $c->t('btn_share_bot'), Ui::shareUrl($url), null, 'share')],
        ];
        if ($bot['status'] !== 'banned') {
            $rows[] = [
                Ui::btn($c, $bot['status'] === 'paused' ? '▶️ ' . $c->t('btn_resume') : '⏸ ' . $c->t('btn_pause'), 'cp:' . $botId, $bot['status'] === 'paused' ? 'success' : null, 'pause'),
                Ui::btn($c, '🗑 ' . $c->t('btn_delete'), 'cd:' . $botId, 'danger', 'delete'),
            ];
        } else {
            $rows[] = [Ui::btn($c, '🗑 ' . $c->t('btn_delete'), 'cd:' . $botId, 'danger', 'delete')];
        }
        $rows[] = [Ui::back($c, 'cb')];
        Ui::show($c, 'your_bot', $caption, $rows);
    }

    public static function pause(Ctx $c, int $botId): void
    {
        Db::q(
            "UPDATE bots SET status = CASE WHEN status = 'active' THEN 'paused' ELSE 'active' END WHERE id = ? AND owner_id = ? AND status <> 'banned'",
            [$botId, $c->uid]
        );
        self::view($c, $botId);
    }

    public static function deleteAsk(Ctx $c, int $botId): void
    {
        $bot = self::mine($c, $botId);
        if (!$bot) {
            return;
        }
        $caption = Ui::title($c, 'delete', '🗑', $c->t('t_delete_bot')) . "\n\n"
            . Ui::quote($c->t('delete_bot_text', ['bot' => '@' . Ui::esc($bot['username'])]));
        Ui::show($c, 'deleted', $caption, [
            [Ui::btn($c, '🗑 ' . $c->t('btn_delete_yes'), 'cdy:' . $botId, 'danger', 'delete')],
            [Ui::back($c, 'cv:' . $botId)],
        ]);
    }

    public static function deleteYes(Ctx $c, int $botId): void
    {
        $bot = self::mine($c, $botId);
        if (!$bot) {
            return;
        }
        $token = Crypto::decrypt($bot['token_enc']);
        if ($token) {
            (new Tg($token))->call('deleteWebhook', ['drop_pending_updates' => true]);
        }
        self::purge($botId);
        $c->answer($c->t('deleted'));
        self::home($c);
    }

    /** Remove a clone and everything it stored. */
    public static function purge(int $botId): void
    {
        Db::q('DELETE FROM links WHERE bot_id = ?', [$botId]);
        Db::q('DELETE FROM users WHERE bot_id = ?', [$botId]);
        Db::q('DELETE FROM banners WHERE bot_id = ?', [$botId]);
        Db::q('DELETE FROM pending_files WHERE bot_id = ?', [$botId]);
        Db::q('DELETE FROM deletions WHERE bot_id = ?', [$botId]);
        Db::q('DELETE FROM bots WHERE id = ? AND is_main = FALSE', [$botId]);
    }
}
