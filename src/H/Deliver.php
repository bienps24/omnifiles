<?php
namespace H;

use App;
use Ctx;
use Db;
use Tg;
use Ui;

/**
 * Someone opens a link: checks, password, sending the files as albums, auto-delete, re-send, report.
 */
final class Deliver
{
    /** Kinds that can share one album, by family. */
    private const FAMILY = ['photo' => 'visual', 'video' => 'visual', 'document' => 'document', 'audio' => 'audio'];

    private const SEND_METHOD = [
        'photo' => ['sendPhoto', 'photo'],
        'video' => ['sendVideo', 'video'],
        'document' => ['sendDocument', 'document'],
        'audio' => ['sendAudio', 'audio'],
        'voice' => ['sendVoice', 'voice'],
        'animation' => ['sendAnimation', 'animation'],
        'video_note' => ['sendVideoNote', 'video_note'],
        'sticker' => ['sendSticker', 'sticker'],
    ];

    public const REPORT_REASONS = ['illegal', 'adult', 'scam', 'copyright', 'other'];

    public static function open(Ctx $c, string $code): void
    {
        $link = \Links::byCode($c->botId(), $code);
        if ($link === null) {
            self::notice($c, 'deleted', 'gone_title', 'link_missing');
            return;
        }
        if ($c->userIsNew) {
            Db::q('UPDATE users SET joined_via = ? WHERE bot_id = ? AND user_id = ?', [(int) $link['id'], $c->botId(), $c->uid]);
        }
        if ($link['banned']) {
            self::notice($c, 'report', 'gone_title', 'link_removed');
            return;
        }
        if ($link['paused']) {
            self::notice($c, 'paused', 'paused_title', 'link_paused');
            return;
        }
        if ($link['expires_at'] !== null && strtotime($link['expires_at']) < time()) {
            self::notice($c, 'paused', 'expired_title', 'link_expired');
            return;
        }
        $seen = Db::val('SELECT 1 FROM opens WHERE link_id = ? AND user_id = ?', [(int) $link['id'], $c->uid]);
        if ($link['max_people'] !== null && !$seen) {
            $people = (int) Db::val('SELECT COUNT(*) FROM opens WHERE link_id = ?', [(int) $link['id']]);
            if ($people >= (int) $link['max_people'] && (int) $link['owner_id'] !== $c->uid) {
                self::notice($c, 'people', 'full_title', 'link_full');
                return;
            }
        }
        if ($link['password_hash'] !== null && (int) $link['owner_id'] !== $c->uid) {
            $c->setState('link_password', ['link' => (int) $link['id'], 'new' => $c->userIsNew]);
            $caption = Ui::title($c, 'password', '🔑', $c->t('t_password_needed')) . "\n\n"
                . Ui::quote($c->t('password_needed_text') . "\n💡 " . $c->t('password_needed_tip'));
            Ui::show($c, 'password_needed', $caption, [[Ui::btn($c, '✖️ ' . $c->t('btn_cancel'), 'pwc', null, 'cancel')]], true);
            return;
        }
        self::deliver($c, $link, false, $c->userIsNew);
    }

    private static function notice(Ctx $c, string $banner, string $titleKey, string $textKey): void
    {
        $caption = Ui::title($c, 'info', 'ℹ️', $c->t($titleKey)) . "\n\n" . Ui::quote($c->t($textKey));
        Ui::show($c, $banner, $caption . Ui::credit($c), ['reply' => Ui::menu($c)], true, true);
    }

    public static function passwordText(Ctx $c, int $linkId, string $text): bool
    {
        $c->tg->call('deleteMessage', ['chat_id' => $c->chatId, 'message_id' => $c->message['message_id']]);
        $link = Db::one('SELECT * FROM links WHERE id = ? AND bot_id = ?', [$linkId, $c->botId()]);
        if (!$link || $link['password_hash'] === null) {
            $c->setState(null);
            return true;
        }
        if (!password_verify(trim($text), $link['password_hash'])) {
            $c->say('❌ ' . $c->t('password_wrong'));
            return true;
        }
        $wasNew = (bool) ($c->stateData()['new'] ?? false);
        $c->setState(null);
        self::deliver($c, $link, false, $wasNew);
        return true;
    }

    public static function passwordCancel(Ctx $c): void
    {
        $c->setState(null);
        $c->answer($c->t('cancelled'));
        $c->tg->call('deleteMessage', ['chat_id' => $c->chatId, 'message_id' => $c->callback['message']['message_id']]);
    }

    /** Re-send button after auto-delete. */
    public static function resend(Ctx $c, int $linkId): void
    {
        $link = Db::one('SELECT * FROM links WHERE id = ? AND bot_id = ?', [$linkId, $c->botId()]);
        if (!$link || !$link['resend']) {
            $c->answer($c->t('resend_off'), true);
            return;
        }
        if ($link['banned'] || $link['paused'] || ($link['expires_at'] !== null && strtotime($link['expires_at']) < time())) {
            $c->answer($c->t('link_unavailable'), true);
            return;
        }
        $c->answer();
        self::deliver($c, $link, true, false);
    }

    /** Send every file of the link, then the info message, and schedule the auto-delete. */
    public static function deliver(Ctx $c, array $link, bool $isResend, bool $isNewUser): void
    {
        $files = Db::all('SELECT * FROM files WHERE link_id = ? ORDER BY position', [(int) $link['id']]);
        $protect = (bool) $link['protect'];
        $sent = [];

        if ($link['intro']) {
            $m = $c->tg->get('sendMessage', ['chat_id' => $c->chatId, 'text' => $link['intro'], 'protect_content' => $protect]);
            if ($m) {
                $sent[] = (int) $m['message_id'];
            }
        }

        foreach (self::groups($files) as $group) {
            $ids = self::sendGroup($c->tg, $c->chatId, $group, $protect);
            if ($ids === null) {
                // 403: the person blocked the bot
                Db::q('UPDATE users SET blocked = TRUE WHERE bot_id = ? AND user_id = ?', [$c->botId(), $c->uid]);
                return;
            }
            array_push($sent, ...$ids);
        }

        $minutes = intdiv((int) $link['autodelete_sec'], 60);
        $text = Ui::e($c, 'clock', '⏲') . ' ' . $c->t('files_will_delete', ['n' => $minutes]);
        if ($link['resend']) {
            $text .= "\n🔁 " . $c->t('files_resend_hint');
        }
        $rows = [];
        if ($link['resend']) {
            $rows[] = [Ui::btn($c, '🔁 ' . $c->t('btn_get_again'), 'get:' . $link['id'], 'success', 'resend')];
        }
        $rows[] = [Ui::btn($c, '⚠️ ' . $c->t('btn_report'), 'rp:' . $link['id'], null, 'report')];
        $ad = Ui::ad($c);
        if ($ad) {
            $text .= $ad[0];
            $rows[] = $ad[1];
        }
        $info = $c->tg->get('sendMessage', [
            'chat_id' => $c->chatId,
            'text' => $text . Ui::credit($c),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'protect_content' => true,
            'reply_markup' => ['inline_keyboard' => $rows],
        ]);
        if ($info && !$link['resend']) {
            $sent[] = (int) $info['message_id'];
        }

        if ($sent) {
            Db::q(
                'INSERT INTO deletions (bot_id, chat_id, message_ids, due_at) VALUES (?, ?, ?::bigint[], ?)',
                [$c->botId(), $c->chatId, '{' . implode(',', $sent) . '}', gmdate('c', time() + (int) $link['autodelete_sec'])]
            );
        }

        // Stats
        Db::q('INSERT INTO open_events (link_id, user_id) VALUES (?, ?)', [(int) $link['id'], $c->uid]);
        Db::q(
            'INSERT INTO opens (link_id, user_id, was_new) VALUES (?, ?, ?)
             ON CONFLICT (link_id, user_id) DO UPDATE SET times = opens.times + 1, last_at = now()',
            [(int) $link['id'], $c->uid, $isNewUser]
        );
    }

    /** Split files into sendable groups: albums of up to 10 of the same family, others alone. */
    private static function groups(array $files): array
    {
        $groups = [];
        $current = [];
        $family = null;
        foreach ($files as $f) {
            $fam = self::FAMILY[$f['kind']] ?? null;
            if ($fam === null) {
                if ($current) {
                    $groups[] = $current;
                    $current = [];
                }
                $groups[] = [$f];
                $family = null;
                continue;
            }
            if ($fam !== $family || count($current) >= 10) {
                if ($current) {
                    $groups[] = $current;
                }
                $current = [];
                $family = $fam;
            }
            $current[] = $f;
        }
        if ($current) {
            $groups[] = $current;
        }
        return $groups;
    }

    /** Returns sent message ids, or null if the user blocked the bot. */
    private static function sendGroup(Tg $tg, int $chatId, array $group, bool $protect): ?array
    {
        if (count($group) === 1 || !isset(self::FAMILY[$group[0]['kind']])) {
            $f = $group[0];
            [$method, $field] = self::SEND_METHOD[$f['kind']] ?? ['sendDocument', 'document'];
            $params = ['chat_id' => $chatId, $field => $f['file_id'], 'protect_content' => $protect];
            if ($f['caption'] && !in_array($f['kind'], ['sticker', 'video_note'], true)) {
                $params['caption'] = $f['caption'];
            }
            $res = $tg->call($method, $params);
            if (!$res['ok']) {
                return ($res['error_code'] ?? 0) === 403 ? null : [];
            }
            return [(int) $res['result']['message_id']];
        }

        $media = [];
        foreach ($group as $f) {
            $item = ['type' => $f['kind'], 'media' => $f['file_id']];
            if ($f['caption']) {
                $item['caption'] = $f['caption'];
            }
            $media[] = $item;
        }
        $res = $tg->call('sendMediaGroup', ['chat_id' => $chatId, 'media' => $media, 'protect_content' => $protect]);
        if (!$res['ok']) {
            return ($res['error_code'] ?? 0) === 403 ? null : [];
        }
        return array_map(fn($m) => (int) $m['message_id'], $res['result']);
    }

    /* ---------------- Report ---------------- */

    public static function report(Ctx $c, int $linkId): void
    {
        $rows = [];
        foreach (self::REPORT_REASONS as $reason) {
            $rows[] = [Ui::btn($c, $c->t('reason_' . $reason), "rpr:$linkId:$reason")];
        }
        $caption = Ui::title($c, 'report', '⚠️', $c->t('t_report')) . "\n\n" . Ui::quote($c->t('report_text'));
        Ui::show($c, 'report', $caption, $rows, true);
    }

    public static function reportReason(Ctx $c, int $linkId, string $reason): void
    {
        if (!in_array($reason, self::REPORT_REASONS, true)) {
            return;
        }
        $link = Db::one('SELECT * FROM links WHERE id = ? AND bot_id = ?', [$linkId, $c->botId()]);
        if (!$link) {
            return;
        }
        $already = Db::val('SELECT 1 FROM reports WHERE link_id = ? AND reporter_id = ?', [$linkId, $c->uid]);
        if (!$already) {
            $reportId = (int) Db::val(
                'INSERT INTO reports (link_id, reporter_id, reason) VALUES (?, ?, ?) RETURNING id',
                [$linkId, $c->uid, $reason]
            );
            Admin::notifyReport($reportId);
        }
        $c->answer($c->t('report_thanks'), true);
        $c->tg->call('deleteMessage', ['chat_id' => $c->chatId, 'message_id' => $c->callback['message']['message_id']]);
    }
}
