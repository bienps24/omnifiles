<?php
namespace H;

use App;
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

        if (!self::withinRate($c)) {
            return true;
        }

        $state = $c->state();
        if ($state === 'many') {
            // Free users: the worker turns every full set of files into its own link
            self::buffer($c, 'many', $row);
            self::markDirty($c);
            return true;
        }
        if ($state === 'add') {
            $linkId = (int) $c->stateData()['link'];
            $bucket = 'add:' . $linkId;
            if (self::room($c, $bucket, $linkId) <= 0) {
                self::limitNotice($c);
                return true;
            }
            self::buffer($c, $bucket, $row);
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

    /* ---------------- Free file limit ---------------- */

    /** Files already in the link (0 for a new link). */
    private static function existing(int $linkId): int
    {
        return $linkId > 0 ? (int) Db::val('SELECT COUNT(*) FROM files WHERE link_id = ?', [$linkId]) : 0;
    }

    /** How many more files fit in this upload, PHP_INT_MAX when there is no limit. */
    private static function room(Ctx $c, string $bucket, int $linkId): int
    {
        $limit = $c->fileLimit();
        if ($limit === null) {
            return PHP_INT_MAX;
        }
        $pending = (int) Db::val(
            'SELECT COUNT(*) FROM pending_files WHERE bot_id = ? AND user_id = ? AND bucket = ?',
            [$c->botId(), $c->uid, $bucket]
        );
        return $limit - self::existing($linkId) - $pending;
    }

    /** Keep only the files that fit (files sent at the same moment can slip past the check above). */
    private static function fit(Ctx $c, array $files, int $linkId): array
    {
        $limit = $c->fileLimit();
        if ($limit === null) {
            return $files;
        }
        return array_slice($files, 0, max(0, $limit - self::existing($linkId)));
    }

    /** Tell a free user once per upload that the link is full, with the way to Pro. */
    private static function limitNotice(Ctx $c): void
    {
        $first = Db::q(
            "UPDATE users SET state_data = state_data || '{\"capped\": 1}'::jsonb
             WHERE bot_id = ? AND user_id = ? AND state_data->>'capped' IS NULL",
            [$c->botId(), $c->uid]
        )->rowCount();
        if ($first === 0) {
            return;
        }
        $text = '🔒 ' . $c->t('files_limit_reached', ['max' => App::freeFileLimit(), 'n' => App::proPrice()]);
        $c->say($text, ['inline_keyboard' => [[Ui::urlBtn(
            $c,
            '⭐ ' . $c->t('btn_unlimited_pro', ['n' => App::proPrice()]),
            'https://t.me/' . App::payBot() . '?start=ofiles_pro',
            'success',
            'star'
        )]]]);
    }

    /** "Files so far: 37 / 100" for free users, "Files so far: 37" for Pro. */
    private static function soFar(Ctx $c, int $count, int $existing = 0): string
    {
        $limit = $c->fileLimit();
        $n = '<b>' . ($existing + $count) . '</b>';
        if ($limit !== null) {
            $n .= ' / ' . $limit;
        }
        return $c->t('files_so_far', ['n' => $n]);
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
        $limit = $c->fileLimit();
        return Ui::title($c, 'many', '📚', $c->t('t_many')) . "\n\n"
            . Ui::quote(Ui::num(1) . ' ' . $c->t('many_step1') . "\n\n" . self::soFar($c, $count)) . "\n"
            . Ui::num(2) . ' ' . $c->t('many_step2')
            . ($limit !== null ? "\n\n<i>" . $c->t('many_free_split', ['max' => $limit]) . '</i>' : '');
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
        $limit = $c->fileLimit();
        $links = [];
        while ($files = self::claim($c, $limit ?? 1000000)) {
            $links[] = self::makePart($c, $files);
            if ($limit === null) {
                break;
            }
        }
        $madeBefore = (int) ($c->stateData()['parts'] ?? 0);
        if (!$links && $madeBefore === 0) {
            $c->answer($c->t('need_one_file'), true);
            return;
        }
        $c->setState(null);
        if (count($links) === 1) {
            // The Many Files screen turns into the result
            self::linkReady($c, $links[0], false);
            return;
        }
        // Several (or no) new links: the Many Files screen goes, each link gets its own message
        $c->tg->call('deleteMessage', ['chat_id' => $c->chatId, 'message_id' => (int) $c->callback['message']['message_id']]);
        foreach ($links as $link) {
            self::linkReady($c, $link, true);
        }
        $c->answer('✅');
    }

    /**
     * Worker: a free user in Many Files has a full set of files → it becomes its own link now,
     * and the Many Files screen moves back below the new links.
     */
    public static function splitMany(Ctx $c): void
    {
        $limit = $c->fileLimit();
        if ($limit === null) {
            return;
        }
        $made = 0;
        while (self::pendingCount($c) >= $limit) {
            $files = self::claim($c, $limit);
            if (!$files) {
                break;
            }
            self::linkReady($c, self::makePart($c, $files), true);
            $made++;
        }
        if ($made === 0 || $c->state() !== 'many') {
            return;
        }
        $data = Db::one('SELECT state_data FROM users WHERE bot_id = ? AND user_id = ?', [$c->botId(), $c->uid]);
        $data = json_decode($data['state_data'] ?? '{}', true) ?: [];
        $msg = Ui::show($c, 'many_files', self::manyCaption($c, self::pendingCount($c)), self::manyButtons($c), true);
        $data['msg'] = $msg['message_id'] ?? null;
        unset($data['dirty']);
        $c->setState('many', $data);
    }

    /** One link from a set of Many Files uploads; free users' links are numbered #1, #2, … */
    private static function makePart(Ctx $c, array $files): array
    {
        $link = \Links::create($c, $c->uid, $files, true);
        if ($c->fileLimit() !== null) {
            $part = (int) Db::val(
                "UPDATE users SET state_data = jsonb_set(state_data, '{parts}', to_jsonb(COALESCE((state_data->>'parts')::int, 0) + 1))
                 WHERE bot_id = ? AND user_id = ? RETURNING (state_data->>'parts')::int",
                [$c->botId(), $c->uid]
            );
            $link['title'] = mb_substr($link['title'] . ' · #' . $part, 0, 120);
            Db::q('UPDATE links SET title = ? WHERE id = ?', [$link['title'], (int) $link['id']]);
        }
        return $link;
    }

    private static function pendingCount(Ctx $c): int
    {
        return (int) Db::val(
            "SELECT COUNT(*) FROM pending_files WHERE bot_id = ? AND user_id = ? AND bucket = 'many'",
            [$c->botId(), $c->uid]
        );
    }

    /**
     * Take the oldest $n Many Files uploads out of the buffer, in order.
     * One statement with SKIP LOCKED, so the worker and a button tap never take the same file.
     */
    private static function claim(Ctx $c, int $n): array
    {
        $rows = Db::all(
            "DELETE FROM pending_files WHERE id IN (
                 SELECT id FROM pending_files
                 WHERE bot_id = ? AND user_id = ? AND bucket = 'many'
                 ORDER BY message_id LIMIT " . max(1, $n) . " FOR UPDATE SKIP LOCKED
             ) RETURNING *",
            [$c->botId(), $c->uid]
        );
        usort($rows, fn($a, $b) => (int) $a['message_id'] <=> (int) $b['message_id']);
        return $rows;
    }

    /* ---------------- Anti-spam ---------------- */

    /**
     * Counts files per minute. Too many → cooldown with one warning; files sent during
     * the cooldown are ignored. The worker says when the user can send again.
     */
    private static function withinRate(Ctx $c): bool
    {
        if ($c->uid === null) {
            return true;
        }
        $row = Db::one(
            "UPDATE users SET
                 rate_count = CASE WHEN rate_start > now() - interval '1 minute' THEN rate_count + 1 ELSE 1 END,
                 rate_start = CASE WHEN rate_start > now() - interval '1 minute' THEN rate_start ELSE now() END
             WHERE bot_id = ? AND user_id = ? AND (cooldown_until IS NULL OR cooldown_until <= now())
             RETURNING rate_count",
            [$c->botId(), $c->uid]
        );
        if ($row === null) {
            return false;
        }
        if ((int) $row['rate_count'] <= App::ratePerMinute($c->fileLimit() === null)) {
            return true;
        }
        $seconds = App::cooldownSeconds();
        $started = Db::q(
            "UPDATE users SET cooldown_until = now() + make_interval(secs => ?), rate_count = 0, rate_start = NULL
             WHERE bot_id = ? AND user_id = ? AND (cooldown_until IS NULL OR cooldown_until <= now())",
            [$seconds, $c->botId(), $c->uid]
        )->rowCount();
        if ($started > 0) {
            $c->say('⏳ ' . $c->t('too_fast', ['s' => $seconds]));
        }
        return false;
    }

    /** Worker: the cooldown is over. */
    public static function cooldownOver(Ctx $c): void
    {
        $c->say('✅ ' . $c->t('ready_again'));
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
                . self::soFar($c, $count, self::existing((int) $link['id'])));
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
        $files = self::fit($c, self::pending($c, 'add:' . $linkId), $linkId);
        self::clearPending($c, 'add:' . $linkId);
        if ($files) {
            \Links::addFiles($c, $linkId, $c->uid, $files);
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
            'caption' => \Emoji::render($c, $caption),
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
