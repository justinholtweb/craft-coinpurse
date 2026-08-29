<?php

namespace justinholtweb\coinpurse\drivers;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\helpers\UrlHelper;
use justinholtweb\coinpurse\base\Driver;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\models\Quote;
use justinholtweb\coinpurse\models\Settings;

/**
 * Apple Pay and Google Pay through Stripe's Express Checkout Element.
 *
 * ## Why this shape
 *
 * Commerce's own Stripe gateway creates an **unconfirmed** PaymentIntent whenever the payment form
 * carries no `paymentMethodId`, and `PaymentIntentResponse::getRedirectData()` then hands back the
 * `client_secret`. That is the seam Commerce's own Payment Element uses, and using the same one
 * means transactions, order completion, status emails, captures and refunds are all Commerce's
 * code rather than a reimplementation living in a plugin.
 *
 * So the division of labour is: Commerce creates and finishes the intent, the browser confirms it.
 *
 * The older `stripe.paymentRequest()` / `paymentRequestButton` pairing would map more directly onto
 * Commerce's legacy `paymentMethodId` field and save a round trip. It is deliberately not used:
 * Stripe has superseded it with the Express Checkout Element, and a plugin written now should not
 * be born on a deprecated element.
 */
class StripeDriver extends Driver
{
    public const GATEWAY_CLASS = 'craft\\commerce\\stripe\\gateways\\PaymentIntents';
    public const FORM_CLASS = 'craft\\commerce\\stripe\\models\\forms\\payment\\PaymentIntent';

    /**
     * The wallets the Express Checkout Element can show, mapped to Stripe's own keys.
     */
    private const WALLET_MAP = [
        Settings::WALLET_APPLE_PAY => 'applePay',
        Settings::WALLET_GOOGLE_PAY => 'googlePay',
        Settings::WALLET_LINK => 'link',
        Settings::WALLET_PAYPAL => 'paypal',
        Settings::WALLET_AMAZON_PAY => 'amazonPay',
        Settings::WALLET_KLARNA => 'klarna',
    ];

    public static function handle(): string
    {
        return 'stripe';
    }

    public static function displayName(): string
    {
        return Craft::t('coinpurse', 'Stripe (Express Checkout Element)');
    }

    public function supportsGateway(Gateway $gateway): bool
    {
        $class = self::GATEWAY_CLASS;

        return class_exists($class) && $gateway instanceof $class;
    }

    public function getProblems(Gateway $gateway): array
    {
        if (!class_exists(self::GATEWAY_CLASS)) {
            return [Craft::t('coinpurse', 'The Stripe for Craft Commerce plugin isn’t installed.')];
        }

        if (!$this->supportsGateway($gateway)) {
            return [Craft::t('coinpurse', 'The selected gateway isn’t a Stripe Payment Intents gateway.')];
        }

        $problems = [];

        if (!method_exists($gateway, 'getPublishableKey') || !$gateway->getPublishableKey()) {
            $problems[] = Craft::t('coinpurse', 'The Stripe gateway has no publishable key, so no button can be rendered.');
        }

        return $problems;
    }

    public function getClientConfig(ExpressSession $session, Quote $quote): array
    {
        $gateway = $session->gatewayId
            ? Commerce::getInstance()->getGateways()->getGatewayById($session->gatewayId)
            : null;

        $settings = $this->settings();
        $wallets = $this->enabledWallets(array_keys(self::WALLET_MAP));

        return [
            'publishableKey' => $gateway && method_exists($gateway, 'getPublishableKey')
                ? $gateway->getPublishableKey()
                : null,
            'locale' => $this->stripeLocale(),
            // `never` is the explicit off switch; anything not named here Stripe decides for
            // itself, which is what the `stripeAutoWallets` setting turns on.
            'paymentMethods' => $this->paymentMethodPreferences($wallets),
            'wallets' => $wallets,
            'businessName' => $settings->getMerchantName(),
        ];
    }

    /**
     * An empty payment form.
     *
     * The absence of `paymentMethodId` is doing real work here, not an oversight: it is what makes
     * `PaymentIntents::authorizeOrPurchase()` create the intent *without* confirming it, so that
     * the browser can confirm it with the wallet's own authorisation.
     */
    public function createPaymentForm(ExpressSession $session, Gateway $gateway, array $payment): BasePaymentForm
    {
        $class = self::FORM_CLASS;

        /** @var BasePaymentForm $form */
        $form = new $class();
        $form->paymentFormType = 'elements';

        return $form;
    }

    public function getPaymentResult(
        ExpressSession $session,
        ?Transaction $transaction,
        ?string $redirect,
        array $redirectData,
    ): array {
        $clientSecret = $redirectData['client_secret'] ?? null;

        if (!$clientSecret) {
            // The intent went straight through without needing the browser to confirm it. Nothing
            // is left for the wallet to do.
            return ['confirmed' => true];
        }

        return [
            'confirmed' => false,
            'clientSecret' => $clientSecret,
            'paymentIntent' => $redirectData['payment_intent'] ?? null,
            // Where Stripe sends the customer if the wallet's authorisation still needs a 3-D
            // Secure step. Commerce's own completion action picks it up there, so a redirected
            // payment finishes in exactly the same place as one that never left the page.
            'returnUrl' => $transaction
                ? UrlHelper::actionUrl('commerce/payments/complete-payment', [
                    'commerceTransactionId' => $transaction->id,
                    'commerceTransactionHash' => $transaction->hash,
                ])
                : UrlHelper::siteUrl(),
        ];
    }

    // ---------------------------------------------------------------- Internals

    /**
     * @param string[] $wallets
     */
    private function paymentMethodPreferences(array $wallets): array
    {
        if ($this->settings()->stripeAutoWallets) {
            return [];
        }

        $preferences = [];

        foreach (self::WALLET_MAP as $handle => $stripeKey) {
            $preferences[$stripeKey] = in_array($handle, $wallets, true) ? 'auto' : 'never';
        }

        return $preferences;
    }

    /**
     * Stripe wants a locale it recognises. Craft's locale IDs use underscores where Stripe uses
     * hyphens, and `auto` is the safe answer for anything unusual.
     */
    private function stripeLocale(): string
    {
        $language = str_replace('_', '-', (string)Craft::$app->language);

        return $language !== '' ? $language : 'auto';
    }
}
