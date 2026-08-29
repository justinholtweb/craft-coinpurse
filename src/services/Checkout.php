<?php

namespace justinholtweb\coinpurse\services;

use Craft;
use craft\base\Component;
use craft\commerce\base\Gateway;
use craft\commerce\elements\Order;
use craft\commerce\errors\PaymentException;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use justinholtweb\coinpurse\events\ExpressOrderEvent;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\models\LogEntry;
use justinholtweb\coinpurse\models\Quote;
use justinholtweb\coinpurse\models\WalletAddress;
use justinholtweb\coinpurse\models\WalletPayer;
use justinholtweb\coinpurse\Plugin;

/**
 * Applying what a wallet says to an order, charging it, and finishing it.
 *
 * Invariant: `complete()` is the only place an express order is completed. Every driver, every
 * retry, and every return from a 3-D Secure redirect lands here, so "have we already taken this
 * customer's money?" is answered once, from the session row, rather than three times from three
 * slightly different pieces of code.
 */
class Checkout extends Component
{
    /**
     * @event ExpressOrderEvent Raised with the order addressed and priced, before it is charged.
     *                          Cancellable.
     */
    public const EVENT_BEFORE_PAY = 'beforePay';

    /**
     * @event ExpressOrderEvent Raised once the order is complete.
     */
    public const EVENT_AFTER_COMPLETE = 'afterComplete';

    /**
     * Put a wallet's shipping address onto the order and re-price it.
     *
     * Wallets redact addresses while their sheet is open — often to nothing but a country, a
     * region and a postal code — so this deliberately does not validate. Commerce's own shipping
     * matchers decide what they can rate; anything they cannot becomes "we can't ship there" in
     * the sheet rather than a validation error the customer never sees.
     */
    public function applyShippingAddress(ExpressSession $session, WalletAddress $address): Quote
    {
        $order = $this->orderFor($session);

        $order->setShippingAddress($address->toCommerceAttributes());

        // A redacted address usually cannot satisfy Commerce's own address validation, and an
        // invalid address on the order stops it saving at all. The order is not being completed
        // here, only priced, so validation is deferred to `prepare()`.
        $this->autoSelectShippingMethod($order);

        $this->persist($order);

        return Plugin::getInstance()->getQuotes()->build($session);
    }

    /**
     * Select a shipping method by handle and re-price.
     */
    public function applyShippingOption(ExpressSession $session, string $handle): Quote
    {
        $order = $this->orderFor($session);

        $available = array_map(
            static fn($option) => $option->getHandle(),
            array_filter($order->getAvailableShippingMethodOptions(), static fn($o) => $o->matchesOrder)
        );

        if (!in_array($handle, $available, true)) {
            // The browser picks from a list this site produced, so a handle that is not on it is
            // either a stale sheet or someone poking at the endpoint. Either way it must not become
            // a free shipping upgrade.
            throw new PaymentException(Craft::t('coinpurse', 'That shipping option isn’t available for this address.'));
        }

        $order->shippingMethodHandle = $handle;

        $this->persist($order);

        return Plugin::getInstance()->getQuotes()->build($session);
    }

    /**
     * Address the order the way the wallet finally said, then charge it.
     *
     * @param int|null $expectedTotal What the wallet sheet last showed, in minor units. If the
     *                                order no longer costs that, the charge is refused rather than
     *                                quietly made for a different amount.
     * @return array{quote: Quote, transaction: Transaction|null, redirect: string|null, redirectData: array, response: mixed}
     */
    public function prepare(
        ExpressSession $session,
        Gateway $gateway,
        WalletPayer $payer,
        ?WalletAddress $shippingAddress,
        ?WalletAddress $billingAddress,
        ?string $shippingOption,
        callable $formFactory,
        ?int $expectedTotal = null,
    ): array {
        $order = $this->orderFor($session);
        $options = $session->getOptions();
        $quotes = Plugin::getInstance()->getQuotes();

        if ($shippingAddress && $options->needsShipping()) {
            $order->setShippingAddress($shippingAddress->toCommerceAttributes());
        }

        // Wallets do not always return a billing address distinct from the shipping one, and
        // Commerce needs a billing address to complete an order.
        $billing = $billingAddress ?? $shippingAddress;

        if ($billing) {
            $order->setBillingAddress($billing->toCommerceAttributes());
        }

        if ($payer->email) {
            $order->setEmail($payer->email);
        }

        if ($shippingOption && $options->needsShipping()) {
            $order->shippingMethodHandle = $shippingOption;
        }

        if ($options->needsShipping() && !$order->shippingMethodHandle) {
            $this->autoSelectShippingMethod($order);
        }

        $order->gatewayId = $gateway->id;

        $order->recalculate();

        $reason = $quotes->unpayableReason($order);

        if ($reason !== null) {
            throw new PaymentException($reason);
        }

        $this->persist($order);

        $quote = $quotes->build($session);

        // The sheet is a system dialog the customer trusts more than the page behind it. If the
        // total moved between the last thing it displayed and what the order now costs — a rate
        // that changed under a redacted address, a discount that expired mid-sheet — the honest
        // answer is to stop, not to charge a number nobody agreed to.
        if ($expectedTotal !== null && $expectedTotal !== $quote->total) {
            throw new PaymentException(Craft::t('coinpurse', 'The total changed while you were paying. Nothing has been charged — please try again.'));
        }

        $event = new ExpressOrderEvent(['order' => $order, 'session' => $session]);

        $this->trigger(self::EVENT_BEFORE_PAY, $event);

        if (!$event->isValid) {
            throw new PaymentException($event->message ?: Craft::t('coinpurse', 'This order can’t be completed.'));
        }

        Plugin::getInstance()->getSessions()->setState($session, ExpressSession::STATE_PAYING);

        $form = $formFactory($order);

        $redirect = null;
        $transaction = null;
        $redirectData = [];

        Commerce::getInstance()->getPayments()->processPayment($order, $form, $redirect, $transaction, $redirectData);

        $session->transactionId = $transaction?->id;
        $session->orderNumber = $order->number;

        Plugin::getInstance()->getSessions()->save($session);

        return [
            'quote' => $quote,
            'transaction' => $transaction,
            'redirect' => $redirect,
            'redirectData' => $redirectData,
        ];
    }

    /**
     * Finish an express order. The only place that happens.
     *
     * Idempotent by design: a wallet sheet can fire its completion handler more than once — a
     * flaky network, a customer double-tapping, a browser restoring a backgrounded tab — and the
     * second call must return the same order, not take a second payment.
     *
     * @return array{number: string, order: Order}
     */
    public function complete(ExpressSession $session): array
    {
        $order = $this->orderFor($session, allowCompleted: true);

        if ($session->isPaid() || $order->isCompleted) {
            $this->markPaid($session, $order);

            return ['number' => $order->number, 'order' => $order];
        }

        $transaction = $session->transactionId
            ? Commerce::getInstance()->getTransactions()->getTransactionById($session->transactionId)
            : null;

        if (!$transaction) {
            throw new PaymentException(Craft::t('coinpurse', 'This payment hasn’t been started yet.'));
        }

        $customError = null;

        $success = Commerce::getInstance()->getPayments()->completePayment($transaction, $customError);

        if (!$success) {
            Plugin::getInstance()->getSessions()->setState($session, ExpressSession::STATE_FAILED, $customError);

            throw new PaymentException($customError ?: Craft::t('coinpurse', 'The payment couldn’t be completed.'));
        }

        // `completePayment()` mutates the order behind the transaction, so re-read it rather than
        // trusting the copy this request has been holding.
        $order = Commerce::getInstance()->getOrders()->getOrderById($order->id) ?? $order;

        $this->markPaid($session, $order);

        return ['number' => $order->number, 'order' => $order];
    }

    // ---------------------------------------------------------------- Internals

    private function markPaid(ExpressSession $session, Order $order): void
    {
        if ($session->isPaid()) {
            return;
        }

        $session->orderNumber = $order->number;

        Plugin::getInstance()->getSessions()->setState($session, ExpressSession::STATE_PAID);

        Plugin::getInstance()->getLog()->write('complete', [
            'level' => LogEntry::LEVEL_INFO,
            'sessionUid' => $session->uid,
            'orderNumber' => $order->number,
            'driver' => $session->driver,
            'summary' => Craft::t('coinpurse', 'Express order {number} completed.', ['number' => $order->number]),
        ]);

        $this->trigger(self::EVENT_AFTER_COMPLETE, new ExpressOrderEvent([
            'order' => $order,
            'session' => $session,
        ]));
    }

    private function orderFor(ExpressSession $session, bool $allowCompleted = false): Order
    {
        $order = $session->getOrder();

        if (!$order) {
            throw new PaymentException(Craft::t('coinpurse', 'The order behind this payment no longer exists.'));
        }

        if ($order->isCompleted && !$allowCompleted) {
            throw new PaymentException(Craft::t('coinpurse', 'This order has already been placed.'));
        }

        return $order;
    }

    /**
     * Pick a shipping method when the order has none, so that the sheet opens with a price rather
     * than a blank. Cheapest wins, which is the same order the sheet lists them in.
     */
    private function autoSelectShippingMethod(Order $order): void
    {
        $options = array_filter($order->getAvailableShippingMethodOptions(), static fn($o) => $o->matchesOrder);

        if (!$options) {
            $order->shippingMethodHandle = null;

            return;
        }

        $handles = array_map(static fn($o) => $o->getHandle(), $options);

        if ($order->shippingMethodHandle && in_array($order->shippingMethodHandle, $handles, true)) {
            return;
        }

        usort($options, static fn($a, $b) => $a->price <=> $b->price);

        $order->shippingMethodHandle = reset($options)->getHandle();
    }

    /**
     * Save an order that is mid-sheet.
     *
     * Validation is off on purpose. A wallet's redacted address will not pass Commerce's address
     * validation, and an order that will not save cannot be priced at all — which would turn every
     * mid-sheet re-quote into a failure. The order is validated where it matters, on the way into
     * `processPayment()`, by which point the wallet has handed over the complete address.
     */
    private function persist(Order $order): void
    {
        $order->recalculate();

        Craft::$app->getElements()->saveElement($order, false);
    }
}
