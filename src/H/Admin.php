<?php
namespace H;

use App;
use Crypto;
use Ctx;
use Db;
use Tg;
use Ui;

/**
 * Admin panel. On the main bot: the OmniFiles admin (ADMIN_ID). On a clone: its owner sees that bot's numbers.
 *
 * Commands (main bot, admin only):
 *   /admin                  panel
 *   /banlink <code|url>     /unbanlink <code|url>
 *   /banuser <user id>      /unbanuser <user id>
 *   /banbot <@username|id>  /unbanbot <@username|id>
 *   /emojis                 every emoji the bot uses: Premium or still normal
 *   /setemoji 🔗📁✅         (Premium emoji) each replaces the normal emoji it is based on
 *   /setemoji 🗂 <Premium>   the Premium emoji replaces 🗂
 *   /delemoji 🔗             back to the normal emoji
 *   send a message with custom emoji → the bot replies with their ids
 */
final class Admin
{
    private static function global(Ctx $c): bool
    {
        return $c->isMain && $c->uid === App::adminId();
    }

    public static function command(Ctx $c, string $command, string $arg): bool
    {
        if ($command === '/admin') {
            self::home($c);
            return true;
        }
        if (!self::global($c)) {
            return false;
        }
        switch ($command) {
            case '/emojis':
                self::emojiList($c);
                return true;
            case '/setemoji':
                self::emojiSet($c, $arg);
                return true;
            case '/delemoji':
                $removed = [];
                preg_match_all(\Emoji::RE, $arg, $m);
                foreach ($m[0] as $emoji) {
                    \Emoji::delete($emoji);
                    $removed[] = $emoji;
                }
                $c->say($removed
                    ? '<code>' . implode(' ', $removed) . '</code> use the normal emoji again.'
                    : 'Usage: <code>/delemoji 🔗</code> (one or more normal emoji)');
                return true;
            case '/banlink':
            case '/unbanlink':
                $ban = $command === '/banlink';
                $code = strtolower(preg_replace('/^.*start=/', '', $arg));
                $n = Db::q('UPDATE links SET banned = ? WHERE code = ?', [$ban, $code])->rowCount();
                $c->say($n ? "✅ $n link(s) " . ($ban ? 'banned' : 'unbanned') : '❌ Link not found');
                return true;
            case '/banuser':
            case '/unbanuser':
                $ban = $command === '/banuser';
                $n = Db::q('UPDATE users SET banned = ? WHERE user_id = ?', [$ban, (int) $arg])->rowCount();
                if ($ban) {
                    Db::q('UPDATE links SET banned = TRUE WHERE owner_id = ?', [(int) $arg]);
                    Db::q("UPDATE bots SET status = 'banned' WHERE owner_id = ?", [(int) $arg]);
                }
                $c->say($n ? '✅ User ' . ($ban ? 'banned (with their links and bots)' : 'unbanned') : '❌ User not found');
                return true;
            case '/banbot':
            case '/unbanbot':
                $ban = $command === '/banbot';
                $key = ltrim($arg, '@');
                $n = Db::q(
                    'UPDATE bots SET status = ? WHERE is_main = FALSE AND (lower(username) = lower(?) OR id::text = ?)',
                    [$ban ? 'banned' : 'active', $key, $key]
                )->rowCount();
                $c->say($n ? '✅ Bot ' . ($ban ? 'banned' : 'unbanned') : '❌ Bot not found');
                return true;
        }
        return false;
    }

    /** /emojis: every emoji the bot uses, Premium (✅ shown animated) or not yet (⬜). */
    private static function emojiList(Ctx $c): void
    {
        \Emoji::reload();
        $used = \Emoji::used();
        $done = [];
        $todo = [];
        foreach ($used as $norm => $emoji) {
            $id = \Emoji::id($emoji);
            if ($id === null) {
                $todo[] = $emoji;
            } else {
                $done[] = '<tg-emoji emoji-id="' . $id . '">' . $emoji . '</tg-emoji>';
            }
        }
        $text = '<b>Premium emoji: ' . count($done) . ' / ' . count($used) . "</b>\n\n";
        if ($done) {
            $text .= "✅ Premium:\n" . implode(' ', $done) . "\n\n";
        }
        if ($todo) {
            $text .= "⬜ Still normal:\n<code>" . implode(' ', $todo) . "</code>\n\n";
        }
        $text .= "Set: <code>/setemoji</code> + Premium emoji (many at once is fine).\n"
            . "Pick a different look: <code>/setemoji 🗂</code> + the Premium emoji to show instead.\n"
            . "Remove: <code>/delemoji 🔗</code>";
        // Sent as-is: the ✅ list is already Premium, the ⬜ list must stay normal
        $c->tg->call('sendMessage', [
            'chat_id' => $c->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * /setemoji + Premium emoji.
     *   /setemoji 🔗📁✅        each Premium emoji replaces the normal emoji it is based on
     *   /setemoji 🗂 <Premium>  the Premium emoji replaces 🗂, whatever it is based on
     */
    private static function emojiSet(Ctx $c, string $arg): void
    {
        $text = (string) ($c->message['text'] ?? '');
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $customs = [];
        foreach ($c->message['entities'] ?? [] as $e) {
            if (($e['type'] ?? '') === 'custom_emoji') {
                $alt = mb_convert_encoding(substr($utf16, $e['offset'] * 2, $e['length'] * 2), 'UTF-8', 'UTF-16LE');
                $customs[] = ['offset' => (int) $e['offset'], 'alt' => $alt, 'id' => (string) $e['custom_emoji_id']];
            }
        }
        if (!$customs) {
            $c->say("Send Premium (animated) emoji with the command, e.g. <code>/setemoji</code> 🔗📁✅\n\nSend /emojis to see which ones the bot uses.");
            return;
        }

        // Explicit form: a normal emoji written before the first Premium one
        $firstOffset = $customs[0]['offset'];
        $before = mb_convert_encoding(substr($utf16, 0, $firstOffset * 2), 'UTF-8', 'UTF-16LE');
        $before = trim(preg_replace('#^/setemoji(@\w+)?#i', '', $before));
        $saved = [];
        if ($before !== '' && preg_match(\Emoji::RE, $before, $m) && count($customs) === 1) {
            \Emoji::save($m[0], $customs[0]['id']);
            $saved[] = '<tg-emoji emoji-id="' . $customs[0]['id'] . '">' . $m[0] . '</tg-emoji> → ' . $m[0];
        } else {
            foreach ($customs as $custom) {
                \Emoji::save($custom['alt'], $custom['id']);
                $saved[] = '<tg-emoji emoji-id="' . $custom['id'] . '">' . $custom['alt'] . '</tg-emoji> → ' . $custom['alt'];
            }
        }
        $c->tg->call('sendMessage', [
            'chat_id' => $c->chatId,
            'text' => "✅ Saved, live now:\n" . implode("\n", $saved) . "\n\nSend /emojis to see what is left.",
            'parse_mode' => 'HTML',
        ]);
    }

    /** Reply with the ids of custom emoji in the admin's message. */
    public static function emojiIds(Ctx $c): bool
    {
        $entities = $c->message['entities'] ?? $c->message['caption_entities'] ?? [];
        $text = (string) ($c->message['text'] ?? $c->message['caption'] ?? '');
        $lines = [];
        foreach ($entities as $e) {
            if (($e['type'] ?? '') === 'custom_emoji') {
                $emoji = mb_convert_encoding(
                    substr(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), $e['offset'] * 2, $e['length'] * 2),
                    'UTF-8',
                    'UTF-16LE'
                );
                $lines[] = $emoji . ' <code>' . $e['custom_emoji_id'] . '</code>';
            }
        }
        if (!$lines) {
            return false;
        }
        $c->say("Custom emoji ids:\n" . implode("\n", $lines));
        return true;
    }

    public static function home(Ctx $c): void
    {
        $c->setState(null);
        if (self::global($c)) {
            $s = [
                'main_users' => (int) Db::val('SELECT COUNT(*) FROM users WHERE bot_id = ?', [$c->botId()]),
                'all_users' => (int) Db::val('SELECT COUNT(DISTINCT user_id) FROM users'),
                'today' => (int) Db::val("SELECT COUNT(*) FROM users WHERE created_at >= date_trunc('day', now())"),
                'links' => (int) Db::val('SELECT COUNT(*) FROM links'),
                'files' => (int) Db::val('SELECT COUNT(*) FROM files'),
                'opens' => (int) Db::val('SELECT COUNT(*) FROM open_events'),
                'clones' => (int) Db::val('SELECT COUNT(*) FROM bots WHERE is_main = FALSE'),
                'pro' => (int) Db::val('SELECT COUNT(*) FROM accounts WHERE is_pro'),
                'stars' => (int) Db::val('SELECT COALESCE(SUM(stars), 0) FROM payments WHERE NOT refunded'),
                'reports' => (int) Db::val("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
            ];
            $text = "👥 Main bot users: {$s['main_users']}\n"
                . "🌍 Users (all bots): {$s['all_users']} · new today: {$s['today']}\n"
                . "🔗 Links: {$s['links']} · 📁 Files: {$s['files']}\n"
                . "👁 Opens: {$s['opens']}\n"
                . "🤖 Clone bots: {$s['clones']}\n"
                . "⭐ Pro users: {$s['pro']} · Stars earned: {$s['stars']}\n"
                . "⚠️ Open reports: {$s['reports']}";
            $rows = [
                [Ui::btn($c, '📢 Ads', 'adm:ads'), Ui::btn($c, '⚠️ Reports', 'adm:rep')],
                [Ui::btn($c, '🔄 Refresh', 'adm:home')],
            ];
        } else {
            $text = '👥 Users: ' . (int) Db::val('SELECT COUNT(*) FROM users WHERE bot_id = ?', [$c->botId()]) . "\n"
                . '🔗 Links: ' . (int) Db::val('SELECT COUNT(*) FROM links WHERE bot_id = ?', [$c->botId()]) . "\n"
                . '👁 Opens: ' . (int) Db::val('SELECT COUNT(*) FROM open_events e JOIN links l ON l.id = e.link_id WHERE l.bot_id = ?', [$c->botId()]);
            $rows = [[Ui::btn($c, '🔄 Refresh', 'adm:home')]];
        }
        $caption = Ui::title($c, 'stats', '🛠', 'Admin') . "\n\n" . Ui::quote($text);
        Ui::show($c, 'stats', $caption, $rows, $c->callback === null);
    }

    public static function callback(Ctx $c, string $action, string $arg): void
    {
        if ($action === 'home') {
            self::home($c);
            return;
        }
        if (!self::global($c)) {
            return;
        }
        switch ($action) {
            case 'ads':
                self::ads($c);
                return;
            case 'at':
                Db::q('UPDATE ads SET active = NOT active WHERE id = ?', [(int) $arg]);
                self::ads($c);
                return;
            case 'ax':
                Db::q('DELETE FROM ads WHERE id = ?', [(int) $arg]);
                self::ads($c);
                return;
            case 'aa':
                $c->setState('admin_ad');
                $caption = Ui::title($c, 'ad', '📢', 'New ad') . "\n\n"
                    . Ui::quote("Send the ad in one line:\n<code>Text | Button | https://link</code>\n\nExample:\n<code>Join our channel for updates | Join | https://t.me/omnigatework</code>");
                Ui::show($c, 'help', $caption, [[Ui::btn($c, '✖️ Cancel', 'adm:ads')]]);
                return;
            case 'rep':
                self::reports($c);
                return;
            case 'bl':
            case 'ig':
                $report = Db::one('SELECT * FROM reports WHERE id = ?', [(int) $arg]);
                if ($report) {
                    if ($action === 'bl') {
                        Db::q('UPDATE links SET banned = TRUE WHERE id = ?', [(int) $report['link_id']]);
                        Db::q("UPDATE reports SET status = 'banned' WHERE link_id = ?", [(int) $report['link_id']]);
                    } else {
                        Db::q("UPDATE reports SET status = 'ignored' WHERE id = ?", [(int) $arg]);
                    }
                }
                $c->answer($action === 'bl' ? 'Link banned' : 'Ignored');
                if (!empty($c->callback['message']['photo'])) {
                    self::reports($c);
                } else {
                    $c->tg->call('editMessageReplyMarkup', [
                        'chat_id' => $c->chatId,
                        'message_id' => $c->callback['message']['message_id'],
                        'reply_markup' => ['inline_keyboard' => []],
                    ]);
                }
                return;
        }
    }

    private static function ads(Ctx $c): void
    {
        $c->setState(null);
        $ads = Db::all('SELECT * FROM ads ORDER BY id');
        $text = $ads ? 'Tap an ad to turn it on/off. Free users see one active ad at random.' : 'No ads yet. Free users see the default ad (your news channel).';
        $rows = [];
        foreach ($ads as $ad) {
            $rows[] = [
                Ui::btn($c, ($ad['active'] ? '✅ ' : '⏸ ') . mb_strimwidth($ad['text'], 0, 30, '…'), 'adm:at:' . $ad['id']),
                Ui::btn($c, '🗑', 'adm:ax:' . $ad['id'], 'danger'),
            ];
        }
        $rows[] = [Ui::btn($c, '➕ Add ad', 'adm:aa', 'success')];
        $rows[] = [Ui::back($c, 'adm:home')];
        $caption = Ui::title($c, 'ad', '📢', 'Ads') . "\n\n" . Ui::quote($text);
        Ui::show($c, 'help', $caption, $rows);
    }

    public static function adText(Ctx $c, string $text): bool
    {
        $parts = array_map('trim', explode('|', $text));
        if (count($parts) !== 3 || !preg_match('#^(https?://|tg://)#', $parts[2])) {
            $c->say('❌ Format: <code>Text | Button | https://link</code>');
            return true;
        }
        Db::q('INSERT INTO ads (text, button_text, url) VALUES (?, ?, ?)', [$parts[0], $parts[1], $parts[2]]);
        $c->setState(null);
        $c->say('✅ Ad added.');
        self::ads($c);
        return true;
    }

    private static function reports(Ctx $c): void
    {
        $reports = Db::all(
            "SELECT r.*, l.code, l.title, b.username FROM reports r
             JOIN links l ON l.id = r.link_id JOIN bots b ON b.id = l.bot_id
             WHERE r.status = 'open' ORDER BY r.id LIMIT 8"
        );
        $lines = [];
        $rows = [];
        foreach ($reports as $r) {
            $lines[] = "#{$r['id']} · {$r['reason']} · @{$r['username']} · <code>{$r['code']}</code> · " . Ui::esc(mb_strimwidth($r['title'], 0, 30, '…'));
            $rows[] = [
                Ui::btn($c, "🚫 Ban #{$r['id']}", 'adm:bl:' . $r['id'], 'danger'),
                Ui::btn($c, "Ignore #{$r['id']}", 'adm:ig:' . $r['id']),
            ];
        }
        $rows[] = [Ui::back($c, 'adm:home')];
        $text = $lines ? implode("\n", $lines) : 'No open reports.';
        $caption = Ui::title($c, 'report', '⚠️', 'Reports') . "\n\n" . Ui::quote($text);
        Ui::show($c, 'report', $caption, $rows);
    }

    /** Tell the admin about a new report, from the main bot. */
    public static function notifyReport(int $reportId): void
    {
        $admin = App::adminId();
        $main = Db::one('SELECT * FROM bots WHERE is_main LIMIT 1');
        if (!$admin || !$main) {
            return;
        }
        $r = Db::one(
            'SELECT r.*, l.code, l.title, l.owner_id, b.username FROM reports r
             JOIN links l ON l.id = r.link_id JOIN bots b ON b.id = l.bot_id WHERE r.id = ?',
            [$reportId]
        );
        $text = "⚠️ <b>Report #{$r['id']}</b>\n"
            . "Reason: {$r['reason']}\n"
            . "Link: https://t.me/{$r['username']}?start={$r['code']}\n"
            . 'Title: ' . Ui::esc($r['title']) . "\n"
            . "Owner: <code>{$r['owner_id']}</code> · Reporter: <code>{$r['reporter_id']}</code>";
        (new Tg((string) Crypto::decrypt($main['token_enc'])))->call('sendMessage', [
            'chat_id' => $admin,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => '🚫 Ban link', 'callback_data' => 'adm:bl:' . $r['id'], 'style' => 'danger'],
                ['text' => 'Ignore', 'callback_data' => 'adm:ig:' . $r['id']],
            ]]],
        ]);
    }
}
