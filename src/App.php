<?php
/**
 * OmniFiles bootstrap: autoload + config.
 */

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/';
    $path = $base . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

final class App
{
    public const VERSION = '1.0.0';

    /** Read an environment variable with a default. */
    public static function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /** Telegram user id of the OmniFiles owner (admin). */
    public static function adminId(): int
    {
        return (int) self::env('ADMIN_ID', '0');
    }

    /** Public base URL, e.g. https://files.omnigate.info */
    public static function publicUrl(): string
    {
        return rtrim((string) self::env('PUBLIC_URL', ''), '/');
    }

    /** Channel people must join before cloning, e.g. @omnigatework */
    public static function newsChannel(): string
    {
        return (string) self::env('NEWS_CHANNEL', '@omnigatework');
    }

    /** Private channel id (e.g. -100123...) where the main bot keeps a copy of every file. */
    public static function backupChat(): ?int
    {
        $value = self::env('BACKUP_CHAT_ID');
        return $value === null ? null : (int) $value;
    }

    public static function proPrice(): int
    {
        return (int) self::env('PRO_PRICE_STARS', '300');
    }

    /**
     * Most files one link can hold for a free user. Pro has no limit.
     * In Many Files, every full set of this many files becomes its own link.
     */
    public static function freeFileLimit(): int
    {
        return max(1, (int) self::env('FREE_FILE_LIMIT', '10'));
    }

    /** Anti-spam: files a user may send per minute before the cooldown starts. */
    public static function ratePerMinute(bool $pro): int
    {
        return $pro
            ? (int) self::env('RATE_PRO_PER_MIN', '300')
            : (int) self::env('RATE_FREE_PER_MIN', '50');
    }

    /** Anti-spam: how long the cooldown lasts, in seconds. */
    public static function cooldownSeconds(): int
    {
        return max(10, (int) self::env('COOLDOWN_SECONDS', '60'));
    }

    /** Bot that collects Telegram Stars (it sends the invoices), e.g. omnigatepaybot */
    public static function payBot(): string
    {
        return ltrim((string) self::env('PAY_BOT', 'omnigatepaybot'), '@');
    }

    public static function slotPrice(): int
    {
        return (int) self::env('SLOT_PRICE_STARS', '999');
    }

    /**
     * Link for the "Contact admin" button, or null to hide it.
     * Needs CONTACT_USERNAME: tg://user?id links are refused by Telegram when the admin's privacy settings hide them.
     */
    public static function contactUrl(): ?string
    {
        $username = self::env('CONTACT_USERNAME');
        return $username === null ? null : 'https://t.me/' . ltrim($username, '@');
    }

    public static function log(string $message): void
    {
        error_log('[omnifiles] ' . $message);
    }
}
