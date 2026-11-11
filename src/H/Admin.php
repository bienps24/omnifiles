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
 *   /link <code or link>    who uploaded a link, what is in it
 *   /user <id|@username>    everything about one user: uploads, reports, bots
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
                $n = self::banUser((int) $arg, $ban);
                $c->say($n ? '✅ User ' . ($ban ? 'banned (with their links and bots)' : 'unbanned') : '❌ User not found');
                return true;
            case '/user':
                self::userInfo($c, $arg);
                return true;
            case '/link':
                self::linkInfo($c, $arg);
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
            case 'rd':
                self::reportScreen($c, (int) $arg);
                return;
            case 'bu':
                $report = Db::one(
                    'SELECT r.*, l.owner_id FROM reports r JOIN links l ON l.id = r.link_id WHERE r.id = ?',
                    [(int) $arg]
                );
                if ($report) {
                    self::banUser((int) $report['owner_id'], true);
                    Db::q(
                        "UPDATE reports SET status = 'banned' WHERE status = 'open'
                         AND link_id IN (SELECT id FROM links WHERE owner_id = ?)",
                        [(int) $report['owner_id']]
                    );
                }
                $c->answer('Uploader banned, with all their links');
                self::afterAction($c);
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
                self::afterAction($c);
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

    /** After a report action: back to the list (panel screen) or clear the alert's buttons. */
    private static function afterAction(Ctx $c): void
    {
        if (!empty($c->callback['message']['photo'])) {
            self::reports($c);
            return;
        }
        $c->tg->call('editMessageReplyMarkup', [
            'chat_id' => $c->chatId,
            'message_id' => $c->callback['message']['message_id'],
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }

    /** Ban or unban a user everywhere. Banning also bans their links and clone bots. Returns rows changed. */
    private static function banUser(int $userId, bool $ban): int
    {
        $n = Db::q('UPDATE users SET banned = ? WHERE user_id = ?', [$ban, $userId])->rowCount();
        if ($ban) {
            Db::q('UPDATE links SET banned = TRUE WHERE owner_id = ?', [$userId]);
            Db::q("UPDATE bots SET status = 'banned' WHERE owner_id = ? AND is_main = FALSE", [$userId]);
        }
        return $n;
    }

    /* ---------------- Who is who ---------------- */

    /** "Juan Dela Cruz @juan · 123456" with a tap-able name. Prefers what the given bot knows. */
    private static function person(int $userId, ?int $botId = null): string
    {
        $u = Db::one(
            'SELECT first_name, username FROM users WHERE user_id = ?
             ORDER BY (bot_id = ?) DESC, last_seen DESC LIMIT 1',
            [$userId, $botId ?? 0]
        );
        $name = trim((string) ($u['first_name'] ?? '')) !== '' ? $u['first_name'] : 'User';
        $line = '<a href="tg://user?id=' . $userId . '">' . Ui::esc(mb_strimwidth($name, 0, 32, '…')) . '</a>';
        if (!empty($u['username'])) {
            $line .= ' @' . Ui::esc($u['username']);
        }
        return $line . ' · <code>' . $userId . '</code>';
    }

    /** Short record of an uploader: Pro, links, files, reports against them, banned. */
    private static function uploaderStats(int $userId): string
    {
        $links = (int) Db::val('SELECT COUNT(*) FROM links WHERE owner_id = ?', [$userId]);
        $files = (int) Db::val('SELECT COUNT(*) FROM files f JOIN links l ON l.id = f.link_id WHERE l.owner_id = ?', [$userId]);
        $against = (int) Db::val('SELECT COUNT(*) FROM reports r JOIN links l ON l.id = r.link_id WHERE l.owner_id = ?', [$userId]);
        $acc = Ctx::account($userId);
        $banned = (bool) Db::val('SELECT bool_or(banned) FROM users WHERE user_id = ?', [$userId]);
        return ($acc['is_pro'] ? '⭐ Pro · ' : '')
            . "$links links · $files files · reported {$against}×"
            . ($banned ? ' · ⛔ banned' : '');
    }

    /** Short record of a reporter: how many reports, and how they were judged. */
    private static function reporterStats(int $userId): string
    {
        $r = Db::one(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'banned') AS banned,
                    COUNT(*) FILTER (WHERE status = 'ignored') AS ignored
             FROM reports WHERE reporter_id = ?",
            [$userId]
        );
        return "{$r['total']} reports sent · {$r['banned']} led to a ban · {$r['ignored']} ignored";
    }

    /** What the link holds: "12 files (10 video, 2 photo) · 340 people opened". */
    private static function linkContents(int $linkId): string
    {
        $kinds = Db::all('SELECT kind, COUNT(*) AS n FROM files WHERE link_id = ? GROUP BY kind ORDER BY n DESC', [$linkId]);
        $total = array_sum(array_map(fn($k) => (int) $k['n'], $kinds));
        $parts = array_map(fn($k) => $k['n'] . ' ' . $k['kind'], $kinds);
        $people = (int) Db::val('SELECT COUNT(*) FROM opens WHERE link_id = ?', [$linkId]);
        return "$total files" . ($parts ? ' (' . implode(', ', $parts) . ')' : '') . " · $people people opened";
    }

    /** The full report card, used by the alert and the panel. */
    private static function reportText(int $reportId): ?string
    {
        $r = Db::one(
            'SELECT r.*, l.id AS lid, l.code, l.title, l.owner_id, l.bot_id, l.banned AS link_banned, l.created_at AS link_created, b.username
             FROM reports r JOIN links l ON l.id = r.link_id JOIN bots b ON b.id = l.bot_id WHERE r.id = ?',
            [$reportId]
        );
        if ($r === null) {
            return null;
        }
        $others = (int) Db::val('SELECT COUNT(*) FROM reports WHERE link_id = ?', [(int) $r['lid']]);
        return "⚠️ <b>Report #{$r['id']}</b> · {$r['reason']} · " . $r['status'] . "\n"
            . 'Reported ' . date('M j, H:i', strtotime($r['created_at'])) . " UTC · this link has $others report(s)\n\n"
            . "🔗 <b>Link</b>\n"
            . "https://t.me/{$r['username']}?start={$r['code']}\n"
            . Ui::esc($r['title']) . "\n"
            . self::linkContents((int) $r['lid'])
            . ($r['link_banned'] ? "\n⛔ link banned" : '') . "\n\n"
            . "📤 <b>Uploaded by</b>\n"
            . self::person((int) $r['owner_id'], (int) $r['bot_id']) . "\n"
            . self::uploaderStats((int) $r['owner_id']) . "\n\n"
            . "🚩 <b>Reported by</b>\n"
            . self::person((int) $r['reporter_id'], (int) $r['bot_id']) . "\n"
            . self::reporterStats((int) $r['reporter_id']);
    }

    private static function reportButtons(Ctx $c, int $reportId, bool $panel): array
    {
        $rows = [
            [
                Ui::btn($c, '🚫 Ban link', 'adm:bl:' . $reportId, 'danger'),
                Ui::btn($c, '⛔ Ban uploader', 'adm:bu:' . $reportId, 'danger'),
            ],
            [Ui::btn($c, 'Ignore', 'adm:ig:' . $reportId)],
        ];
        if ($panel) {
            $rows[] = [Ui::back($c, 'adm:rep')];
        }
        return $rows;
    }

    /** Panel: open reports, newest first, one button each. */
    private static function reports(Ctx $c): void
    {
        $reports = Db::all(
            "SELECT r.id, r.reason, r.reporter_id, l.owner_id, l.bot_id, l.title FROM reports r
             JOIN links l ON l.id = r.link_id
             WHERE r.status = 'open' ORDER BY r.id DESC LIMIT 5"
        );
        $lines = [];
        $rows = [];
        foreach ($reports as $r) {
            $lines[] = "<b>#{$r['id']}</b> · {$r['reason']} · " . Ui::esc(mb_strimwidth($r['title'], 0, 24, '…')) . "\n"
                . '📤 ' . self::person((int) $r['owner_id'], (int) $r['bot_id']) . "\n"
                . '🚩 ' . self::person((int) $r['reporter_id'], (int) $r['bot_id']);
            $rows[] = [Ui::btn($c, "🔎 Open report #{$r['id']}", 'adm:rd:' . $r['id'])];
        }
        $rows[] = [Ui::back($c, 'adm:home')];
        $text = $lines ? implode("\n\n", $lines) : 'No open reports.';
        $caption = Ui::title($c, 'report', '⚠️', 'Reports') . "\n\n" . Ui::quote($text);
        Ui::show($c, 'report', $caption, $rows);
    }

    private static function reportScreen(Ctx $c, int $reportId): void
    {
        $text = self::reportText($reportId);
        if ($text === null) {
            $c->answer('Report not found');
            return;
        }
        Ui::show($c, 'report', $text, self::reportButtons($c, $reportId, true));
    }

    /** /link <code or link>: who uploaded it, what is in it, reports. */
    private static function linkInfo(Ctx $c, string $arg): void
    {
        $code = strtolower(trim(preg_replace('/^.*start=/', '', $arg)));
        $l = $code === '' ? null : Db::one(
            'SELECT l.*, b.username FROM links l JOIN bots b ON b.id = l.bot_id WHERE l.code = ? ORDER BY l.id DESC LIMIT 1',
            [$code]
        );
        if ($l === null) {
            $c->say('Usage: <code>/link abc123</code> or <code>/link https://t.me/bot?start=abc123</code>');
            return;
        }
        $reports = (int) Db::val('SELECT COUNT(*) FROM reports WHERE link_id = ?', [(int) $l['id']]);
        $c->say(
            "🔗 <b>Link</b> · @{$l['username']}\n"
            . "https://t.me/{$l['username']}?start={$l['code']}\n"
            . Ui::esc($l['title']) . "\n"
            . self::linkContents((int) $l['id']) . "\n"
            . 'Made ' . date('M j Y, H:i', strtotime($l['created_at'])) . " UTC · $reports report(s)"
            . ($l['banned'] ? ' · ⛔ banned' : '') . "\n\n"
            . "📤 <b>Uploaded by</b>\n"
            . self::person((int) $l['owner_id'], (int) $l['bot_id']) . "\n"
            . self::uploaderStats((int) $l['owner_id'])
        );
    }

    /** /user <id or @username>: everything about one person. */
    private static function userInfo(Ctx $c, string $arg): void
    {
        $arg = trim($arg);
        $userId = ctype_digit($arg) ? (int) $arg : (int) Db::val(
            'SELECT user_id FROM users WHERE lower(username) = lower(?) ORDER BY last_seen DESC LIMIT 1',
            [ltrim($arg, '@')]
        );
        $seen = $userId ? Db::one('SELECT MIN(created_at) AS first, MAX(last_seen) AS last FROM users WHERE user_id = ?', [$userId]) : null;
        if (!$userId || $seen['first'] === null) {
            $c->say('Usage: <code>/user 123456789</code> or <code>/user @username</code>');
            return;
        }
        $bots = Db::all(
            "SELECT username, status FROM bots WHERE owner_id = ? AND is_main = FALSE ORDER BY id",
            [$userId]
        );
        $recent = Db::all(
            'SELECT l.code, l.title, b.username, l.banned,
                    (SELECT COUNT(*) FROM files f WHERE f.link_id = l.id) AS files,
                    (SELECT COUNT(*) FROM reports r WHERE r.link_id = l.id) AS reports
             FROM links l JOIN bots b ON b.id = l.bot_id WHERE l.owner_id = ? ORDER BY l.id DESC LIMIT 5',
            [$userId]
        );
        $text = "👤 <b>User</b>\n"
            . self::person($userId) . "\n"
            . 'First seen ' . date('M j Y', strtotime($seen['first'])) . ' · last active ' . date('M j, H:i', strtotime($seen['last'])) . " UTC\n\n"
            . "📤 <b>As uploader</b>\n" . self::uploaderStats($userId) . "\n\n"
            . "🚩 <b>As reporter</b>\n" . self::reporterStats($userId);
        if ($bots) {
            $text .= "\n\n🤖 <b>Clone bots</b>\n" . implode("\n", array_map(
                fn($b) => '@' . Ui::esc($b['username']) . ' · ' . $b['status'],
                $bots
            ));
        }
        if ($recent) {
            $text .= "\n\n🔗 <b>Latest links</b>\n" . implode("\n", array_map(
                fn($l) => "<code>{$l['code']}</code> · {$l['files']} files · {$l['reports']} reports"
                    . ($l['banned'] ? ' · ⛔' : '') . ' · ' . Ui::esc(mb_strimwidth($l['title'], 0, 24, '…')),
                $recent
            ));
        }
        $c->say($text);
    }

    /** Tell the admin about a new report, from the main bot. */
    public static function notifyReport(int $reportId): void
    {
        $admin = App::adminId();
        $main = Db::one('SELECT * FROM bots WHERE is_main LIMIT 1');
        if (!$admin || !$main) {
            return;
        }
        $text = self::reportText($reportId);
        if ($text === null) {
            return;
        }
        (new Tg((string) Crypto::decrypt($main['token_enc'])))->call('sendMessage', [
            'chat_id' => $admin,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => [
                [
                    ['text' => '🚫 Ban link', 'callback_data' => 'adm:bl:' . $reportId, 'style' => 'danger'],
                    ['text' => '⛔ Ban uploader', 'callback_data' => 'adm:bu:' . $reportId, 'style' => 'danger'],
                ],
                [['text' => 'Ignore', 'callback_data' => 'adm:ig:' . $reportId]],
            ]],
        ]);
    }
}
