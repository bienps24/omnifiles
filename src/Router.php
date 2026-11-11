<?php
/**
 * Routes one Telegram update to the right handler.
 */
final class Router
{
    public const ALLOWED_UPDATES = ['message', 'callback_query', 'my_chat_member', 'pre_checkout_query', 'managed_bot'];

    public static function handle(array $bot, array $update): void
    {
        // A user created a managed bot through the main bot
        if (isset($update['managed_bot'])) {
            if ($bot['is_main']) {
                H\Clones::onManagedBot($bot, $update['managed_bot']);
            }
            return;
        }

        $c = new Ctx($bot, $update);

        if (isset($update['pre_checkout_query'])) {
            H\Pay::preCheckout($c, $update['pre_checkout_query']);
            return;
        }

        if (isset($update['my_chat_member'])) {
            $m = $update['my_chat_member'];
            if (($m['chat']['type'] ?? '') === 'private') {
                $blocked = in_array($m['new_chat_member']['status'] ?? '', ['kicked', 'left'], true);
                Db::q('UPDATE users SET blocked = ? WHERE bot_id = ? AND user_id = ?', [$blocked, $c->botId(), (int) $m['from']['id']]);
            }
            return;
        }

        $from = $c->message['from'] ?? $c->callback['from'] ?? null;
        $chatType = $c->message['chat']['type'] ?? $c->callback['message']['chat']['type'] ?? 'private';
        if ($from === null || $chatType !== 'private' || !empty($from['is_bot'])) {
            return;
        }

        $c->loadUser($from);

        if ($c->user['banned'] && !$c->isAdmin()) {
            $c->answer();
            if ($c->message) {
                $c->say($c->t('you_are_banned'));
            }
            return;
        }

        if ($bot['status'] !== 'active' && !$c->isAdmin()) {
            $c->answer();
            if ($c->message) {
                $c->say($c->t($bot['status'] === 'banned' ? 'bot_banned' : 'bot_paused'));
            }
            return;
        }

        try {
            if ($c->callback) {
                self::callback($c);
            } elseif ($c->message) {
                self::message($c);
            }
        } finally {
            if ($c->callback && empty($c->callback['answered'])) {
                $c->answer();
            }
        }
    }

    private static function message(Ctx $c): void
    {
        $m = $c->message;

        if (isset($m['successful_payment'])) {
            H\Pay::success($c, $m['successful_payment']);
            return;
        }

        $text = trim((string) ($m['text'] ?? ''));

        if ($text !== '' && $text[0] === '/') {
            [$command, $arg] = array_pad(explode(' ', $text, 2), 2, '');
            $command = strtolower(explode('@', $command)[0]);
            $arg = trim($arg);
            switch ($command) {
                case '/start':
                    $c->setState(null);
                    H\Start::start($c, $arg);
                    return;
                case '/help':
                    H\Start::help($c);
                    return;
                case '/links':
                    H\Links::list($c, 0);
                    return;
                case '/many':
                    H\Upload::manyStart($c);
                    return;
                case '/settings':
                    H\Limits::show($c, 'd');
                    return;
                case '/language':
                    H\Start::languagePicker($c, 's');
                    return;
                case '/clone':
                    if ($c->isMain) {
                        H\Clones::home($c);
                    }
                    return;
                case '/pro':
                    if ($c->isMain) {
                        H\Pay::proScreen($c);
                    }
                    return;
                case '/cancel':
                    $c->setState(null);
                    $c->say($c->t('cancelled'), Ui::menu($c));
                    return;
            }
            if ($c->isAdmin() && H\Admin::command($c, $command, $arg)) {
                return;
            }
        }

        // Waiting for a typed answer (rename, password, token, ...)
        if ($c->state() !== null && $text !== '' && self::stateText($c, $text)) {
            return;
        }

        // Bottom menu buttons
        $menu = [
            'menu_links' => fn() => H\Links::list($c, 0),
            'menu_many' => fn() => H\Upload::manyStart($c),
            'menu_clones' => fn() => $c->isMain ? H\Clones::home($c) : null,
            'menu_help' => fn() => H\Start::help($c),
            'menu_settings' => fn() => H\Limits::show($c, 'd'),
        ];
        foreach ($menu as $key => $run) {
            if ($text !== '' && in_array($text, Lang::allOf($key), true)) {
                if (in_array($c->state(), ['many', 'add'], true)) {
                    $c->setState(null);
                }
                $run();
                return;
            }
        }

        if (H\Upload::incoming($c)) {
            return;
        }

        if ($c->isMain && $c->uid === App::adminId() && H\Admin::emojiIds($c)) {
            return;
        }

        H\Start::welcome($c);
    }

    /** Text typed while a state is active. Returns true when handled. */
    private static function stateText(Ctx $c, string $text): bool
    {
        $state = $c->state();
        $data = $c->stateData();
        return match ($state) {
            'rename' => H\Links::renameText($c, (int) $data['link'], $text),
            'custom' => H\Links::customText($c, (int) $data['link'], $text),
            'look' => H\Links::lookText($c, (int) $data['link'], $text),
            'exp_date' => H\Limits::expDateText($c, (string) $data['scope'], $text),
            'people_num' => H\Limits::peopleText($c, (string) $data['scope'], $text),
            'password' => H\Limits::passwordText($c, (string) $data['scope'], $text),
            'link_password' => H\Deliver::passwordText($c, (int) $data['link'], $text),
            'token' => H\Clones::tokenText($c, $text),
            'admin_ad' => H\Admin::adText($c, $text),
            default => false,
        };
    }

    private static function callback(Ctx $c): void
    {
        $parts = explode(':', (string) ($c->callback['data'] ?? ''));
        $a = $parts[0];
        $p1 = $parts[1] ?? '';
        $p2 = $parts[2] ?? '';
        $id = (int) $p1;

        match ($a) {
            // start, language, help
            'lang' => H\Start::pickLanguage($c, $p1, $p2),
            'lg' => H\Start::languagePicker($c, 's'),
            'hp' => H\Start::help($c),
            'pv' => H\Start::privacy($c),

            // uploads
            'mf' => H\Upload::manyStart($c),
            'mfm' => H\Upload::manyMake($c),
            'mfc' => H\Upload::manyCancel($c),
            'af' => H\Upload::addStart($c, $id),
            'afd' => H\Upload::addDone($c, $id),

            // links
            'ml' => H\Links::list($c, $id),
            'lk' => H\Links::view($c, $id),
            'st' => H\Links::stats($c, $id),
            'cu' => H\Links::customPrompt($c, $id),
            'rn' => H\Links::renamePrompt($c, $id),
            'fl' => H\Links::files($c, $id),
            'fa' => H\Links::fileActions($c, $id),
            'fu' => H\Links::fileMove($c, $id, -1),
            'fd' => H\Links::fileMove($c, $id, 1),
            'fx' => H\Links::fileRemove($c, $id),
            'lo' => H\Links::look($c, $id),
            'los' => H\Links::lookPrompt($c, $id),
            'lor' => H\Links::lookRemove($c, $id),
            'pa' => H\Links::pause($c, $id),
            'dl' => H\Links::deleteAsk($c, $id),
            'dly' => H\Links::deleteYes($c, $id),

            // limits + default settings (scope "d" = defaults, number = link id)
            'lm' => H\Limits::show($c, $p1),
            'ex' => H\Limits::expires($c, $p1),
            'exs' => H\Limits::expiresSet($c, $p1, $p2),
            'exo' => H\Limits::expiresOther($c, $p1),
            'pp' => H\Limits::people($c, $p1),
            'pps' => H\Limits::peopleSet($c, $p1, $p2),
            'ppo' => H\Limits::peopleOther($c, $p1),
            'pw' => H\Limits::password($c, $p1),
            'pws' => H\Limits::passwordPrompt($c, $p1),
            'pwr' => H\Limits::passwordRemove($c, $p1),
            'ad' => H\Limits::autodelete($c, $p1),
            'ads' => H\Limits::autodeleteSet($c, $p1, (int) $p2),
            'rs' => H\Limits::toggle($c, $p1, 'resend', $p2),
            'pr' => H\Limits::toggle($c, $p1, 'protect', $p2),
            'ds' => H\Limits::show($c, 'd'),
            'dsr' => H\Limits::resetDefaults($c),
            'tz' => H\Limits::timezone($c),
            'tzs' => H\Limits::timezoneSet($c, $p1),
            'cx' => H\Limits::cancelPrompt($c, $p1),

            // delivery
            'get' => H\Deliver::resend($c, $id),
            'pwc' => H\Deliver::passwordCancel($c),
            'rp' => H\Deliver::report($c, $id),
            'rpr' => H\Deliver::reportReason($c, $id, $p2),

            // clones (main bot)
            'cb' => H\Clones::home($c),
            'ca' => H\Clones::add($c),
            'cra' => H\Clones::agree($c),
            'ct' => H\Clones::tokenPrompt($c),
            'cn' => H\Clones::home($c),
            'cv' => H\Clones::view($c, $id),
            'cp' => H\Clones::pause($c, $id),
            'cd' => H\Clones::deleteAsk($c, $id),
            'cdy' => H\Clones::deleteYes($c, $id),

            // stars
            'pro' => H\Pay::proScreen($c),
            'buy' => H\Pay::invoice($c, $p1),

            // admin
            'adm' => $c->isAdmin() ? H\Admin::callback($c, $p1, $p2) : null,

            default => null,
        };
    }
}
