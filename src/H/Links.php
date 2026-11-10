<?php
namespace H;

use Ctx;
use Db;
use Ui;

/**
 * My Links and everything on one link: stats, custom link, rename, files, look, pause, delete.
 */
final class Links
{
    private const PER_PAGE = 8;

    private const KIND_ICON = [
        'photo' => '🖼', 'video' => '🎬', 'document' => '📄', 'audio' => '🎵',
        'voice' => '🎤', 'animation' => '🎞', 'video_note' => '⏺', 'sticker' => '🏷',
    ];

    public static function list(Ctx $c, int $page): void
    {
        $c->setState(null);
        $total = (int) Db::val('SELECT COUNT(*) FROM links WHERE bot_id = ? AND owner_id = ?', [$c->botId(), $c->uid]);
        $page = max(0, min($page, (int) floor(max(0, $total - 1) / self::PER_PAGE)));
        $links = Db::all(
            'SELECT id, title, paused, banned FROM links WHERE bot_id = ? AND owner_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
            [$c->botId(), $c->uid, self::PER_PAGE, $page * self::PER_PAGE]
        );

        $caption = Ui::title($c, 'links', '🗂', $c->t('t_my_links')) . "\n\n"
            . Ui::quote($total ? $c->t('you_have_links', ['n' => $total]) : $c->t('no_links_yet'));

        $rows = [];
        foreach ($links as $l) {
            $mark = $l['banned'] ? '🚫 ' : ($l['paused'] ? '⏸ ' : '📁 ');
            $rows[] = [Ui::btn($c, $mark . mb_strimwidth($l['title'], 0, 40, '…'), 'lk:' . $l['id'])];
        }
        $nav = [];
        if ($page > 0) {
            $nav[] = Ui::btn($c, '‹ ' . $c->t('btn_prev'), 'ml:' . ($page - 1));
        }
        if (($page + 1) * self::PER_PAGE < $total) {
            $nav[] = Ui::btn($c, $c->t('btn_next') . ' ›', 'ml:' . ($page + 1));
        }
        if ($nav) {
            $rows[] = $nav;
        }
        Ui::show($c, 'my_links', $caption, $rows, $c->callback === null);
    }

    public static function view(Ctx $c, int $id, bool $forceNew = false, string $notice = ''): void
    {
        $c->setState(null);
        $link = \Links::mine($c, $id);
        if (!$link) {
            $c->answer($c->t('link_not_found'), true);
            return;
        }
        $sum = \Links::summary($id);
        $people = (int) Db::val('SELECT COUNT(*) FROM opens WHERE link_id = ?', [$id]);
        $url = $c->linkUrl($link['code']);

        $info = '📚 ' . Ui::esc($link['title']) . "\n"
            . $c->t('n_files', ['n' => $sum['files']]) . ' · 👥 '
            . ($people ? $c->t('n_people_opened', ['n' => $people]) : $c->t('nobody_opened'));
        if ($link['banned']) {
            $info .= "\n🚫 " . $c->t('link_banned_owner');
        } elseif ($link['paused']) {
            $info .= "\n⏸ " . $c->t('link_paused_owner');
        }

        $caption = ($notice !== '' ? $notice . "\n\n" : '')
            . Ui::title($c, 'link', '🔗', $c->t('t_your_link')) . "\n\n"
            . Ui::quote($info) . "\n"
            . '🔗 ' . Ui::esc($url);

        $rows = [
            [
                Ui::urlBtn($c, '📤 ' . $c->t('btn_share'), Ui::shareUrl($url), 'success', 'share'),
                Ui::copyBtn($c, '📋 ' . $c->t('btn_copy'), $url, 'primary'),
            ],
            [
                Ui::btn($c, '📊 ' . $c->t('btn_stats'), 'st:' . $id, null, 'stats'),
                Ui::btn($c, '🔗 ' . $c->t('btn_custom_link'), 'cu:' . $id, null, 'link'),
            ],
            [
                Ui::btn($c, '✏️ ' . $c->t('btn_rename'), 'rn:' . $id, null, 'rename'),
                Ui::btn($c, '📁 ' . $c->t('btn_files'), 'fl:' . $id, null, 'files'),
            ],
            [
                Ui::btn($c, '🎚 ' . $c->t('btn_limits'), 'lm:' . $id, null, 'limits'),
                Ui::btn($c, '🎨 ' . $c->t('btn_look'), 'lo:' . $id, null, 'look'),
            ],
            [
                Ui::btn($c, $link['paused'] ? '▶️ ' . $c->t('btn_resume') : '⏸ ' . $c->t('btn_pause'), 'pa:' . $id, $link['paused'] ? 'success' : null, 'pause'),
                Ui::btn($c, '🗑 ' . $c->t('btn_delete'), 'dl:' . $id, 'danger', 'delete'),
            ],
            [Ui::back($c, 'ml:0')],
        ];
        Ui::show($c, 'my_links', $caption, $rows, $forceNew);
    }

    public static function stats(Ctx $c, int $id): void
    {
        $link = \Links::mine($c, $id);
        if (!$link) {
            return;
        }
        $s = \Links::stats($id);
        $sum = \Links::summary($id);
        $info = '📚 ' . Ui::esc($link['title']) . "\n\n"
            . '👁 ' . $c->t('stats_opens', ['n' => $s['opens'], 'today' => $s['today'], 'week' => $s['week']]) . "\n"
            . '👥 ' . $c->t('stats_people', ['n' => $s['people'], 'back' => $s['returned']]) . "\n"
            . '🆕 ' . $c->t('stats_new', ['bot' => '@' . Ui::esc($c->bot['username']), 'n' => $s['new']]) . "\n"
            . '🚫 ' . $c->t('stats_blocked', ['n' => $s['blocked']]) . "\n"
            . '📁 ' . $c->t('n_files', ['n' => $sum['files']]) . ' · 💾 ' . \Links::humanSize((int) $sum['size']) . "\n"
            . '📅 ' . $c->t('stats_made', ['date' => $c->time($link['created_at'])]);

        $caption = Ui::title($c, 'stats', '📊', $c->t('t_stats')) . "\n\n" . Ui::quote($info);
        if ($s['opens'] === 0) {
            $caption .= "\n" . $c->t('stats_empty');
        }
        $caption .= "\n\n<i>" . $c->t('times_in', ['tz' => Limits::tzLabel((int) $c->user['tz_offset'])]) . '</i>';

        Ui::show($c, 'stats', $caption, [
            [Ui::btn($c, '🔄 ' . $c->t('btn_refresh'), 'st:' . $id, null, 'refresh')],
            [Ui::back($c, 'lk:' . $id)],
        ]);
    }

    /* ---------------- Custom link ---------------- */

    public static function customPrompt(Ctx $c, int $id, string $error = ''): void
    {
        $link = \Links::mine($c, $id);
        if (!$link) {
            return;
        }
        $c->setState('custom', ['link' => $id]);
        $caption = ($error !== '' ? '⚠️ ' . $error . "\n\n" : '')
            . Ui::title($c, 'link', '🔗', $c->t('t_custom_link')) . "\n\n"
            . Ui::quote($c->t('custom_text', ['title' => '<b>' . Ui::esc($link['title']) . '</b>'])) . "\n"
            . '🔗 ' . $c->t('now') . ': ' . Ui::esc($c->linkUrl($link['code']));
        Ui::show($c, 'custom_link', $caption, [[Ui::btn($c, '✖️ ' . $c->t('btn_cancel'), 'lk:' . $id, null, 'cancel')]], $error !== '');
    }

    public static function customText(Ctx $c, int $id, string $text): bool
    {
        $code = strtolower(trim($text));
        if (!preg_match('/^[a-z0-9_-]{3,32}$/', $code)) {
            self::customPrompt($c, $id, $c->t('custom_bad'));
            return true;
        }
        $taken = Db::val('SELECT 1 FROM links WHERE bot_id = ? AND code = ? AND id <> ?', [$c->botId(), $code, $id]);
        if ($taken) {
            self::customPrompt($c, $id, $c->t('custom_taken'));
            return true;
        }
        Db::q('UPDATE links SET code = ? WHERE id = ? AND owner_id = ?', [$code, $id, $c->uid]);
        self::view($c, $id, true, '✅ ' . $c->t('custom_saved'));
        return true;
    }

    /* ---------------- Rename ---------------- */

    public static function renamePrompt(Ctx $c, int $id): void
    {
        $link = \Links::mine($c, $id);
        if (!$link) {
            return;
        }
        $c->setState('rename', ['link' => $id]);
        $caption = Ui::title($c, 'rename', '✏️', $c->t('t_rename')) . "\n\n"
            . Ui::quote($c->t('rename_text', ['title' => '<b>' . Ui::esc($link['title']) . '</b>']));
        Ui::show($c, 'my_links', $caption, [[Ui::btn($c, '✖️ ' . $c->t('btn_cancel'), 'lk:' . $id, null, 'cancel')]]);
    }

    public static function renameText(Ctx $c, int $id, string $text): bool
    {
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 120);
        if ($title === '') {
            return true;
        }
        Db::q('UPDATE links SET title = ? WHERE id = ? AND owner_id = ?', [$title, $id, $c->uid]);
        self::view($c, $id, true, '✅ ' . $c->t('renamed'));
        return true;
    }

    /* ---------------- Files ---------------- */

    public static function files(Ctx $c, int $id): void
    {
        $link = \Links::mine($c, $id);
        if (!$link) {
            return;
        }
        $files = Db::all('SELECT id, kind FROM files WHERE link_id = ? ORDER BY position', [$id]);
        $caption = Ui::title($c, 'files', '📁', $c->t('t_files')) . "\n\n"
            . Ui::quote(Ui::esc($link['title']) . ' · ' . $c->t('n_files', ['n' => count($files)])) . "\n"
            . $c->t('files_hint');

        $rows = [[Ui::btn($c, '➕ ' . $c->t('btn_add_files'), 'af:' . $id, 'primary', 'add')]];
        foreach ($files as $i => $f) {
            $rows[] = [Ui::btn($c, ($i + 1) . '. ' . (self::KIND_ICON[$f['kind']] ?? '📄') . ' ' . $c->t('kind_' . $f['kind']), 'fa:' . $f['id'])];
        }
        $rows[] = [Ui::back($c, 'lk:' . $id)];
        Ui::show($c, 'my_links', $caption, $rows);
    }

    private static function myFile(Ctx $c, int $fileId): ?array
    {
        return Db::one(
            'SELECT f.*, l.title FROM files f JOIN links l ON l.id = f.link_id WHERE f.id = ? AND l.bot_id = ? AND l.owner_id = ?',
            [$fileId, $c->botId(), $c->uid]
        );
    }

    public static function fileActions(Ctx $c, int $fileId): void
    {
        $f = self::myFile($c, $fileId);
        if (!$f) {
            return;
        }
        $index = (int) Db::val('SELECT COUNT(*) FROM files WHERE link_id = ? AND position <= ?', [$f['link_id'], $f['position']]);
        $caption = Ui::title($c, 'files', '📁', $c->t('t_files')) . "\n\n"
            . Ui::quote(
                $index . '. ' . (self::KIND_ICON[$f['kind']] ?? '📄') . ' ' . $c->t('kind_' . $f['kind'])
                . ($f['size'] ? ' · ' . \Links::humanSize((int) $f['size']) : '')
                . ($f['caption'] ? "\n" . Ui::esc(mb_strimwidth($f['caption'], 0, 200, '…')) : '')
            );
        Ui::show($c, 'my_links', $caption, [
            [
                Ui::btn($c, '⬆️ ' . $c->t('btn_up'), 'fu:' . $fileId),
                Ui::btn($c, '⬇️ ' . $c->t('btn_down'), 'fd:' . $fileId),
            ],
            [Ui::btn($c, '🗑 ' . $c->t('btn_remove'), 'fx:' . $fileId, 'danger', 'delete')],
            [Ui::back($c, 'fl:' . $f['link_id'])],
        ]);
    }

    public static function fileMove(Ctx $c, int $fileId, int $dir): void
    {
        $f = self::myFile($c, $fileId);
        if (!$f) {
            return;
        }
        $op = $dir < 0 ? '<' : '>';
        $order = $dir < 0 ? 'DESC' : 'ASC';
        $other = Db::one(
            "SELECT id, position FROM files WHERE link_id = ? AND position $op ? ORDER BY position $order LIMIT 1",
            [$f['link_id'], $f['position']]
        );
        if ($other) {
            Db::q('UPDATE files SET position = ? WHERE id = ?', [(int) $other['position'], $fileId]);
            Db::q('UPDATE files SET position = ? WHERE id = ?', [(int) $f['position'], (int) $other['id']]);
        }
        self::files($c, (int) $f['link_id']);
    }

    public static function fileRemove(Ctx $c, int $fileId): void
    {
        $f = self::myFile($c, $fileId);
        if (!$f) {
            return;
        }
        $count = (int) Db::val('SELECT COUNT(*) FROM files WHERE link_id = ?', [$f['link_id']]);
        if ($count <= 1) {
            $c->answer($c->t('last_file'), true);
            return;
        }
        Db::q('DELETE FROM files WHERE id = ?', [$fileId]);
        self::files($c, (int) $f['link_id']);
    }

    /* ---------------- Look (message shown before the files) ---------------- */

    public static function look(Ctx $c, int $id): void
    {
        $link = \Links::mine($c, $id);
        if (!$link) {
            return;
        }
        $c->setState(null);
        $now = $link['intro'] ? Ui::esc($link['intro']) : '<i>' . $c->t('look_none') . '</i>';
        $caption = Ui::title($c, 'look', '🎨', $c->t('t_look')) . "\n\n"
            . Ui::quote($c->t('look_text')) . "\n"
            . $c->t('now') . ":\n" . $now;
        $rows = [[Ui::btn($c, '✏️ ' . $c->t('btn_set_message'), 'los:' . $id, 'primary')]];
        if ($link['intro']) {
            $rows[] = [Ui::btn($c, '🗑 ' . $c->t('btn_remove'), 'lor:' . $id, 'danger')];
        }
        $rows[] = [Ui::back($c, 'lk:' . $id)];
        Ui::show($c, 'settings', $caption, $rows);
    }

    public static function lookPrompt(Ctx $c, int $id): void
    {
        if (!\Links::mine($c, $id)) {
            return;
        }
        $c->setState('look', ['link' => $id]);
        $caption = Ui::title($c, 'look', '🎨', $c->t('t_look')) . "\n\n" . Ui::quote($c->t('look_prompt'));
        Ui::show($c, 'settings', $caption, [[Ui::btn($c, '✖️ ' . $c->t('btn_cancel'), 'lo:' . $id, null, 'cancel')]]);
    }

    public static function lookText(Ctx $c, int $id, string $text): bool
    {
        Db::q('UPDATE links SET intro = ? WHERE id = ? AND owner_id = ?', [mb_substr($text, 0, 1000), $id, $c->uid]);
        self::view($c, $id, true, '✅ ' . $c->t('look_saved'));
        return true;
    }

    public static function lookRemove(Ctx $c, int $id): void
    {
        Db::q('UPDATE links SET intro = NULL WHERE id = ? AND owner_id = ?', [$id, $c->uid]);
        self::look($c, $id);
    }

    /* ---------------- Pause / delete ---------------- */

    public static function pause(Ctx $c, int $id): void
    {
        Db::q('UPDATE links SET paused = NOT paused WHERE id = ? AND bot_id = ? AND owner_id = ?', [$id, $c->botId(), $c->uid]);
        self::view($c, $id);
    }

    public static function deleteAsk(Ctx $c, int $id): void
    {
        $link = \Links::mine($c, $id);
        if (!$link) {
            return;
        }
        $caption = Ui::title($c, 'delete', '🗑', $c->t('t_delete')) . "\n\n"
            . Ui::quote($c->t('delete_text', ['title' => '<b>' . Ui::esc($link['title']) . '</b>']));
        Ui::show($c, 'deleted', $caption, [
            [Ui::btn($c, '🗑 ' . $c->t('btn_delete_yes'), 'dly:' . $id, 'danger', 'delete')],
            [Ui::back($c, 'lk:' . $id)],
        ]);
    }

    public static function deleteYes(Ctx $c, int $id): void
    {
        Db::q('DELETE FROM links WHERE id = ? AND bot_id = ? AND owner_id = ?', [$id, $c->botId(), $c->uid]);
        $c->answer($c->t('deleted'));
        self::list($c, 0);
    }
}
