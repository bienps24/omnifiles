<?php
/**
 * Translations. Each language is lang/<code>.php returning ['key' => 'text'].
 * Missing keys fall back to English. Add a language: create the file and list it in LANGS.
 */
final class Lang
{
    /** code => [flag, native name, default UTC offset in minutes] */
    public const LANGS = [
        'en' => ['🌐', 'English', 0],
        'tl' => ['🇵🇭', 'Filipino', 480],
    ];

    private static array $cache = [];

    public static function has(string $code): bool
    {
        return isset(self::LANGS[$code]) && is_file(App::root() . "/lang/$code.php");
    }

    private static function dict(string $code): array
    {
        if (!isset(self::$cache[$code])) {
            $file = App::root() . "/lang/$code.php";
            self::$cache[$code] = is_file($file) ? require $file : [];
        }
        return self::$cache[$code];
    }

    /** Translate. Values in $vars are inserted as-is: escape user text before passing it. */
    public static function t(string $code, string $key, array $vars = []): string
    {
        $text = self::dict($code)[$key] ?? self::dict('en')[$key] ?? $key;
        $pairs = [];
        foreach ($vars as $name => $value) {
            $pairs['{' . $name . '}'] = (string) $value;
        }
        return strtr($text, $pairs);
    }

    /** Pick a supported language from Telegram's language_code. */
    public static function guess(?string $languageCode): string
    {
        $code = strtolower(substr((string) $languageCode, 0, 2));
        if ($code === 'fil') {
            $code = 'tl';
        }
        return self::has($code) ? $code : 'en';
    }

    /** All translations of one key (used to match reply-keyboard buttons). */
    public static function allOf(string $key): array
    {
        $out = [];
        foreach (array_keys(self::LANGS) as $code) {
            if (self::has($code)) {
                $out[] = self::t($code, $key);
            }
        }
        return array_unique($out);
    }
}
