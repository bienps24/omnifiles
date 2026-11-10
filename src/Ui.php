<?php
/**
 * Screens: banner photo + caption + buttons, edited in place when possible.
 */
final class Ui
{
    /** Banner key => file in assets/banners */
    public const BANNERS = [
        'language' => '01_language',
        'welcome' => '02_welcome',
        'many_files' => '03_many_files',
        'link_ready' => '04_link_ready',
        'my_links' => '05_my_links',
        'stats' => '06_stats',
        'custom_link' => '07_custom_link',
        'limits' => '08_limits',
        'expires' => '09_expires',
        'people' => '10_people',
        'password' => '11_password',
        'password_needed' => '12_password_needed',
        'auto_delete' => '13_auto_delete',
        'settings' => '14_settings',
        'clone_bots' => '15_clone_bots',
        'clone_rules' => '16_clone_rules',
        'create_bot' => '17_create_bot',
        'connect_token' => '18_connect_token',
        'bot_ready' => '19_bot_ready',
        'your_bot' => '20_your_bot',
        'bot_limit' => '21_bot_limit',
        'pro' => '22_pro_no_ads',
        'help' => '23_help',
        'privacy' => '24_privacy',
        'files_sent' => '25_files_sent',
        'resend' => '26_resend',
        'paused' => '27_paused_expired',
        'deleted' => '28_deleted',
        'report' => '29_report',
    ];

    private static ?array $emoji = null;

    public static function esc(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Custom emoji id from config/emoji.json (empty = use the normal emoji). */
    private static function emojiId(string $key): ?string
    {
        if (self::$emoji === null) {
            $file = App::root() . '/config/emoji.json';
            self::$emoji = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        }
        $id = self::$emoji[$key] ?? '';
        return $id === '' ? null : (string) $id;
    }

    /** Emoji for captions: animated custom emoji on the main bot (owner has Premium), plain emoji elsewhere. */
    public static function e(Ctx $c, string $key, string $fallback): string
    {
        $id = self::emojiId($key);
        if ($c->isMain && $id !== null) {
            return '<tg-emoji emoji-id="' . $id . '">' . $fallback . '</tg-emoji>';
        }
        return $fallback;
    }

    /** Screen title line, e.g. "🔗 LINK READY". */
    public static function title(Ctx $c, string $emojiKey, string $fallback, string $text): string
    {
        return '<b>' . self::e($c, $emojiKey, $fallback) . ' ' . mb_strtoupper($text) . '</b>';
    }

    public static function quote(string $html): string
    {
        return '<blockquote>' . $html . '</blockquote>';
    }

    public static function num(int $n): string
    {
        return ['0️⃣', '1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣'][$n] ?? (string) $n;
    }

    /** Inline button with optional color style (success | danger | primary) and custom emoji icon. */
    public static function btn(Ctx $c, string $text, string $data, ?string $style = null, ?string $icon = null): array
    {
        $button = ['text' => $text, 'callback_data' => $data];
        return self::decorate($c, $button, $style, $icon);
    }

    public static function urlBtn(Ctx $c, string $text, string $url, ?string $style = null, ?string $icon = null): array
    {
        $button = ['text' => $text, 'url' => $url];
        return self::decorate($c, $button, $style, $icon);
    }

    public static function copyBtn(Ctx $c, string $text, string $copy, ?string $style = null): array
    {
        $button = ['text' => $text, 'copy_text' => ['text' => $copy]];
        return self::decorate($c, $button, $style, 'copy');
    }

    public static function back(Ctx $c, string $data): array
    {
        return self::btn($c, '← ' . $c->t('btn_back'), $data, null, 'back');
    }

    private static function decorate(Ctx $c, array $button, ?string $style, ?string $icon): array
    {
        if ($style !== null) {
            $button['style'] = $style;
        }
        if ($icon !== null && $c->isMain) {
            $id = self::emojiId($icon);
            if ($id !== null) {
                $button['icon_custom_emoji_id'] = $id;
            }
        }
        return $button;
    }

    /** Share link via Telegram's share sheet. */
    public static function shareUrl(string $url, string $text = ''): string
    {
        return 'https://t.me/share/url?url=' . rawurlencode($url) . ($text !== '' ? '&text=' . rawurlencode($text) : '');
    }

    /** Reply keyboard at the bottom of the chat. */
    public static function menu(Ctx $c): array
    {
        $rows = [
            [['text' => $c->t('menu_links')], ['text' => $c->t('menu_many')]],
        ];
        if ($c->isMain) {
            $rows[] = [['text' => $c->t('menu_clones')], ['text' => $c->t('menu_help')]];
        } else {
            $rows[] = [['text' => $c->t('menu_help')]];
        }
        $rows[] = [['text' => $c->t('menu_settings')]];
        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $c->t('placeholder'),
        ];
    }

    /** Photo value for a banner: cached file_id, or a file to upload. Returns [media, files]. */
    private static function bannerMedia(Ctx $c, string $key, string $field): array
    {
        $fileId = Db::val('SELECT file_id FROM banners WHERE bot_id = ? AND key = ?', [$c->botId(), $key]);
        if ($fileId) {
            return [$fileId, []];
        }
        $path = App::root() . '/assets/banners/' . (self::BANNERS[$key] ?? self::BANNERS['welcome']) . '.png';
        return ['attach://' . $field, [$field => $path]];
    }

    private static function rememberBanner(Ctx $c, string $key, ?array $message): void
    {
        if (!$message || empty($message['photo'])) {
            return;
        }
        $photo = end($message['photo']);
        Db::q(
            'INSERT INTO banners (bot_id, key, file_id) VALUES (?, ?, ?) ON CONFLICT (bot_id, key) DO NOTHING',
            [$c->botId(), $key, $photo['file_id']]
        );
    }

    /**
     * Show a screen. Inside a button press it edits the pressed message; otherwise it sends a new one.
     * $rows: inline keyboard rows, or ['reply' => replyKeyboard] to attach the bottom menu instead.
     */
    public static function show(Ctx $c, string $banner, string $caption, array $rows = [], bool $forceNew = false): ?array
    {
        $markup = isset($rows['reply']) ? $rows['reply'] : ['inline_keyboard' => array_values($rows)];
        $canEdit = !$forceNew && !isset($rows['reply']) && $c->callback && !empty($c->callback['message']['photo']);

        if ($canEdit) {
            [$media, $files] = self::bannerMedia($c, $banner, 'banner');
            $res = $c->tg->call('editMessageMedia', [
                'chat_id' => $c->chatId,
                'message_id' => $c->callback['message']['message_id'],
                'media' => ['type' => 'photo', 'media' => $media, 'caption' => $caption, 'parse_mode' => 'HTML'],
                'reply_markup' => $markup,
            ], $files);
            if ($res['ok']) {
                self::rememberBanner($c, $banner, is_array($res['result']) ? $res['result'] : null);
                return is_array($res['result']) ? $res['result'] : null;
            }
            if (str_contains((string) ($res['description'] ?? ''), 'not modified')) {
                return $c->callback['message'];
            }
        }

        [$media, $files] = self::bannerMedia($c, $banner, 'photo');
        $params = [
            'chat_id' => $c->chatId,
            'photo' => $files ? null : $media,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => $markup,
        ];
        $res = $c->tg->call('sendPhoto', $params, $files ? ['photo' => $files['photo']] : []);
        if ($res['ok']) {
            self::rememberBanner($c, $banner, $res['result']);
            return $res['result'];
        }
        return null;
    }

    /** Random active ad for free users: [caption line, button row] or null. */
    public static function ad(Ctx $c): ?array
    {
        if ($c->adFree()) {
            return null;
        }
        $ad = Db::one('SELECT * FROM ads WHERE active ORDER BY random() LIMIT 1');
        if ($ad === null) {
            $ad = [
                'text' => $c->t('ad_default_text'),
                'button_text' => $c->t('ad_default_button'),
                'url' => 'https://t.me/' . ltrim(App::newsChannel(), '@'),
            ];
        }
        return [
            "\n\n" . self::e($c, 'ad', '📢') . ' ' . self::esc($ad['text']),
            [self::urlBtn($c, $ad['button_text'], $ad['url'], null, 'ad')],
        ];
    }
}
