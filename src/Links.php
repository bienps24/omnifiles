<?php
/**
 * Link model helpers.
 */
final class Links
{
    /** Settings every new link gets unless the user changed their defaults. */
    public const DEFAULTS = [
        'expires' => null,        // seconds after creation, null = never
        'people' => null,         // max people, null = anyone
        'password_hash' => null,
        'autodelete' => 300,      // 60 or 300 seconds
        'resend' => false,
        'protect' => false,
    ];

    public const AUTODELETE_CHOICES = [60, 300];

    public static function defaults(array $user): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($user['defaults'] ?? [], self::DEFAULTS));
    }

    /** Random 10-character code, lower case + digits. */
    public static function newCode(int $botId): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyz23456789';
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Db::val('SELECT 1 FROM links WHERE bot_id = ? AND code = ?', [$botId, $code]));
        return $code;
    }

    /** Pull the file out of a message: [kind, file_id, file_unique_id, size] or null. */
    public static function fileOf(array $message): ?array
    {
        if (!empty($message['photo'])) {
            $p = end($message['photo']);
            return ['photo', $p['file_id'], $p['file_unique_id'] ?? null, (int) ($p['file_size'] ?? 0)];
        }
        foreach (['video', 'document', 'audio', 'voice', 'animation', 'video_note', 'sticker'] as $kind) {
            if (!empty($message[$kind])) {
                $f = $message[$kind];
                return [$kind, $f['file_id'], $f['file_unique_id'] ?? null, (int) ($f['file_size'] ?? 0)];
            }
        }
        return null;
    }

    /**
     * Create a link from pending/incoming files.
     * $files: list of ['message_id', 'kind', 'file_id', 'file_unique_id', 'caption', 'size', 'file_name'?]
     */
    public static function create(Ctx $c, int $ownerId, array $files, bool $many): array
    {
        $owner = Db::one('SELECT * FROM users WHERE bot_id = ? AND user_id = ?', [$c->botId(), $ownerId]);
        $owner['defaults'] = json_decode($owner['defaults'] ?? '{}', true) ?: [];
        $d = self::defaults($owner);
        $offset = (int) ($owner['tz_offset'] ?? 0);
        $stamp = gmdate('Y-m-d H:i', time() + $offset * 60);

        if ($many || count($files) > 1) {
            $title = Lang::t($owner['lang'] ?: 'en', 'title_many') . ' · ' . $stamp;
        } else {
            $f = $files[0];
            $name = $f['file_name'] ?? null;
            $title = ($name ?: Lang::t($owner['lang'] ?: 'en', 'kind_' . $f['kind'])) . ' · ' . $stamp;
        }

        $link = Db::one(
            'INSERT INTO links (bot_id, owner_id, code, title, expires_at, max_people, password_hash, autodelete_sec, resend, protect)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING *',
            [
                $c->botId(),
                $ownerId,
                self::newCode($c->botId()),
                mb_substr($title, 0, 120),
                $d['expires'] ? gmdate('c', time() + (int) $d['expires']) : null,
                $d['people'] ? (int) $d['people'] : null,
                $d['password_hash'],
                in_array((int) $d['autodelete'], self::AUTODELETE_CHOICES, true) ? (int) $d['autodelete'] : 300,
                (bool) $d['resend'],
                (bool) $d['protect'],
            ]
        );
        self::addFiles($c, (int) $link['id'], $ownerId, $files);
        return $link;
    }

    /** Append files to a link, and keep a copy in the backup channel (main bot only). */
    public static function addFiles(Ctx $c, int $linkId, int $ownerId, array $files): void
    {
        $position = (int) Db::val('SELECT COALESCE(MAX(position), 0) FROM files WHERE link_id = ?', [$linkId]);

        $backupIds = [];
        $backup = App::backupChat();
        if ($c->isMain && $backup !== null && $files) {
            $ids = array_map(fn($f) => (int) $f['message_id'], $files);
            $copied = $c->tg->get('copyMessages', [
                'chat_id' => $backup,
                'from_chat_id' => $ownerId,
                'message_ids' => $ids,
            ]);
            if (is_array($copied)) {
                foreach ($copied as $i => $m) {
                    $backupIds[$i] = (int) $m['message_id'];
                }
            }
        }

        foreach (array_values($files) as $i => $f) {
            $position++;
            Db::q(
                'INSERT INTO files (link_id, position, kind, file_id, file_unique_id, caption, size, backup_msg_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$linkId, $position, $f['kind'], $f['file_id'], $f['file_unique_id'] ?? null, $f['caption'] ?? null, (int) ($f['size'] ?? 0), $backupIds[$i] ?? null]
            );
        }
    }

    public static function byCode(int $botId, string $code): ?array
    {
        return Db::one('SELECT * FROM links WHERE bot_id = ? AND code = ?', [$botId, strtolower($code)]);
    }

    /** A link that belongs to the current user (or null). */
    public static function mine(Ctx $c, int $id): ?array
    {
        return Db::one('SELECT * FROM links WHERE id = ? AND bot_id = ? AND owner_id = ?', [$id, $c->botId(), $c->uid]);
    }

    public static function summary(int $linkId): array
    {
        return Db::one(
            'SELECT COUNT(*)::int AS files, COALESCE(SUM(size), 0)::bigint AS size FROM files WHERE link_id = ?',
            [$linkId]
        );
    }

    public static function stats(int $linkId): array
    {
        $link = Db::one('SELECT * FROM links WHERE id = ?', [$linkId]);
        return [
            'opens' => (int) Db::val('SELECT COUNT(*) FROM open_events WHERE link_id = ?', [$linkId]),
            'today' => (int) Db::val("SELECT COUNT(*) FROM open_events WHERE link_id = ? AND at >= date_trunc('day', now())", [$linkId]),
            'week' => (int) Db::val("SELECT COUNT(*) FROM open_events WHERE link_id = ? AND at >= now() - interval '7 days'", [$linkId]),
            'people' => (int) Db::val('SELECT COUNT(*) FROM opens WHERE link_id = ?', [$linkId]),
            'returned' => (int) Db::val('SELECT COUNT(*) FROM opens WHERE link_id = ? AND times > 1', [$linkId]),
            'new' => (int) Db::val('SELECT COUNT(*) FROM opens WHERE link_id = ? AND was_new', [$linkId]),
            'blocked' => (int) Db::val(
                'SELECT COUNT(*) FROM opens o JOIN users u ON u.user_id = o.user_id AND u.bot_id = ? WHERE o.link_id = ? AND u.blocked',
                [(int) $link['bot_id'], $linkId]
            ),
        ];
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $bytes : number_format($size, 1)) . ' ' . $units[$i];
    }
}
