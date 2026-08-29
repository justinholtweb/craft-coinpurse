<?php

namespace justinholtweb\coinpurse\base;

use craft\commerce\base\Gateway;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\models\Quote;

/**
 * A way of getting a wallet's authorisation into a Commerce gateway.
 *
 * There are two in the box — one that goes through Stripe's Express Checkout Element, and one that
 * speaks Apple Pay JS and the Google Pay API directly — and the interface exists so there can be
 * more. Register one with `Drivers::EVENT_REGISTER_DRIVERS`.
 */
interface WalletDriverInterface
{
    public static function handle(): string;

    public static function displayName(): string;

    /**
     * Whether this driver can charge through the given gateway.
     */
    public function supportsGateway(Gateway $gateway): bool;

    /**
     * Anything stopping this driver from working, in plain language. An empty array means ready.
     *
     * This is what the diagnostics screen prints, and it is the single most useful thing in the
     * plugin: a misconfigured wallet button does not error, it silently fails to render, and the
     * merchant has no way at all to tell why.
     *
     * @return string[]
     */
    public function getProblems(Gateway $gateway): array;

    /**
     * Whatever the browser needs to put a button on the page and open a sheet.
     */
    public function getClientConfig(ExpressSession $session, Quote $quote): array;

    /**
     * Turn the wallet's authorisation into the payment form this gateway expects.
     *
     * @param array $payment The driver-specific payment payload posted by the browser.
     */
    public function createPaymentForm(ExpressSession $session, Gateway $gateway, array $payment): BasePaymentForm;

    /**
     * Anything the browser needs in order to finish, once Commerce has processed the payment —
     * a client secret to confirm against, a redirect to follow, or nothing at all.
     */
    public function getPaymentResult(
        ExpressSession $session,
        ?Transaction $transaction,
        ?string $redirect,
        array $redirectData,
    ): array;
}
