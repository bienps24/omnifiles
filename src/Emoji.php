<?php
/**
 * Premium (animated) emoji, matched by the emoji character itself.
 *
 * The admin maps a normal emoji to a Premium one once (/setemoji). From then on every
 * place that shows that emoji on the main bot shows the Premium one:
 *   - captions and messages: wrapped in <tg-emoji emoji-id="...">
 *   - inline and bottom-menu buttons: the leading emoji becomes the button icon
 *     (icon_custom_emoji_id) and is removed from the text, so it never shows twice.
 * Clones keep normal emoji: custom emoji need the bot owner's Premium.
 */
final class Emoji
{
    /** One emoji: flag, keycap, or pictograph (with ZWJ sequences). */
    public const RE = '/(?:[\x{1F1E6}-\x{1F1FF}]{2}|[0-9#*]\x{FE0F}?\x{20E3}|\p{Extended_Pictographic}\x{FE0F}?(?:\x{200D}\p{Extended_Pictographic}\x{FE0F}?)*)/u';

    /** Old key names (first version of /setemoji) → the emoji they stood for. */
    private const KEY_CHAR = [
        'ad' => '📢', 'add' => '➕', 'bolt' => '⚡', 'cancel' => '✖', 'clock' => '⏲', 'clone' => '🤖',
        'contact' => '🎧', 'copy' => '📋', 'delete' => '🗑', 'done' => '✅', 'expires' => '⏳', 'files' => '📁',
        'help' => '❓', 'info' => 'ℹ', 'language' => '🌐', 'limits' => '🎚', 'link' => '🔗', 'links' => '🗂',
        'look' => '🎨', 'many' => '📚', 'party' => '🎉', 'password' => '🔑', 'pause' => '⏸', 'people' => '👥',
        'privacy' => '🔒', 'pro' => '⭐', 'refresh' => '🔄', 'rename' => '✏', 'report' => '⚠', 'reset' => '↩',
        'resend' => '🔁', 'settings' => '⚙', 'share' => '📤', 'shield' => '🛡', 'star' => '⭐', 'stats' => '📊',
    ];

    private static ?array $map = null;
    private static int $loadedAt = 0;

    /** Emoji without the FE0F variation selector, used as the lookup key. */
    public static function norm(string $emoji): string
    {
        return str_replace("\u{FE0F}", '', $emoji);
    }

    /** normal emoji → Premium emoji id. Reloaded every minute (the worker runs for days). */
    private static function map(): array
    {
        if (self::$map === null || time() - self::$loadedAt > 60) {
            $map = [];
            try {
                foreach (Db::all("SELECT key, value FROM settings WHERE key LIKE 'emoji:%' OR key LIKE 'emojichar:%'") as $row) {
                    if (str_starts_with($row['key'], 'emojichar:')) {
                        $map[substr($row['key'], 10)] = $row['value'];
                    } else {
                        $char = self::KEY_CHAR[substr($row['key'], 6)] ?? null;
                        if ($char !== null) {
                            $map[$char] ??= $row['value'];
                        }
                    }
                }
            } catch (Throwable $e) {
                // settings table not created yet
            }
            self::$map = $map;
            self::$loadedAt = time();
        }
        return self::$map;
    }

    public static function reload(): void
    {
        self::$map = null;
    }

    public static function id(string $emoji): ?string
    {
        return self::map()[self::norm($emoji)] ?? null;
    }

    public static function save(string $emoji, string $id): void
    {
        Db::q(
            'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
            ['emojichar:' . self::norm($emoji), $id]
        );
        self::reload();
    }

    public static function delete(string $emoji): void
    {
        $norm = self::norm($emoji);
        Db::q("DELETE FROM settings WHERE key = ?", ['emojichar:' . $norm]);
        foreach (self::KEY_CHAR as $key => $char) {
            if ($char === $norm) {
                Db::q("DELETE FROM settings WHERE key = ?", ['emoji:' . $key]);
            }
        }
        self::reload();
    }

    /** Turn every mapped emoji in an HTML text into its Premium version (main bot only). */
    public static function render(Ctx $c, string $html): string
    {
        if (!$c->isMain || !self::map()) {
            return $html;
        }
        $parts = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $skip = 0; // inside <code>, <pre> or an existing <tg-emoji>
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] === '<') {
                if (preg_match('#^<(code|pre|tg-emoji)\b#i', $part)) {
                    $skip++;
                } elseif (preg_match('#^</(code|pre|tg-emoji)>#i', $part)) {
                    $skip = max(0, $skip - 1);
                }
                continue;
            }
            if ($skip > 0) {
                continue;
            }
            $parts[$i] = preg_replace_callback(self::RE, function (array $m): string {
                $id = self::id($m[0]);
                return $id === null ? $m[0] : '<tg-emoji emoji-id="' . $id . '">' . $m[0] . '</tg-emoji>';
            }, $part);
        }
        return implode('', $parts);
    }

    /** [emoji, rest of the text] when the text starts with an emoji, else null. */
    private static function leading(string $text): ?array
    {
        if (preg_match('/^\s*(' . substr(self::RE, 1, -2) . ')\s*/u', $text, $m)) {
            return [$m[1], (string) substr($text, strlen($m[0]))];
        }
        return null;
    }

    /** Text without its leading emoji (to match bottom-menu buttons with or without icon). */
    public static function strip(string $text): string
    {
        $lead = self::leading($text);
        return trim($lead === null ? $text : $lead[1]);
    }

    /** Button (inline or bottom menu): leading emoji → Premium icon, removed from the text. */
    public static function button(Ctx $c, array $button): array
    {
        if (!$c->isMain || !isset($button['text'])) {
            return $button;
        }
        $lead = self::leading($button['text']);
        if ($lead === null) {
            return $button;
        }
        $id = self::id($lead[0]);
        if ($id === null || $lead[1] === '') {
            return $button;
        }
        $button['icon_custom_emoji_id'] = $id;
        $button['text'] = $lead[1];
        return $button;
    }

    /** Every emoji the bot uses (texts in lang/ and src/), as [normalized => as written]. */
    public static function used(): array
    {
        $files = array_merge(glob(App::root() . '/lang/*.php'), glob(App::root() . '/src/*.php'), glob(App::root() . '/src/H/*.php'));
        $found = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '/Emoji.php')) {
                continue;
            }
            preg_match_all(self::RE, (string) file_get_contents($file), $m);
            foreach ($m[0] as $emoji) {
                $found[self::norm($emoji)] ??= $emoji;
            }
        }
        return $found;
    }
}
