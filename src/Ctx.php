<?php
/**
 * Everything about the update being handled: which bot, which user, which chat.
 */
final class Ctx
{
    public Tg $tg;
    public bool $isMain;
    public ?int $uid = null;
    public ?array $user = null;
    public bool $userIsNew = false;
    public ?int $chatId = null;
    public ?array $message = null;
    public ?array $callback = null;

    public function __construct(public array $bot, public array $update)
    {
        $this->tg = new Tg((string) Crypto::decrypt($bot['token_enc']));
        $this->isMain = (bool) $bot['is_main'];
        $this->message = $update['message'] ?? null;
        $this->callback = $update['callback_query'] ?? null;
        if ($this->message) {
            $this->chatId = (int) $this->message['chat']['id'];
        } elseif ($this->callback && isset($this->callback['message'])) {
            $this->chatId = (int) $this->callback['message']['chat']['id'];
        }
    }

    public function botId(): int
    {
        return (int) $this->bot['id'];
    }

    /** Create or refresh the user row. */
    public function loadUser(array $from): void
    {
        $this->uid = (int) $from['id'];
        $row = Db::one('SELECT * FROM users WHERE bot_id = ? AND user_id = ?', [$this->botId(), $this->uid]);
        if ($row === null) {
            $lang = Lang::guess($from['language_code'] ?? null);
            Db::q(
                'INSERT INTO users (bot_id, user_id, first_name, username, tz_offset) VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (bot_id, user_id) DO NOTHING',
                [$this->botId(), $this->uid, $from['first_name'] ?? '', $from['username'] ?? null, Lang::LANGS[$lang][2]]
            );
            $row = Db::one('SELECT * FROM users WHERE bot_id = ? AND user_id = ?', [$this->botId(), $this->uid]);
            $this->userIsNew = true;
        } else {
            Db::q(
                'UPDATE users SET first_name = ?, username = ?, last_seen = now(), blocked = FALSE WHERE bot_id = ? AND user_id = ?',
                [$from['first_name'] ?? '', $from['username'] ?? null, $this->botId(), $this->uid]
            );
        }
        $row['state_data'] = json_decode($row['state_data'] ?? '{}', true) ?: [];
        $row['defaults'] = json_decode($row['defaults'] ?? '{}', true) ?: [];
        $this->user = $row;
    }

    public function lang(): string
    {
        $lang = $this->user['lang'] ?? null;
        if ($lang && Lang::has($lang)) {
            return $lang;
        }
        $from = $this->message['from'] ?? $this->callback['from'] ?? [];
        return Lang::guess($from['language_code'] ?? null);
    }

    public function t(string $key, array $vars = []): string
    {
        return Lang::t($this->lang(), $key, $vars);
    }

    public function setState(?string $state, array $data = []): void
    {
        Db::q(
            'UPDATE users SET state = ?, state_data = ?::jsonb WHERE bot_id = ? AND user_id = ?',
            [$state, json_encode((object) $data), $this->botId(), $this->uid]
        );
        $this->user['state'] = $state;
        $this->user['state_data'] = $data;
    }

    public function state(): ?string
    {
        return $this->user['state'] ?? null;
    }

    public function stateData(): array
    {
        return $this->user['state_data'] ?? [];
    }

    /** Global account row (purchases). */
    public static function account(int $userId): array
    {
        Db::q('INSERT INTO accounts (user_id) VALUES (?) ON CONFLICT (user_id) DO NOTHING', [$userId]);
        return Db::one('SELECT * FROM accounts WHERE user_id = ?', [$userId]);
    }

    /** No ads? Main bot: the viewer bought Pro. Clone: the clone's owner bought Pro or an extra bot. */
    public function adFree(): bool
    {
        if ($this->isMain) {
            return $this->uid !== null && (bool) self::account($this->uid)['is_pro'];
        }
        return (bool) self::account((int) $this->bot['owner_id'])['is_pro'];
    }

    /**
     * Max files per link for the current user, or null for no limit.
     * No limit for Pro users, and on a clone whose owner is Pro.
     */
    public function fileLimit(): ?int
    {
        if ($this->uid !== null && (bool) self::account($this->uid)['is_pro']) {
            return null;
        }
        if (!$this->isMain && (bool) self::account((int) $this->bot['owner_id'])['is_pro']) {
            return null;
        }
        return App::freeFileLimit();
    }

    /** Bot messages can't be forwarded or saved, except for Pro (same rule as ads). */
    public function protect(): bool
    {
        return !$this->adFree();
    }

    /** Admin of this bot: the OmniFiles admin on the main bot, the owner on a clone. */
    public function isAdmin(): bool
    {
        if ($this->uid === null) {
            return false;
        }
        if ($this->isMain) {
            return $this->uid === App::adminId();
        }
        return $this->uid === (int) $this->bot['owner_id'] || $this->uid === App::adminId();
    }

    /** Format a timestamp in the user's time zone. */
    public function time(string|int|null $ts, string $format = 'Y-m-d H:i'): string
    {
        if ($ts === null) {
            return '';
        }
        $unix = is_int($ts) ? $ts : strtotime($ts);
        $offset = (int) ($this->user['tz_offset'] ?? 0);
        return gmdate($format, $unix + $offset * 60);
    }

    public function linkUrl(string $code): string
    {
        return 'https://t.me/' . $this->bot['username'] . '?start=' . $code;
    }

    /** Send a plain text message to the current chat. */
    public function say(string $html, ?array $markup = null): ?array
    {
        return $this->tg->get('sendMessage', [
            'chat_id' => $this->chatId,
            'text' => Emoji::render($this, $html),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'protect_content' => $this->protect(),
            'reply_markup' => $markup,
        ]);
    }

    /** Answer the current callback query (stops the loading spinner). */
    public function answer(?string $text = null, bool $alert = false): void
    {
        if ($this->callback) {
            $this->tg->call('answerCallbackQuery', [
                'callback_query_id' => $this->callback['id'],
                'text' => $text,
                'show_alert' => $alert,
            ]);
            $this->callback['answered'] = true;
        }
    }
}
