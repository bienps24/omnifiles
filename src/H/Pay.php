<?php
namespace H;

use App;
use Ctx;
use Db;
use Ui;

/**
 * Telegram Stars payments, collected by the payment bot (@omnigatepaybot):
 *   pro  = no ads, one-time, lifetime
 *   slot = one more clone bot (also makes all your bots Pro)
 *
 * Flow: OmniFiles sends the user to t.me/<PAY_BOT>?start=ofiles_<product>
 *   → the payment bot sends the Stars invoice and takes the payment
 *   → it POSTs a signed event to /pay.php → Pay::grant() / Pay::refund()
 */
final class Pay
{
    public const PRODUCTS = ['pro', 'slot'];

    public static function price(string $product): int
    {
        return $product === 'slot' ? App::slotPrice() : App::proPrice();
    }

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
            . Ui::quote('• ' . $c->t('pro_point1') . "\n• " . $c->t('pro_point_forward') . "\n• " . $c->t('pro_point2') . "\n• " . $c->t('pro_point3', ['n' => App::proPrice()]))
            . "\n" . $c->t('pay_terms');
        Ui::show($c, 'pro', $caption, [
            [Ui::btn($c, '⭐ ' . $c->t('btn_buy_pro', ['n' => App::proPrice()]), 'buy:pro', 'success', 'star')],
            [Ui::back($c, 'hp')],
        ], $c->callback === null);
    }

    /** "Buy" button: send the user to the payment bot. */
    public static function invoice(Ctx $c, string $product): void
    {
        if (!$c->isMain || !in_array($product, self::PRODUCTS, true)) {
            return;
        }
        $price = self::price($product);
        $payBot = App::payBot();
        $caption = Ui::title($c, 'star', '⭐', $c->t('invoice_' . $product . '_title')) . "\n\n"
            . Ui::quote($c->t('invoice_' . $product . '_desc') . "\n\n⭐ <b>" . $price . ' Stars</b>') . "\n"
            . $c->t('pay_via', ['bot' => '@' . Ui::esc($payBot)]);
        Ui::show($c, 'pro', $caption, [
            [Ui::urlBtn($c, '⭐ ' . $c->t('btn_pay', ['n' => $price]), 'https://t.me/' . $payBot . '?start=ofiles_' . $product, 'success', 'star')],
            [Ui::back($c, $product === 'slot' ? 'cb' : 'hp')],
        ]);
    }

    /**
     * A payment confirmed by the payment bot. Returns false if it was already applied.
     */
    public static function grant(int $userId, string $product, int $stars, string $chargeId): bool
    {
        $inserted = Db::q(
            'INSERT INTO payments (user_id, bot_id, product, stars, charge_id)
             VALUES (?, COALESCE((SELECT id FROM bots WHERE is_main LIMIT 1), 0), ?, ?, ?)
             ON CONFLICT (charge_id) DO NOTHING',
            [$userId, $product, $stars, $chargeId]
        )->rowCount();
        if ($inserted === 0) {
            return false;
        }

        Ctx::account($userId);
        if ($product === 'slot') {
            Db::q('UPDATE accounts SET extra_bot_slots = extra_bot_slots + 1, is_pro = TRUE, pro_since = COALESCE(pro_since, now()) WHERE user_id = ?', [$userId]);
        } else {
            Db::q('UPDATE accounts SET is_pro = TRUE, pro_since = COALESCE(pro_since, now()) WHERE user_id = ?', [$userId]);
        }

        $c = self::mainCtx($userId);
        if ($c !== null) {
            if ($product === 'slot') {
                $text = $c->t('paid_slot');
                $next = [[Ui::btn($c, '➕ ' . $c->t('btn_add_clone'), 'ca', 'success', 'add')]];
            } else {
                $text = $c->t('paid_pro');
                $next = ['reply' => Ui::menu($c)];
            }
            $caption = Ui::title($c, 'party', '🎉', $c->t('t_thank_you')) . "\n\n" . Ui::quote($text);
            Ui::show($c, 'pro', $caption, $next, true, true);
            self::tellAdmin($c, "⭐ Payment: $stars Stars · $product · user $userId");
        }
        return true;
    }

    /** A payment refunded through the payment bot: take back what it gave. */
    public static function refund(string $chargeId): bool
    {
        $p = Db::one('SELECT * FROM payments WHERE charge_id = ? AND NOT refunded', [$chargeId]);
        if ($p === null) {
            return false;
        }
        $userId = (int) $p['user_id'];
        Db::q('UPDATE payments SET refunded = TRUE WHERE charge_id = ?', [$chargeId]);
        if ($p['product'] === 'slot') {
            Db::q('UPDATE accounts SET extra_bot_slots = GREATEST(extra_bot_slots - 1, 0) WHERE user_id = ?', [$userId]);
        }
        // Pro stays only if another paid, not refunded purchase remains
        $stillPaid = (bool) Db::val('SELECT 1 FROM payments WHERE user_id = ? AND NOT refunded', [$userId]);
        if (!$stillPaid) {
            Db::q('UPDATE accounts SET is_pro = FALSE, pro_since = NULL WHERE user_id = ?', [$userId]);
        }
        $c = self::mainCtx($userId);
        if ($c !== null) {
            self::tellAdmin($c, "↩️ Refund: {$p['stars']} Stars · {$p['product']} · user $userId");
        }
        return true;
    }

    /** Context for messaging a user from the main bot outside of an update. */
    private static function mainCtx(int $userId): ?Ctx
    {
        $main = Db::one('SELECT * FROM bots WHERE is_main LIMIT 1');
        if ($main === null) {
            return null;
        }
        $c = new Ctx($main, []);
        $c->chatId = $userId;
        $known = Db::one('SELECT first_name, username, lang FROM users WHERE bot_id = ? AND user_id = ?', [(int) $main['id'], $userId]);
        $c->loadUser([
            'id' => $userId,
            'first_name' => $known['first_name'] ?? '',
            'username' => $known['username'] ?? null,
            'language_code' => $known['lang'] ?? null,
        ]);
        return $c;
    }

    private static function tellAdmin(Ctx $c, string $text): void
    {
        $admin = App::adminId();
        if ($admin) {
            $c->tg->call('sendMessage', ['chat_id' => $admin, 'text' => $text]);
        }
    }

    /** Old direct invoices (before the payment bot): refuse, nothing is sold here anymore. */
    public static function preCheckout(Ctx $c, array $q): void
    {
        $c->tg->call('answerPreCheckoutQuery', [
            'pre_checkout_query_id' => $q['id'],
            'ok' => false,
            'error_message' => 'Please pay through @' . App::payBot() . '.',
        ]);
    }

    public static function success(Ctx $c, array $p): void
    {
        // Not used anymore: payments arrive from the payment bot through /pay.php
    }
}
