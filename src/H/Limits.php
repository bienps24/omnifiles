<?php
namespace H;

use Ctx;
use Db;
use Lang;
use Ui;

/**
 * Limits of one link, and Default settings (scope "d") that new links start with.
 */
final class Limits
{
    public const EXPIRE_CHOICES = [3600, 86400, 604800, 2592000];
    public const PEOPLE_CHOICES = [1, 10, 100, 1000];
    public const TZ_CHOICES = [-480, -300, -180, 0, 60, 180, 330, 420, 480, 540];

    /** Current values for a scope, or null if the link is not yours. */
    private static function load(Ctx $c, string $scope): ?array
    {
        if ($scope === 'd') {
            $d = \Links::defaults($c->user);
            return [
                'd' => true,
                'link' => null,
                'title' => null,
                'expires' => $d['expires'] ? (int) $d['expires'] : null,
                'people' => $d['people'] ? (int) $d['people'] : null,
                'password' => $d['password_hash'] !== null,
                'autodelete' => (int) $d['autodelete'],
                'resend' => (bool) $d['resend'],
                'protect' => (bool) $d['protect'],
            ];
        }
        $link = \Links::mine($c, (int) $scope);
        if (!$link) {
            return null;
        }
        return [
            'd' => false,
            'link' => $link,
            'title' => $link['title'],
            'expires' => $link['expires_at'] ? strtotime($link['expires_at']) : null,
            'people' => $link['max_people'] !== null ? (int) $link['max_people'] : null,
            'password' => $link['password_hash'] !== null,
            'autodelete' => (int) $link['autodelete_sec'],
            'resend' => (bool) $link['resend'],
            'protect' => (bool) $link['protect'],
        ];
    }

    /** Save one setting. $field: expires | people | password_hash | autodelete | resend | protect */
    private static function save(Ctx $c, string $scope, string $field, mixed $value): void
    {
        if ($scope === 'd') {
            $defaults = $c->user['defaults'];
            $defaults[$field] = $value;
            Db::q('UPDATE users SET defaults = ?::jsonb WHERE bot_id = ? AND user_id = ?', [json_encode($defaults), $c->botId(), $c->uid]);
            $c->user['defaults'] = $defaults;
            return;
        }
        $column = [
            'expires' => 'expires_at',
            'people' => 'max_people',
            'password_hash' => 'password_hash',
            'autodelete' => 'autodelete_sec',
            'resend' => 'resend',
            'protect' => 'protect',
        ][$field];
        if ($field === 'expires') {
            $value = $value === null ? null : gmdate('c', (int) $value);
        }
        Db::q("UPDATE links SET $column = ? WHERE id = ? AND bot_id = ? AND owner_id = ?", [$value, (int) $scope, $c->botId(), $c->uid]);
    }

    /* ---------------- labels ---------------- */

    public static function durationLabel(Ctx $c, int $seconds): string
    {
        return match ($seconds) {
            3600 => $c->t('dur_1h'),
            86400 => $c->t('dur_1d'),
            604800 => $c->t('dur_7d'),
            2592000 => $c->t('dur_30d'),
            default => $c->t('dur_custom', ['n' => round($seconds / 86400, 1)]),
        };
    }

    private static function expiresLabel(Ctx $c, array $v): string
    {
        if ($v['expires'] === null) {
            return $c->t('never');
        }
        if ($v['d']) {
            return self::durationLabel($c, $v['expires']);
        }
        if ($v['expires'] < time()) {
            return $c->t('expired');
        }
        return $c->time($v['expires'], 'Y-m-d H:i');
    }

    private static function peopleLabel(Ctx $c, ?int $n): string
    {
        if ($n === null) {
            return $c->t('anyone');
        }
        return $n === 1 ? $c->t('one_person') : $c->t('n_people', ['n' => $n]);
    }

    public static function minutesLabel(Ctx $c, int $seconds): string
    {
        return $c->t('n_min', ['n' => intdiv($seconds, 60)]);
    }

    public static function tzLabel(int $offset): string
    {
        $sign = $offset < 0 ? '−' : '+';
        $abs = abs($offset);
        $h = intdiv($abs, 60);
        $m = $abs % 60;
        return 'UTC' . $sign . $h . ($m ? ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
    }

    private static function onOff(Ctx $c, bool $on): string
    {
        return $on ? $c->t('on') : $c->t('off');
    }

    private static function header(Ctx $c, array $v): string
    {
        return '<b>' . Ui::esc($v['title']) . '</b>';
    }

    /* ---------------- main screen ---------------- */

    public static function show(Ctx $c, string $scope, bool $forceNew = false, string $notice = ''): void
    {
        $c->setState(null);
        $v = self::load($c, $scope);
        if ($v === null) {
            return;
        }

        if ($v['d']) {
            $caption = Ui::title($c, 'settings', '⚙️', $c->t('t_defaults')) . "\n\n"
                . Ui::quote($c->t('defaults_text')) . "\n"
                . '⏲ ' . $c->t('autodelete_note', ['time' => self::minutesLabel($c, $v['autodelete'])]);
            $banner = 'settings';
        } else {
            $caption = Ui::title($c, 'limits', '🎚', $c->t('t_limits')) . "\n\n"
                . Ui::quote(self::header($c, $v) . "\n" . $c->t('tap_setting'));
            $banner = 'limits';
        }
        if ($v['protect']) {
            $caption .= "\n\n🛡 " . $c->t('protect_note');
        }
        if ($notice !== '') {
            $caption = $notice . "\n\n" . $caption;
        }

        $s = $scope;
        $rows = [];
        if ($v['d']) {
            $lang = $c->lang();
            $rows[] = [Ui::btn($c, '🌐 ' . $c->t('set_language') . ': ' . Lang::LANGS[$lang][1], 'lg', null, 'language')];
            $tz = self::tzLabel((int) $c->user['tz_offset']) . ($c->user['tz_auto'] ? ' (' . $c->t('automatic') . ')' : '');
            $rows[] = [Ui::btn($c, '🕐 ' . $c->t('set_timezone') . ': ' . $tz, 'tz', null, 'clock')];
        }
        $rows[] = [Ui::btn($c, '⏳ ' . $c->t('set_expires') . ': ' . self::expiresLabel($c, $v), "ex:$s", null, 'expires')];
        $rows[] = [Ui::btn($c, '👥 ' . $c->t('set_people') . ': ' . self::peopleLabel($c, $v['people']), "pp:$s", null, 'people')];
        $rows[] = [Ui::btn($c, '🔑 ' . $c->t('set_password') . ': ' . self::onOff($c, $v['password']), "pw:$s", $v['password'] ? 'success' : null, 'password')];
        $rows[] = [Ui::btn($c, '⏲ ' . $c->t('set_autodelete') . ': ' . self::minutesLabel($c, $v['autodelete']), "ad:$s", null, 'clock')];
        $rows[] = [Ui::btn($c, '🔁 ' . $c->t('set_resend') . ': ' . self::onOff($c, $v['resend']), "rs:$s:lm", $v['resend'] ? 'success' : null, 'resend')];
        $rows[] = [Ui::btn($c, '🛡 ' . $c->t('set_protect') . ': ' . self::onOff($c, $v['protect']), "pr:$s:lm", $v['protect'] ? 'success' : null, 'shield')];
        if ($v['d']) {
            $rows[] = [Ui::btn($c, '↩️ ' . $c->t('btn_reset'), 'dsr', 'danger', 'reset')];
        } else {
            $rows[] = [Ui::back($c, 'lk:' . $scope)];
        }
        Ui::show($c, $banner, $caption, $rows, $forceNew || $c->callback === null);
    }

    public static function resetDefaults(Ctx $c): void
    {
        Db::q("UPDATE users SET defaults = '{}'::jsonb WHERE bot_id = ? AND user_id = ?", [$c->botId(), $c->uid]);
        $c->user['defaults'] = [];
        $c->answer($c->t('reset_done'));
        self::show($c, 'd');
    }

    /** Cancel a typed prompt and go back to the limits screen. */
    public static function cancelPrompt(Ctx $c, string $scope): void
    {
        self::show($c, $scope);
    }

    /* ---------------- expires ---------------- */

    public static function expires(Ctx $c, string $scope): void
    {
        $v = self::load($c, $scope);
        if ($v === null) {
            return;
        }
        $question = $v['d'] ? $c->t('expires_q_default') : $c->t('expires_q', ['title' => self::header($c, $v)]);
        $caption = Ui::title($c, 'expires', '⏳', $c->t('t_expires')) . "\n\n" . Ui::quote($question);
        if (!$v['d'] && $v['expires'] !== null) {
            $caption .= "\n" . $c->t('now') . ': ' . self::expiresLabel($c, $v);
        }

        $buttons = [];
        foreach (self::EXPIRE_CHOICES as $sec) {
            $mark = ($v['d'] && $v['expires'] === $sec) ? '✅ ' : '';
            $buttons[] = Ui::btn($c, $mark . self::durationLabel($c, $sec), "exs:$scope:$sec");
        }
        $buttons[] = Ui::btn($c, ($v['expires'] === null ? '✅ ' : '') . '♾ ' . $c->t('never'), "exs:$scope:0");
        $rows = [array_slice($buttons, 0, 3), array_slice($buttons, 3)];
        if (!$v['d']) {
            $rows[] = [Ui::btn($c, '📅 ' . $c->t('btn_other_date'), "exo:$scope")];
        }
        $rows[] = [Ui::back($c, "lm:$scope")];
        Ui::show($c, 'expires', $caption, $rows);
    }

    public static function expiresSet(Ctx $c, string $scope, string $value): void
    {
        $v = self::load($c, $scope);
        if ($v === null) {
            return;
        }
        $sec = (int) $value;
        if ($sec === 0) {
            self::save($c, $scope, 'expires', null);
        } elseif (in_array($sec, self::EXPIRE_CHOICES, true)) {
            self::save($c, $scope, 'expires', $v['d'] ? $sec : time() + $sec);
        }
        self::show($c, $scope);
    }

    public static function expiresOther(Ctx $c, string $scope, string $error = ''): void
    {
        $c->setState('exp_date', ['scope' => $scope]);
        $caption = ($error !== '' ? '⚠️ ' . $error . "\n\n" : '')
            . Ui::title($c, 'expires', '⏳', $c->t('t_expires')) . "\n\n"
            . Ui::quote($c->t('expires_other', ['example' => '<code>' . gmdate('d.m.Y', time() + 86400 * 30) . '</code>']));
        Ui::show($c, 'expires', $caption, [[Ui::btn($c, '✖️ ' . $c->t('btn_cancel'), "cx:$scope", null, 'cancel')]], $error !== '');
    }

    public static function expDateText(Ctx $c, string $scope, string $text): bool
    {
        if (!preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', trim($text), $m) || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            self::expiresOther($c, $scope, $c->t('date_bad'));
            return true;
        }
        // End of that day in the user's time zone
        $endLocal = gmmktime(23, 59, 59, (int) $m[2], (int) $m[1], (int) $m[3]);
        $ts = $endLocal - (int) $c->user['tz_offset'] * 60;
        if ($ts <= time()) {
            self::expiresOther($c, $scope, $c->t('date_past'));
            return true;
        }
        self::save($c, $scope, 'expires', $ts);
        self::show($c, $scope, true);
        return true;
    }

    /* ---------------- people ---------------- */

    public static function people(Ctx $c, string $scope): void
    {
        $v = self::load($c, $scope);
        if ($v === null) {
            return;
        }
        $text = ($v['d'] ? $c->t('people_q_default') : $c->t('people_q', ['title' => self::header($c, $v)])) . "\n"
            . '• ' . $c->t('people_rule1') . "\n• " . $c->t('people_rule2');
        if (!$v['d']) {
            $count = (int) Db::val('SELECT COUNT(*) FROM opens WHERE link_id = ?', [(int) $scope]);
            $text .= "\n\n" . $c->t('people_so_far', ['n' => $count]);
        }
        $caption = Ui::title($c, 'people', '👥', $c->t('t_people')) . "\n\n" . Ui::quote($text);

        $buttons = [];
        foreach (self::PEOPLE_CHOICES as $n) {
            $mark = $v['people'] === $n ? '✅ ' : '';
            $buttons[] = Ui::btn($c, $mark . self::peopleLabel($c, $n), "pps:$scope:$n");
        }
        $buttons[] = Ui::btn($c, ($v['people'] === null ? '✅ ' : '') . '♾ ' . $c->t('anyone'), "pps:$scope:0");
        Ui::show($c, 'people', $caption, [
            array_slice($buttons, 0, 3),
            array_slice($buttons, 3),
            [Ui::btn($c, '✏️ ' . $c->t('btn_other_number'), "ppo:$scope")],
            [Ui::back($c, "lm:$scope")],
        ]);
    }

    public static function peopleSet(Ctx $c, string $scope, string $value): void
    {
        $n = (int) $value;
        self::save($c, $scope, 'people', $n > 0 ? $n : null);
        self::show($c, $scope);
    }

    public static function peopleOther(Ctx $c, string $scope, string $error = ''): void
    {
        $c->setState('people_num', ['scope' => $scope]);
        $caption = ($error !== '' ? '⚠️ ' . $error . "\n\n" : '')
            . Ui::title($c, 'people', '👥', $c->t('t_people')) . "\n\n" . Ui::quote($c->t('people_other'));
        Ui::show($c, 'people', $caption, [[Ui::btn($c, '✖️ ' . $c->t('btn_cancel'), "cx:$scope", null, 'cancel')]], $error !== '');
    }

    public static function peopleText(Ctx $c, string $scope, string $text): bool
    {
        $n = (int) preg_replace('/\D/', '', $text);
        if ($n < 1 || $n > 1000000) {
            self::peopleOther($c, $scope, $c->t('number_bad'));
            return true;
        }
        self::save($c, $scope, 'people', $n);
        self::show($c, $scope, true);
        return true;
    }

    /* ---------------- password ---------------- */

    public static function password(Ctx $c, string $scope): void
    {
        $v = self::load($c, $scope);
        if ($v === null) {
            return;
        }
        $caption = Ui::title($c, 'password', '🔑', $c->t('t_password')) . "\n\n"
            . Ui::quote($c->t('password_text') . "\n💡 " . $c->t('password_tip') . "\n\n" . $c->t('now') . ': ' . self::onOff($c, $v['password']));
        $rows = [[Ui::btn($c, '🔑 ' . $c->t('btn_set_password'), "pws:$scope", 'primary', 'password')]];
        if ($v['password']) {
            $rows[] = [Ui::btn($c, '🗑 ' . $c->t('btn_remove_password'), "pwr:$scope", 'danger')];
        }
        $rows[] = [Ui::back($c, "lm:$scope")];
        Ui::show($c, 'password', $caption, $rows);
    }

    public static function passwordPrompt(Ctx $c, string $scope): void
    {
        $c->setState('password', ['scope' => $scope]);
        $caption = Ui::title($c, 'password', '🔑', $c->t('t_password')) . "\n\n" . Ui::quote($c->t('password_prompt'));
        Ui::show($c, 'password', $caption, [[Ui::btn($c, '✖️ ' . $c->t('btn_cancel'), "cx:$scope", null, 'cancel')]]);
    }

    public static function passwordText(Ctx $c, string $scope, string $text): bool
    {
        // Never keep the password in the chat
        $c->tg->call('deleteMessage', ['chat_id' => $c->chatId, 'message_id' => $c->message['message_id']]);
        $password = trim($text);
        if (mb_strlen($password) < 1 || mb_strlen($password) > 64) {
            return true;
        }
        self::save($c, $scope, 'password_hash', password_hash($password, PASSWORD_DEFAULT));
        self::show($c, $scope, true, '🔑 ' . $c->t('password_set'));
        return true;
    }

    public static function passwordRemove(Ctx $c, string $scope): void
    {
        self::save($c, $scope, 'password_hash', null);
        self::show($c, $scope);
    }

    /* ---------------- auto-delete ---------------- */

    public static function autodelete(Ctx $c, string $scope): void
    {
        $v = self::load($c, $scope);
        if ($v === null) {
            return;
        }
        $question = $v['d'] ? $c->t('autodelete_q_default') : $c->t('autodelete_q', ['title' => self::header($c, $v)]);
        $caption = Ui::title($c, 'clock', '⏲', $c->t('t_autodelete')) . "\n\n"
            . Ui::quote($question . "\n\n💡 " . $c->t('autodelete_max'));
        $buttons = [];
        foreach (\Links::AUTODELETE_CHOICES as $sec) {
            $buttons[] = Ui::btn($c, ($v['autodelete'] === $sec ? '✅ ' : '') . self::minutesLabel($c, $sec), "ads:$scope:$sec");
        }
        Ui::show($c, 'auto_delete', $caption, [
            $buttons,
            [Ui::btn($c, '🔁 ' . $c->t('set_resend') . ': ' . self::onOff($c, $v['resend']), "rs:$scope:ad", $v['resend'] ? 'success' : null, 'resend')],
            [Ui::back($c, "lm:$scope")],
        ]);
    }

    public static function autodeleteSet(Ctx $c, string $scope, int $sec): void
    {
        if (in_array($sec, \Links::AUTODELETE_CHOICES, true)) {
            self::save($c, $scope, 'autodelete', $sec);
        }
        self::autodelete($c, $scope);
    }

    /** Toggle resend / protect. $back: 'lm' (limits) or 'ad' (auto-delete screen). */
    public static function toggle(Ctx $c, string $scope, string $field, string $back): void
    {
        $v = self::load($c, $scope);
        if ($v === null) {
            return;
        }
        self::save($c, $scope, $field, !$v[$field]);
        if ($back === 'ad') {
            self::autodelete($c, $scope);
        } else {
            self::show($c, $scope);
        }
    }

    /* ---------------- time zone ---------------- */

    public static function timezone(Ctx $c): void
    {
        $current = (int) $c->user['tz_offset'];
        $auto = (bool) $c->user['tz_auto'];
        $caption = Ui::title($c, 'clock', '🕐', $c->t('t_timezone')) . "\n\n"
            . Ui::quote($c->t('timezone_text') . "\n\n" . $c->t('now') . ': ' . self::tzLabel($current));
        $buttons = [];
        foreach (self::TZ_CHOICES as $offset) {
            $mark = (!$auto && $offset === $current) ? '✅ ' : '';
            $buttons[] = Ui::btn($c, $mark . self::tzLabel($offset), "tzs:$offset");
        }
        $rows = array_chunk($buttons, 3);
        array_unshift($rows, [Ui::btn($c, ($auto ? '✅ ' : '') . $c->t('automatic'), 'tzs:auto')]);
        $rows[] = [Ui::back($c, 'ds')];
        Ui::show($c, 'settings', $caption, $rows);
    }

    public static function timezoneSet(Ctx $c, string $value): void
    {
        if ($value === 'auto') {
            $offset = Lang::LANGS[$c->lang()][2];
            Db::q('UPDATE users SET tz_auto = TRUE, tz_offset = ? WHERE bot_id = ? AND user_id = ?', [$offset, $c->botId(), $c->uid]);
            $c->user['tz_auto'] = true;
            $c->user['tz_offset'] = $offset;
        } else {
            $offset = (int) $value;
            if (!in_array($offset, self::TZ_CHOICES, true)) {
                return;
            }
            Db::q('UPDATE users SET tz_auto = FALSE, tz_offset = ? WHERE bot_id = ? AND user_id = ?', [$offset, $c->botId(), $c->uid]);
            $c->user['tz_auto'] = false;
            $c->user['tz_offset'] = $offset;
        }
        self::show($c, 'd');
    }
}
