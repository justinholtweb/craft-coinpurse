<?php

namespace justinholtweb\coinpurse\drivers;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use justinholtweb\coinpurse\base\Driver;
use justinholtweb\coinpurse\helpers\Money;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\models\Quote;
use justinholtweb\coinpurse\models\Settings;
use justinholtweb\coinpurse\Plugin;

/**
 * Apple Pay and Google Pay spoken directly, for stores that are not on Stripe.
 *
 * Where the Stripe driver borrows Stripe's Express Checkout Element, this one implements Apple Pay
 * JS and the Google Pay API itself and hands the resulting wallet token to whatever gateway the
 * store already uses. Both wallets are designed for exactly this: Apple Pay's payment token and
 * Google Pay's `PAYMENT_GATEWAY` tokenization are both encrypted *to the gateway*, so the token
 * passes through the site opaque and is decrypted by the party that is going to charge it.
 *
 * The only genuinely site-specific part is the last inch: which attribute of the gateway's own
 * payment form model receives the token. Gateway plugins do not agree — `nonce`, `token`,
 * `paymentData` and `gatewayToken` are all in use — so Coin Purse looks for a known name and lets
 * the merchant name it explicitly when the guess is wrong. The diagnostics screen prints which
 * attribute it settled on, so this is never a mystery.
 *
 * **Verification status:** this driver is written from Apple's and Google's published
 * specifications, and its request-building and token-mapping are covered by the test suite. A live
 * end-to-end charge needs an Apple merchant identity certificate and a gateway account, neither of
 * which the test harness has, so it ships documented rather than claimed as proven.
 */
class RawDriver extends Driver
{
    /**
     * Attribute names, in preference order, that gateway payment forms use for a wallet token.
     */
    public const TOKEN_ATTRIBUTE_CANDIDATES = [
        'nonce',
        'paymentData',
        'gatewayToken',
        'walletToken',
        'token',
        'opaqueDataValue',
        'sourceId',
    ];

    /** Google Pay's card network names mapped to Apple Pay's. */
    private const APPLE_NETWORKS = [
        'AMEX' => 'amex',
        'DISCOVER' => 'discover',
        'INTERAC' => 'interac',
        'JCB' => 'jcb',
        'MASTERCARD' => 'masterCard',
        'VISA' => 'visa',
        'ELECTRON' => 'electron',
        'ELO' => 'elo',
        'MAESTRO' => 'maestro',
    ];

    public static function handle(): string
    {
        return 'raw';
    }

    public static function displayName(): string
    {
        return Craft::t('coinpurse', 'Apple Pay & Google Pay (direct)');
    }

    /**
     * Any gateway at all. This driver is the fallback, and `Drivers::resolve()` offers the Stripe
     * driver first, so a Stripe store only lands here if the merchant asks for it by name.
     */
    public function supportsGateway(Gateway $gateway): bool
    {
        return true;
    }

    public function getProblems(Gateway $gateway): array
    {
        $settings = $this->settings();
        $problems = [];

        if ($settings->enableApplePay) {
            $problems = array_merge($problems, Plugin::getInstance()->getApplePay()->getProblems());
        }

        if ($settings->enableGooglePay) {
            if (!$settings->googlePayGateway) {
                $problems[] = Craft::t('coinpurse', 'Google Pay needs the gateway identifier it should encrypt tokens for.');
            }

            if (!$settings->googlePayGatewayMerchantId) {
                $problems[] = Craft::t('coinpurse', 'Google Pay needs your merchant ID at that gateway.');
            }

            if ($settings->googlePayEnvironment === 'PRODUCTION' && !$settings->googlePayMerchantId) {
                $problems[] = Craft::t('coinpurse', 'Google Pay in production needs a Google Pay merchant ID.');
            }
        }

        if (!$settings->enableApplePay && !$settings->enableGooglePay) {
            $problems[] = Craft::t('coinpurse', 'Neither Apple Pay nor Google Pay is switched on, so there is no button to show.');
        }

        try {
            if ($this->resolveTokenAttribute($gateway) === null) {
                $problems[] = Craft::t('coinpurse', 'Coin Purse can’t tell which field of this gateway’s payment form takes the wallet token. Name it in the settings.');
            }
        } catch (\Throwable $e) {
            $problems[] = Craft::t('coinpurse', 'This gateway’s payment form could not be inspected: {message}', ['message' => $e->getMessage()]);
        }

        return $problems;
    }

    public function getClientConfig(ExpressSession $session, Quote $quote): array
    {
        $settings = $this->settings();

        return [
            'applePay' => $settings->enableApplePay ? $this->applePayConfig($quote) : null,
            'googlePay' => $settings->enableGooglePay ? $this->googlePayConfig($quote) : null,
        ];
    }

    public function createPaymentForm(ExpressSession $session, Gateway $gateway, array $payment): BasePaymentForm
    {
        $token = $payment['token'] ?? null;

        if ($token === null || $token === '') {
            throw new \InvalidArgumentException('The wallet returned no payment token.');
        }

        $form = $gateway->getPaymentFormModel();
        $attribute = $this->resolveTokenAttribute($gateway);

        if ($attribute === null) {
            throw new \RuntimeException(
                'Coin Purse does not know which field of ' . $gateway::displayName() .
                '’s payment form takes a wallet token. Set it in Coin Purse’s settings.'
            );
        }

        $form->$attribute = $this->encodeToken($token);

        return $form;
    }

    public function getPaymentResult(
        ExpressSession $session,
        ?Transaction $transaction,
        ?string $redirect,
        array $redirectData,
    ): array {
        // A gateway that answered with a redirect wants the customer to authenticate somewhere
        // else — 3-D Secure, usually. The wallet sheet cannot host that, so the page follows it and
        // the gateway's own return URL brings the customer back into Commerce's completion action.
        if ($redirect) {
            return [
                'confirmed' => false,
                'redirect' => $redirect,
                'redirectData' => $redirectData,
            ];
        }

        return ['confirmed' => true];
    }

    /**
     * Which attribute of the gateway's payment form receives the token.
     *
     * The merchant's own setting wins. Failing that, the first known name the form actually
     * declares is used; if the form declares none of them, this returns null and the diagnostics
     * screen says so rather than the button failing silently in a customer's hand.
     */
    public function resolveTokenAttribute(Gateway $gateway): ?string
    {
        $configured = $this->settings()->tokenTargetAttribute;

        if ($configured) {
            return $configured;
        }

        $form = $gateway->getPaymentFormModel();
        $attributes = $form->attributes();

        foreach (self::TOKEN_ATTRIBUTE_CANDIDATES as $candidate) {
            if (in_array($candidate, $attributes, true)) {
                return $candidate;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- Internals

    private function encodeToken(mixed $token): string
    {
        $encoding = $this->settings()->tokenEncoding;

        // Apple Pay hands over a structured token; Google Pay hands over a string that is itself
        // JSON. Gateways want one or the other, and occasionally base64 of it.
        $raw = is_string($token) ? $token : (string)json_encode($token);

        return match ($encoding) {
            Settings::ENCODING_BASE64 => base64_encode($raw),
            Settings::ENCODING_JSON => is_string($token) ? $raw : (string)json_encode($token),
            default => $raw,
        };
    }

    private function applePayConfig(Quote $quote): array
    {
        $settings = $this->settings();

        return [
            'version' => 3,
            'merchantIdentifier' => $settings->getApplePayMerchantId(),
            'displayName' => mb_substr($settings->getMerchantName(), 0, 64),
            'countryCode' => $settings->merchantCountryCode,
            'currencyCode' => $quote->currency,
            'supportedNetworks' => $this->appleNetworks(),
            // `supports3DS` is not optional — Apple rejects a request without it, and every network
            // that matters requires it.
            'merchantCapabilities' => ['supports3DS'],
        ];
    }

    private function googlePayConfig(Quote $quote): array
    {
        $settings = $this->settings();

        $merchantInfo = ['merchantName' => $settings->getMerchantName()];

        if ($settings->googlePayMerchantId) {
            $merchantInfo['merchantId'] = $settings->googlePayMerchantId;
        }

        return [
            'environment' => $settings->googlePayEnvironment,
            'apiVersion' => 2,
            'apiVersionMinor' => 0,
            'merchantInfo' => $merchantInfo,
            'allowedPaymentMethods' => [
                [
                    'type' => 'CARD',
                    'parameters' => [
                        'allowedAuthMethods' => $settings->allowedAuthMethods,
                        'allowedCardNetworks' => $settings->allowedCardNetworks,
                        'billingAddressRequired' => true,
                        'billingAddressParameters' => ['format' => 'FULL'],
                    ],
                    'tokenizationSpecification' => [
                        'type' => 'PAYMENT_GATEWAY',
                        'parameters' => [
                            'gateway' => (string)$settings->googlePayGateway,
                            'gatewayMerchantId' => (string)$settings->googlePayGatewayMerchantId,
                        ],
                    ],
                ],
            ],
            'countryCode' => $settings->merchantCountryCode,
            'currencyCode' => $quote->currency,
            // Google Pay wants major units as a decimal string, unlike every other number in this
            // plugin. The conversion happens here so the browser never has to know the difference.
            'decimals' => (int)round(log10(Money::subunitFactor($quote->currency))),
        ];
    }

    /**
     * @return string[]
     */
    private function appleNetworks(): array
    {
        $networks = [];

        foreach ($this->settings()->allowedCardNetworks as $network) {
            $apple = self::APPLE_NETWORKS[strtoupper((string)$network)] ?? null;

            if ($apple) {
                $networks[] = $apple;
            }
        }

        return $networks ?: ['visa', 'masterCard', 'amex', 'discover'];
    }
}
