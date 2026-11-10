<?php
namespace H;

use App;
use Ctx;
use Db;
use Lang;
use Ui;

/**
 * /start, language, welcome, help, privacy.
 */
final class Start
{
    public static function start(Ctx $c, string $payload): void
    {
        if ($payload !== '') {
            Deliver::open($c, $payload);
            return;
        }
        if (empty($c->user['lang'])) {
            self::languagePicker($c, 'w');
            return;
        }
        self::welcome($c);
    }

    /** $from: 'w' = first start (welcome after), 's' = from settings (back to settings after). */
    public static function languagePicker(Ctx $c, string $from): void
    {
        $current = $c->user['lang'] ?: null;
        $buttons = [];
        foreach (Lang::LANGS as $code => [$flag, $name]) {
            if (!Lang::has($code)) {
                continue;
            }
            $label = ($code === $current ? '✅ ' : '') . $flag . ' ' . $name;
            $buttons[] = Ui::btn($c, $label, "lang:$code:$from");
        }
        $rows = array_chunk($buttons, 2);
        if ($from === 's') {
            $rows[] = [Ui::back($c, 'ds')];
        }

        $caption = Ui::title($c, 'language', '🌐', $c->t('t_language')) . "\n\n"
            . Ui::quote($c->t('language_prompt_all'));
        Ui::show($c, 'language', $caption, $rows);
    }

    public static function pickLanguage(Ctx $c, string $code, string $from): void
    {
        if (!Lang::has($code)) {
            return;
        }
        $offset = Lang::LANGS[$code][2];
        Db::q(
            'UPDATE users SET lang = ?, tz_offset = CASE WHEN tz_auto THEN ? ELSE tz_offset END WHERE bot_id = ? AND user_id = ?',
            [$code, $offset, $c->botId(), $c->uid]
        );
        $c->user['lang'] = $code;
        if ($c->user['tz_auto']) {
            $c->user['tz_offset'] = $offset;
        }

        if ($from === 's') {
            Limits::show($c, 'd');
            return;
        }
        // Mark the choice on the picker, then send the welcome screen with the bottom menu
        self::languagePicker($c, 'done');
        self::welcome($c);
    }

    public static function welcome(Ctx $c): void
    {
        $caption = Ui::title($c, 'files', '📁', $c->t('t_welcome')) . "\n\n"
            . Ui::quote(
                Ui::num(1) . ' ' . $c->t('step_send') . "\n"
                . Ui::num(2) . ' ' . $c->t('step_link') . "\n"
                . Ui::num(3) . ' ' . $c->t('step_share')
            ) . "\n"
            . '💡 ' . $c->t('welcome_tip');
        Ui::show($c, 'welcome', $caption, ['reply' => Ui::menu($c)], true);
    }

    public static function help(Ctx $c): void
    {
        $lines = Ui::num(1) . ' ' . $c->t('step_send') . "\n"
            . Ui::num(2) . ' ' . $c->t('step_link') . "\n"
            . Ui::num(3) . ' ' . $c->t('step_share') . "\n\n"
            . '• ' . $c->t('help_many');
        if ($c->isMain) {
            $lines .= "\n• " . $c->t('help_clones');
        }
        $caption = Ui::title($c, 'help', '❓', $c->t('t_help')) . "\n\n" . Ui::quote($lines);

        $rows = [[Ui::urlBtn($c, '🎧 ' . $c->t('btn_contact'), App::contactUrl(), null, 'contact')]];
        $row = [Ui::btn($c, '🔒 ' . $c->t('btn_privacy'), 'pv', null, 'privacy')];
        if ($c->isMain) {
            $row[] = Ui::btn($c, '⭐ ' . $c->t('btn_pro'), 'pro', null, 'pro');
        }
        $rows[] = $row;
        Ui::show($c, 'help', $caption, $rows);
    }

    public static function privacy(Ctx $c): void
    {
        $caption = Ui::title($c, 'privacy', '🔒', $c->t('t_privacy')) . "\n\n"
            . Ui::quote($c->t('privacy_text'));
        Ui::show($c, 'privacy', $caption, [[Ui::back($c, 'hp')]]);
    }
}
