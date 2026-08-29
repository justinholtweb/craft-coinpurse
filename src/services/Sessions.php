<?php

namespace justinholtweb\coinpurse\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use justinholtweb\coinpurse\db\Table;
use justinholtweb\coinpurse\helpers\Signature;
use justinholtweb\coinpurse\models\ButtonOptions;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\Plugin;
use justinholtweb\coinpurse\records\SessionRecord;
use yii\base\InvalidArgumentException;

/**
 * Express sessions: the order behind a wallet sheet, and the promise to put the cart back.
 *
 * The interesting part of this service is the snapshot. A wallet sheet writes a shipping address
 * and a shipping method onto the order so that it can be priced, and the customer is free to
 * dismiss the sheet at any point — on the address step, on the rate step, at the fingerprint
 * prompt. The plugin Coin Purse replaces left all of that on the live cart, so a customer who
 * opened Apple Pay out of curiosity found their carefully filled cart addressed to whatever their
 * phone had on file. Here the pre-flight state is written down before anything is touched and put
 * back on cancel, failure or expiry.
 */
class Sessions extends Component
{
    /**
     * Open a session for a button press.
     */
    public function open(ButtonOptions $options, string $driverHandle): ExpressSession
    {
        $order = $options->mode === ButtonOptions::MODE_CART
            ? $this->cartOrder()
            : $this->detachedOrder($options);

        $session = new ExpressSession([
            'mode' => $options->mode,
            'driver' => $driverHandle,
            'siteId' => $options->siteId ?? Craft::$app->getSites()->getCurrentSite()->id,
            'gatewayId' => $options->gatewayId,
            'state' => ExpressSession::STATE_OPEN,
            'payloadHash' => Signature::fingerprint($options->signablePayload()),
            'payload' => $options->signablePayload(),
            'snapshot' => $this->snapshot($order, $options->mode),
        ]);

        $session->setOrder($order);

        $this->save($session);

        return $session;
    }

    public function getByUid(string $uid): ?ExpressSession
    {
        $record = SessionRecord::findOne(['uid' => $uid]);

        return $record ? $this->toModel($record) : null;
    }

    /**
     * Fetch a session, refusing anything that is not this site's, not live, or too old.
     */
    public function getLiveByUid(string $uid): ExpressSession
    {
        $session = $this->getByUid($uid);

        if (!$session) {
            throw new InvalidArgumentException('Unknown express session.');
        }

        if (!$session->isLive()) {
            throw new InvalidArgumentException('This express session is no longer open.');
        }

        if ($this->hasExpired($session)) {
            $this->cancel($session, 'Session expired.');

            throw new InvalidArgumentException('This express session has expired. Reload and try again.');
        }

        if (!$session->getOrder()) {
            throw new InvalidArgumentException('The order behind this express session no longer exists.');
        }

        return $session;
    }

    public function hasExpired(ExpressSession $session): bool
    {
        $lifetime = Plugin::getInstance()->getSettings()->sessionLifetimeMinutes;

        if ($lifetime <= 0 || !$session->dateCreated instanceof DateTime) {
            return false;
        }

        return $session->dateCreated->getTimestamp() + ($lifetime * 60) < time();
    }

    public function save(ExpressSession $session): void
    {
        $record = $session->id ? SessionRecord::findOne($session->id) : null;

        if (!$record) {
            $record = new SessionRecord();
        }

        $record->mode = $session->mode;
        $record->driver = $session->driver;
        $record->orderId = $session->orderId;
        $record->siteId = $session->siteId;
        $record->gatewayId = $session->gatewayId;
        $record->state = $session->state;
        $record->payloadHash = $session->payloadHash;
        $record->payload = json_encode($session->payload);
        $record->snapshot = json_encode($session->snapshot);
        $record->transactionId = $session->transactionId;
        $record->orderNumber = $session->orderNumber;
        $record->lastError = $session->lastError;

        $record->save(false);

        $session->id = $record->id;
        $session->uid = $record->uid;
        $session->dateCreated = $session->dateCreated ?? new DateTime();
    }

    public function setState(ExpressSession $session, string $state, ?string $error = null): void
    {
        $session->state = $state;
        $session->lastError = $error;

        $this->save($session);
    }

    /**
     * Abandon a session and put the cart back the way it was found.
     *
     * Safe to call more than once, and safe to call on a session that never touched a cart.
     */
    public function cancel(ExpressSession $session, ?string $reason = null): void
    {
        if ($session->state === ExpressSession::STATE_PAID) {
            return;
        }

        $this->restore($session);

        $this->setState($session, ExpressSession::STATE_CANCELLED, $reason);
    }

    /**
     * Put a cart-mode order back to its pre-flight state.
     */
    public function restore(ExpressSession $session): void
    {
        if ($session->mode !== ButtonOptions::MODE_CART) {
            // A detached order has nothing to restore: it was never the customer's cart, and
            // Commerce's own incomplete-cart purging will collect it.
            return;
        }

        $order = $session->getOrder();

        if (!$order || $order->isCompleted) {
            return;
        }

        $snapshot = $session->snapshot;

        if (!$snapshot) {
            return;
        }

        try {
            $order->setShippingAddress($snapshot['shippingAddress'] ?: null);
            $order->setBillingAddress($snapshot['billingAddress'] ?: null);
            $order->shippingMethodHandle = $snapshot['shippingMethodHandle'] ?: null;

            // Only put the email back if the wallet is what supplied it. Overwriting a real email
            // with an empty snapshot would log the customer's cart out of its own identity.
            if (!empty($snapshot['email'])) {
                $order->setEmail($snapshot['email']);
            }

            $order->recalculate();

            Craft::$app->getElements()->saveElement($order, false);
        } catch (\Throwable $e) {
            // Restoring is a courtesy. Failing to do it must not turn a dismissed wallet sheet into
            // a 500 on the customer's cart page.
            Craft::warning('Coin Purse could not restore the cart after an express session: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Whether an order was placed through express checkout.
     *
     * Session rows are pruned, so a false answer for an old order means "no record", not "no".
     * Anything that must survive belongs on the order itself.
     */
    public function wasExpressOrder(int $orderId): bool
    {
        return SessionRecord::find()
            ->where(['orderId' => $orderId, 'state' => ExpressSession::STATE_PAID])
            ->exists();
    }

    /**
     * Delete finished and stale session rows. Returns the number deleted.
     */
    public function purge(?int $minutes = null): int
    {
        $minutes = $minutes ?? max(60, Plugin::getInstance()->getSettings()->sessionLifetimeMinutes * 4);
        $cutoff = (new DateTime())->modify("-{$minutes} minutes");

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::SESSIONS, [
            'and',
            ['<', 'dateCreated', Db::prepareDateForDb($cutoff)],
        ])->execute();
    }

    /**
     * Sessions that were left open — a sheet dismissed with the tab closed, so no cancel ever
     * arrived. Returns the number restored.
     */
    public function reapAbandoned(): int
    {
        $lifetime = Plugin::getInstance()->getSettings()->sessionLifetimeMinutes;
        $cutoff = (new DateTime())->modify("-{$lifetime} minutes");

        $records = SessionRecord::find()
            ->where(['state' => [ExpressSession::STATE_OPEN, ExpressSession::STATE_PAYING]])
            ->andWhere(['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->all();

        $reaped = 0;

        foreach ($records as $record) {
            $this->cancel($this->toModel($record), 'Abandoned.');
            $reaped++;
        }

        return $reaped;
    }

    // ---------------------------------------------------------------- Internals

    private function toModel(SessionRecord $record): ExpressSession
    {
        return new ExpressSession([
            'id' => $record->id,
            'mode' => $record->mode,
            'driver' => $record->driver,
            'orderId' => $record->orderId,
            'siteId' => $record->siteId,
            'gatewayId' => $record->gatewayId,
            'state' => $record->state,
            'payloadHash' => $record->payloadHash,
            'payload' => json_decode((string)$record->payload, true) ?: [],
            'snapshot' => json_decode((string)$record->snapshot, true) ?: [],
            'transactionId' => $record->transactionId,
            'orderNumber' => $record->orderNumber,
            'lastError' => $record->lastError,
            // The column is bare UTC; DateTimeHelper::toDateTime() assumes UTC for a string with
            // no zone, which is exactly right here. Constructing a DateTime directly would read it
            // as site-local and make every session look minutes or hours older than it is.
            'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
            'uid' => $record->uid,
        ]);
    }

    private function snapshot(Order $order, string $mode): array
    {
        if ($mode !== ButtonOptions::MODE_CART) {
            return [];
        }

        return [
            'shippingAddress' => $order->getShippingAddress()?->toArray([
                'countryCode', 'administrativeArea', 'locality', 'postalCode',
                'addressLine1', 'addressLine2', 'addressLine3', 'organization', 'fullName',
            ]) ?: null,
            'billingAddress' => $order->getBillingAddress()?->toArray([
                'countryCode', 'administrativeArea', 'locality', 'postalCode',
                'addressLine1', 'addressLine2', 'addressLine3', 'organization', 'fullName',
            ]) ?: null,
            'shippingMethodHandle' => $order->shippingMethodHandle,
            'email' => $order->getEmail(),
        ];
    }

    private function cartOrder(): Order
    {
        $cart = Commerce::getInstance()->getCarts()->getCart();

        if (!$cart->id) {
            Craft::$app->getElements()->saveElement($cart, false);
        }

        return $cart;
    }

    /**
     * A brand-new order that is deliberately *not* the session cart, built from the button's
     * signed item list.
     */
    private function detachedOrder(ButtonOptions $options): Order
    {
        $commerce = Commerce::getInstance();
        $sites = Craft::$app->getSites();
        $currentUser = Craft::$app->getUser()->getIdentity();

        $attributes = [
            'number' => $commerce->getCarts()->generateCartNumber(),
            'orderSiteId' => $options->siteId ?? $sites->getCurrentSite()->id,
            'storeId' => $commerce->getStores()->getCurrentStore()->id,
        ];

        if ($currentUser) {
            $attributes['customer'] = $currentUser;
        }

        /** @var Order $order */
        $order = Craft::createObject([
            'class' => Order::class,
            'attributes' => $attributes,
        ]);

        $order->origin = Order::ORIGIN_WEB;
        $order->orderLanguage = Craft::$app->language;
        $order->setRecalculationMode(Order::RECALCULATION_MODE_ALL);

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $order->lastIp = Craft::$app->getRequest()->getUserIP();
        }

        // The order has to exist before anything else happens to it: `Order::recalculate()`
        // throws outright on an order with no ID, and `createLineItem()` wants an order to hang
        // the item off. So it is saved empty, filled, re-priced, and saved again.
        Craft::$app->getElements()->saveElement($order, false);

        foreach ($options->items as $item) {
            $lineItem = $commerce->getLineItems()->createLineItem(
                $order,
                (int)$item['id'],
                $item['options'] ?? [],
                max(1, (int)($item['qty'] ?? 1)),
                (string)($item['note'] ?? '')
            );

            $order->addLineItem($lineItem);
        }

        $order->recalculate();

        Craft::$app->getElements()->saveElement($order, false);

        return $order;
    }
}
