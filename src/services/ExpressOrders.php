<?php

namespace justinholtweb\coinpurse\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Html;
use justinholtweb\coinpurse\db\Table;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\models\Settings;

/**
 * The permanent record of which orders were placed through express checkout, and with which wallet.
 *
 * Session rows are working state and are purged within hours, so they cannot answer "was this
 * order an Apple Pay order?" a month later. One row per completed express order is written here
 * when `Checkout::complete()` marks a session paid, and is only removed with the order itself.
 *
 * The wallet is what the browser reported when the customer authorised the payment. It is a label
 * for staff, not a security fact: the only person who can misreport it is the customer, about
 * their own order, and nothing is charged or discounted on the strength of it.
 */
class ExpressOrders extends Component
{
    /** Column / condition-rule value meaning "any express order, whatever the wallet". */
    public const ANY = '*';

    /** Condition-rule value meaning "not placed through express checkout". */
    public const NONE = 'none';

    /** Stored when the wallet could not be told (rows backfilled from before wallets were recorded). */
    public const UNKNOWN = 'unknown';

    /**
     * Stripe's `expressPaymentType` values, mapped onto Coin Purse's driver-neutral wallet handles.
     */
    private const STRIPE_TYPES = [
        'apple_pay' => Settings::WALLET_APPLE_PAY,
        'google_pay' => Settings::WALLET_GOOGLE_PAY,
        'link' => Settings::WALLET_LINK,
        'paypal' => Settings::WALLET_PAYPAL,
        'amazon_pay' => Settings::WALLET_AMAZON_PAY,
        'klarna' => Settings::WALLET_KLARNA,
    ];

    /** @var array<int, string|null> Wallet per order id; null means "not express". */
    private array $_wallets = [];

    /**
     * Every wallet handle, with its label.
     *
     * @return array<string, string>
     */
    public function walletLabels(): array
    {
        return [
            Settings::WALLET_APPLE_PAY => Craft::t('coinpurse', 'Apple Pay'),
            Settings::WALLET_GOOGLE_PAY => Craft::t('coinpurse', 'Google Pay'),
            Settings::WALLET_LINK => Craft::t('coinpurse', 'Link'),
            Settings::WALLET_PAYPAL => Craft::t('coinpurse', 'PayPal'),
            Settings::WALLET_AMAZON_PAY => Craft::t('coinpurse', 'Amazon Pay'),
            Settings::WALLET_KLARNA => Craft::t('coinpurse', 'Klarna'),
        ];
    }

    public function walletLabel(?string $wallet): string
    {
        return $this->walletLabels()[$wallet] ?? Craft::t('coinpurse', 'Express checkout');
    }

    /**
     * Turn whatever the browser sent into a known wallet handle, or null.
     *
     * Accepts Coin Purse's own handles (`applePay`) and Stripe's (`apple_pay`). Anything else is
     * dropped rather than stored, because this value is rendered in the control panel.
     */
    public function normalise(mixed $wallet): ?string
    {
        if (!is_string($wallet) || $wallet === '') {
            return null;
        }

        if (isset(self::STRIPE_TYPES[$wallet])) {
            return self::STRIPE_TYPES[$wallet];
        }

        return array_key_exists($wallet, $this->walletLabels()) ? $wallet : null;
    }

    /**
     * Note on the session which wallet the customer paid with. Called from `express/pay`.
     */
    public function rememberWallet(ExpressSession $session, mixed $wallet): void
    {
        $wallet = $this->normalise($wallet);

        if (!$session->id || $wallet === null) {
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::SESSIONS, ['wallet' => $wallet], ['id' => $session->id], [], false)
            ->execute();
    }

    /**
     * Record a completed express order. Idempotent: a second call for the same order does nothing.
     */
    public function record(ExpressSession $session, Order $order): void
    {
        if (!$order->id) {
            return;
        }

        $wallet = $session->id
            ? (new Query())->select(['wallet'])->from(Table::SESSIONS)->where(['id' => $session->id])->scalar()
            : null;

        $wallet = $this->normalise($wallet) ?? self::UNKNOWN;

        Db::upsert(Table::EXPRESS_ORDERS, [
            'orderId' => $order->id,
            'wallet' => $wallet,
            'driver' => $session->driver ?: null,
            'sessionUid' => $session->uid,
        ], false);

        $this->_wallets[(int)$order->id] = $wallet;
    }

    /**
     * Whether an order was placed through express checkout.
     */
    public function isExpress(int $orderId): bool
    {
        return $this->walletFor($orderId) !== null;
    }

    /**
     * The wallet an order was paid with: a wallet handle, `unknown`, or null if it wasn't express.
     */
    public function walletFor(int $orderId): ?string
    {
        if (!array_key_exists($orderId, $this->_wallets)) {
            $this->prime([$orderId]);
        }

        return $this->_wallets[$orderId];
    }

    /**
     * Load the wallets for many orders in one query. The order index calls this once per page so
     * that the column costs one query, not one per row.
     *
     * @param int[] $orderIds
     */
    public function prime(array $orderIds): void
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $missing = array_values(array_filter($orderIds, fn(int $id) => !array_key_exists($id, $this->_wallets)));

        if (!$missing) {
            return;
        }

        foreach ($missing as $id) {
            $this->_wallets[$id] = null;
        }

        $rows = (new Query())
            ->select(['orderId', 'wallet'])
            ->from(Table::EXPRESS_ORDERS)
            ->where(['orderId' => $missing])
            ->all();

        foreach ($rows as $row) {
            $this->_wallets[(int)$row['orderId']] = (string)$row['wallet'];
        }
    }

    /**
     * The `elements.id` subquery an order query is narrowed by.
     *
     * @param string $value A wallet handle; `*` or `none` for every express order (the caller
     * negates it for `none`).
     */
    public function orderIdSubQuery(string $value): Query
    {
        $query = (new Query())->select(['orderId'])->from(Table::EXPRESS_ORDERS);

        if ($value !== self::ANY && $value !== self::NONE) {
            $query->where(['wallet' => $value]);
        }

        return $query;
    }

    /**
     * The order index cell: the wallet's name, or nothing for an ordinary order.
     */
    public function tableAttributeHtml(Order $order): string
    {
        $wallet = $order->id ? $this->walletFor((int)$order->id) : null;

        if ($wallet === null) {
            return '';
        }

        return Html::tag('span', Html::encode($this->walletLabel($wallet)), [
            'class' => 'coinpurse-wallet',
            'data-wallet' => $wallet,
        ]);
    }

    /**
     * Forget cached answers. Tests use this; so would anything that deletes rows mid-request.
     */
    public function reset(): void
    {
        $this->_wallets = [];
    }
}
