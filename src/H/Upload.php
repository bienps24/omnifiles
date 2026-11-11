<?php
namespace H;

use Ctx;
use Db;
use Ui;

/**
 * Incoming files: single file, albums (sent all at once), Many Files and Add files.
 */
final class Upload
{
    /** Handle a message that carries a file. Returns false if there is no file. */
    public static function incoming(Ctx $c): bool
    {
        $m = $c->message;
        $file = \Links::fileOf($m);
        if ($file === null) {
            return false;
        }
        [$kind, $fileId, $unique, $size] = $file;
        $row = [
            'message_id' => (int) $m['message_id'],
            'kind' => $kind,
            'file_id' => $fileId,
            'file_unique_id' => $unique,
            'caption' => $m['caption'] ?? null,
            'size' => $size,
            'file_name' => $m['document']['file_name'] ?? $m['video']['file_name'] ?? $m['audio']['file_name'] ?? null,
        ];

        $state = $c->state();
        if ($state === 'many') {
            self::buffer($c, 'many', $row);
            self::markDirty($c);
            return true;
        }
        if ($state === 'add') {
            self::buffer($c, 'add:' . (int) $c->stateData()['link'], $row);
            self::markDirty($c);
            return true;
        }
        if ($state !== null) {
            $c->setState(null);
        }

        // Album: the worker turns the whole group into one link after the last part arrives
        if (!empty($m['media_group_id'])) {
            self::buffer($c, 'mg:' . $m['media_group_id'], $row);
            return true;
        }

        $link = \Links::create($c, $c->uid, [$row], false);
        self::linkReady($c, $link, true);
        return true;
    }

    private static function buffer(Ctx $c, string $bucket, array $row): void
    {
        Db::q(
            'INSERT INTO pending_files (bot_id, user_id, bucket, message_id, kind, file_id, file_unique_id, caption, size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$c->botId(), $c->uid, $bucket, $row['message_id'], $row['kind'], $row['file_id'], $row['file_unique_id'], $row['caption'], $row['size']]
        );
    }

    /** Ask the worker to refresh the "Files so far" counter. */
    private static function markDirty(Ctx $c): void
    {
        Db::q(
            "UPDATE users SET state_data = state_data || '{\"dirty\": 1}'::jsonb WHERE bot_id = ? AND user_id = ?",
            [$c->botId(), $c->uid]
        );
    }

    public static function pending(Ctx $c, string $bucket): array
    {
        return Db::all(
            'SELECT * FROM pending_files WHERE bot_id = ? AND user_id = ? AND bucket = ? ORDER BY message_id',
            [$c->botId(), $c->uid, $bucket]
        );
    }

    private static function clearPending(Ctx $c, string $bucket): void
    {
        Db::q('DELETE FROM pending_files WHERE bot_id = ? AND user_id = ? AND bucket = ?', [$c->botId(), $c->uid, $bucket]);
    }

    /* ---------------- Link ready ---------------- */

    public static function linkReady(Ctx $c, array $link, bool $forceNew): void
    {
        $sum = \Links::summary((int) $link['id']);
        $url = $c->linkUrl($link['code']);
        $caption = Ui::title($c, 'done', '✅', $c->t('t_link_ready')) . "\n\n"
            . Ui::quote('📚 ' . Ui::esc($link['title']) . ' · ' . $c->t('n_files', ['n' => $sum['files']])) . "\n"
            . '🔗 ' . Ui::esc($url) . "\n\n"
            . '<i>' . $c->t('link_ready_hint') . '</i>'
            . Ui::credit($c);

        $rows = [
            [
                Ui::urlBtn($c, '📤 ' . $c->t('btn_share'), Ui::shareUrl($url), 'success', 'share'),
                Ui::copyBtn($c, '📋 ' . $c->t('btn_copy'), $url, 'primary'),
            ],
            [
                Ui::btn($c, '✏️ ' . $c->t('btn_rename'), 'rn:' . $link['id'], null, 'rename'),
                Ui::btn($c, '➕ ' . $c->t('btn_add_files'), 'af:' . $link['id'], null, 'add'),
            ],
            [Ui::btn($c, '⚙️ ' . $c->t('btn_options'), 'lk:' . $link['id'], null, 'settings')],
        ];
        $ad = Ui::ad($c);
        if ($ad) {
            $caption .= $ad[0];
            $rows[] = $ad[1];
        }
        Ui::show($c, 'link_ready', $caption, $rows, $forceNew, true);
    }

    /* ---------------- Many Files ---------------- */

    public static function manyStart(Ctx $c): void
    {
        self::clearPending($c, 'many');
        $msg = Ui::show($c, 'many_files', self::manyCaption($c, 0), self::manyButtons($c), $c->callback === null);
        $c->setState('many', ['msg' => $msg['message_id'] ?? null]);
    }

    public static function manyCaption(Ctx $c, int $count): string
    {
        return Ui::title($c, 'many', '📚', $c->t('t_many')) . "\n\n"
            . Ui::quote(Ui::num(1) . ' ' . $c->t('many_step1') . "\n\n" . $c->t('files_so_far', ['n' => '<b>' . $count . '</b>'])) . "\n"
            . Ui::num(2) . ' ' . $c->t('many_step2');
    }

    public static function manyButtons(Ctx $c): array
    {
        return [
            [Ui::btn($c, '✅ ' . $c->t('btn_make_link'), 'mfm', 'success', 'done')],
            [Ui::btn($c, '✖️ ' . $c->t('btn_cancel'), 'mfc', null, 'cancel')],
        ];
    }

    public static function manyMake(Ctx $c): void
    {
        $files = self::pending($c, 'many');
        if (!$files) {
            $c->answer($c->t('need_one_file'), true);
            return;
        }
        $link = \Links::create($c, $c->uid, $files, true);
        self::clearPending($c, 'many');
        $c->setState(null);
        self::linkReady($c, $link, false);
    }

    public static function manyCancel(Ctx $c): void
    {
        self::clearPending($c, 'many');
        $c->setState(null);
        $c->answer($c->t('cancelled'));
        if ($c->callback) {
            $c->tg->call('deleteMessage', ['chat_id' => $c->chatId, 'message_id' => $c->callback['message']['message_id']]);
        }
    }

    /* ---------------- Add files to an existing link ---------------- */

    public static function addStart(Ctx $c, int $linkId): void
    {
        $link = \Links::mine($c, $linkId);
        if (!$link) {
            return;
        }
        self::clearPending($c, 'add:' . $linkId);
        $msg = Ui::show($c, 'many_files', self::addCaption($c, $link, 0), self::addButtons($c, $linkId));
        $c->setState('add', ['link' => $linkId, 'msg' => $msg['message_id'] ?? null]);
    }

    public static function addCaption(Ctx $c, array $link, int $count): string
    {
        return Ui::title($c, 'add', '➕', $c->t('t_add_files')) . "\n\n"
            . Ui::quote($c->t('add_step', ['title' => '<b>' . Ui::esc($link['title']) . '</b>']) . "\n\n"
                . $c->t('files_so_far', ['n' => '<b>' . $count . '</b>']));
    }

    public static function addButtons(Ctx $c, int $linkId): array
    {
        return [
            [Ui::btn($c, '✅ ' . $c->t('btn_done'), 'afd:' . $linkId, 'success', 'done')],
            [Ui::btn($c, '✖️ ' . $c->t('btn_cancel'), 'lk:' . $linkId, null, 'cancel')],
        ];
    }

    public static function addDone(Ctx $c, int $linkId): void
    {
        $link = \Links::mine($c, $linkId);
        if (!$link) {
            return;
        }
        $files = self::pending($c, 'add:' . $linkId);
        if ($files) {
            \Links::addFiles($c, $linkId, $c->uid, $files);
            self::clearPending($c, 'add:' . $linkId);
        }
        $c->setState(null);
        Links::view($c, $linkId);
    }

    /** Called by the worker: refresh the counter on the Many Files / Add files screen. */
    public static function refreshCounter(Ctx $c): void
    {
        $data = $c->stateData();
        $msgId = $data['msg'] ?? null;
        unset($data['dirty']);
        $c->setState($c->state(), $data);
        if (!$msgId) {
            return;
        }
        if ($c->state() === 'many') {
            $count = count(self::pending($c, 'many'));
            $caption = self::manyCaption($c, $count);
            $buttons = self::manyButtons($c);
        } else {
            $linkId = (int) ($data['link'] ?? 0);
            $link = \Links::mine($c, $linkId);
            if (!$link) {
                return;
            }
            $count = count(self::pending($c, 'add:' . $linkId));
            $caption = self::addCaption($c, $link, $count);
            $buttons = self::addButtons($c, $linkId);
        }
        $c->tg->call('editMessageCaption', [
            'chat_id' => $c->chatId,
            'message_id' => $msgId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $buttons],
        ]);
    }

    /** Called by the worker: an album has finished arriving, make one link from it. */
    public static function finalizeAlbum(Ctx $c, string $bucket): void
    {
        $files = self::pending($c, $bucket);
        self::clearPending($c, $bucket);
        if (!$files) {
            return;
        }
        $link = \Links::create($c, $c->uid, $files, true);
        self::linkReady($c, $link, true);
    }
}
