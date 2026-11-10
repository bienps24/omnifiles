<?php
namespace H;

use App;
use Ctx;
use Db;
use Ui;

/**
 * Telegram Stars payments (main bot only):
 *   pro  = no ads, one-time, lifetime
 *   slot = one more clone bot (also makes all your bots Pro)
 */
final class Pay
{
    public static function proScreen(Ctx $c): void
    {
        if (!$c->isMain) {
            return;
        }
        $acc = Ctx::account($c->uid);
        if ($acc['is_pro']) {
            $caption = Ui::title($c, 'star', '⭐', $c->t('t_pro')) . "\n\n" . Ui::quote($c->t('pro_active'));
            Ui::show($c, 'pro', $caption, [[Ui::back($c, 'hp')]], $c->callback === null);
            return;
        }
        $caption = Ui::title($c, 'star', '⭐', $c->t('t_pro')) . "\n\n"
            . Ui::quote('• ' . $c->t('pro_point1') . "\n• " . $c->t('pro_point2') . "\n• " . $c->t('pro_point3', ['n' => App::proPrice()]))
            . "\n" . $c->t('pay_terms');
        Ui::show($c, 'pro', $caption, [
            [Ui::btn($c, '⭐ ' . $c->t('btn_buy_pro', ['n' => App::proPrice()]), 'buy:pro', 'success', 'star')],
            [Ui::back($c, 'hp')],
        ], $c->callback === null);
    }

    public static function invoice(Ctx $c, string $product): void
    {
        if (!$c->isMain || !in_array($product, ['pro', 'slot'], true)) {
            return;
        }
        $price = $product === 'pro' ? App::proPrice() : App::slotPrice();
        $c->tg->call('sendInvoice', [
            'chat_id' => $c->chatId,
            'title' => $c->t('invoice_' . $product . '_title'),
            'description' => $c->t('invoice_' . $product . '_desc'),
            'payload' => $product . ':' . $c->uid,
            'currency' => 'XTR',
            'prices' => [['label' => $c->t('invoice_' . $product . '_title'), 'amount' => $price]],
        ]);
    }

    public static function preCheckout(Ctx $c, array $q): void
    {
        [$product, $uid] = array_pad(explode(':', (string) $q['invoice_payload']), 2, '');
        $ok = in_array($product, ['pro', 'slot'], true)
            && (int) $uid === (int) $q['from']['id']
            && $q['currency'] === 'XTR';
        $c->tg->call('answerPreCheckoutQuery', [
            'pre_checkout_query_id' => $q['id'],
            'ok' => $ok,
            'error_message' => $ok ? null : 'Something went wrong. Please try again.',
        ]);
    }

    public static function success(Ctx $c, array $p): void
    {
        [$product] = explode(':', (string) $p['invoice_payload']);
        $charge = (string) $p['telegram_payment_charge_id'];
        if (Db::val('SELECT 1 FROM payments WHERE charge_id = ?', [$charge])) {
            return;
        }
        Db::q(
            'INSERT INTO payments (user_id, bot_id, product, stars, charge_id) VALUES (?, ?, ?, ?, ?)',
            [$c->uid, $c->botId(), $product, (int) $p['total_amount'], $charge]
        );
        Ctx::account($c->uid);
        if ($product === 'slot') {
            Db::q('UPDATE accounts SET extra_bot_slots = extra_bot_slots + 1, is_pro = TRUE, pro_since = COALESCE(pro_since, now()) WHERE user_id = ?', [$c->uid]);
            $text = $c->t('paid_slot');
            $next = [[Ui::btn($c, '➕ ' . $c->t('btn_add_clone'), 'ca', 'success', 'add')]];
        } else {
            Db::q('UPDATE accounts SET is_pro = TRUE, pro_since = COALESCE(pro_since, now()) WHERE user_id = ?', [$c->uid]);
            $text = $c->t('paid_pro');
            $next = [];
        }
        $caption = Ui::title($c, 'party', '🎉', $c->t('t_thank_you')) . "\n\n" . Ui::quote($text);
        Ui::show($c, 'pro', $caption, $next ?: ['reply' => Ui::menu($c)], true);

        $admin = App::adminId();
        if ($admin) {
            $c->tg->call('sendMessage', [
                'chat_id' => $admin,
                'text' => "⭐ Payment: {$p['total_amount']} Stars · $product · user {$c->uid}",
            ]);
        }
    }
}
