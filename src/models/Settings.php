<?php

namespace justinholtweb\coinpurse\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;

/**
 * Coin Purse's settings.
 *
 * Nothing here is `required`. A plugin whose settings model refuses to validate cannot be
 * installed at all — Craft saves the settings as part of the install, the validation fails, and
 * the merchant is left with a half-installed plugin and no way back. Defaults are chosen so that
 * a fresh install is inert but valid, and the diagnostics screen says what is still missing.
 */
class Settings extends Model
{
    public const DRIVER_AUTO = 'auto';

    public const WALLET_APPLE_PAY = 'applePay';
    public const WALLET_GOOGLE_PAY = 'googlePay';
    public const WALLET_LINK = 'link';
    public const WALLET_PAYPAL = 'paypal';
    public const WALLET_AMAZON_PAY = 'amazonPay';
    public const WALLET_KLARNA = 'klarna';

    public const ENCODING_RAW = 'raw';
    public const ENCODING_JSON = 'json';
    public const ENCODING_BASE64 = 'base64';

    // ---------------------------------------------------------------- Gateway

    /** @var int|null The Commerce gateway express payments are charged through. */
    public ?int $gatewayId = null;

    /** @var string Which wallet driver to use: `auto`, or a driver handle. */
    public string $driver = self::DRIVER_AUTO;

    // ---------------------------------------------------------------- Wallets

    public bool $enableApplePay = true;
    public bool $enableGooglePay = true;

    /** @var bool Stripe's Express Checkout Element can also show Link. Off by default. */
    public bool $enableLink = false;
    public bool $enablePaypal = false;
    public bool $enableAmazonPay = false;
    public bool $enableKlarna = false;

    // ---------------------------------------------------------------- Sheet

    /** @var string|null Merchant name shown in the wallet sheet. Falls back to the system name. */
    public ?string $merchantName = null;

    /** @var string The merchant's own country, as an ISO 3166-1 alpha-2 code. */
    public string $merchantCountryCode = 'US';

    /** @var string[] Countries the wallet may ship to. Empty means "wherever Commerce will". */
    public array $allowedShippingCountries = [];

    /** @var bool Ask the wallet for an email address. Commerce cannot complete an order without one. */
    public bool $requireEmail = true;

    /** @var bool Ask the wallet for a phone number. */
    public bool $requirePhone = false;

    /** @var bool Ask the wallet for the payer's name. */
    public bool $requireName = true;

    // ---------------------------------------------------------------- Button

    /** @var string `default`, `buy`, `donate`, `book`, `checkout` or `pay`. */
    public string $buttonType = 'buy';

    /** @var string `dark`, `light` or `light-outline`. */
    public string $buttonTheme = 'dark';

    /** @var int Button height in pixels. Apple's guidelines put the floor at 30 and the ceiling at 64. */
    public int $buttonHeight = 48;

    // ---------------------------------------------------------------- Stripe

    /**
     * @var bool Whether to let Stripe decide which wallets a given browser can show. When off,
     *           Coin Purse hard-filters the element down to the wallets enabled above.
     */
    public bool $stripeAutoWallets = false;

    // ---------------------------------------------------------------- Apple Pay (raw driver)

    /** @var string|null e.g. `merchant.com.example.store` */
    public ?string $applePayMerchantId = null;

    /** @var string|null Path to the merchant identity certificate (PEM). */
    public ?string $applePayCertPath = null;

    /** @var string|null Path to the merchant identity private key (PEM). */
    public ?string $applePayKeyPath = null;

    /** @var string|null Passphrase for the private key, if it has one. */
    public ?string $applePayKeyPassword = null;

    /**
     * @var string|null The contents of Apple's domain association file. Coin Purse serves it at
     *                  `/.well-known/apple-developer-merchantid-domain-association`.
     */
    public ?string $applePayDomainAssociation = null;

    // ---------------------------------------------------------------- Google Pay (raw driver)

    /** @var string `TEST` or `PRODUCTION`. */
    public string $googlePayEnvironment = 'TEST';

    /** @var string|null The Google Pay merchant ID. Only needed in `PRODUCTION`. */
    public ?string $googlePayMerchantId = null;

    /** @var string|null The gateway identifier Google encrypts the token for, e.g. `braintree`. */
    public ?string $googlePayGateway = null;

    /** @var string|null The merchant's ID *at that gateway*. */
    public ?string $googlePayGatewayMerchantId = null;

    /** @var string[] */
    public array $allowedCardNetworks = ['AMEX', 'DISCOVER', 'MASTERCARD', 'VISA'];

    /** @var string[] */
    public array $allowedAuthMethods = ['PAN_ONLY', 'CRYPTOGRAM_3DS'];

    // ---------------------------------------------------------------- Token delivery (raw driver)

    /**
     * @var string|null The attribute on the gateway's payment form model that receives the wallet
     *                  token. Left empty, Coin Purse guesses from the gateway class.
     */
    public ?string $tokenTargetAttribute = null;

    /** @var string `raw`, `json` or `base64`. */
    public string $tokenEncoding = self::ENCODING_RAW;

    // ---------------------------------------------------------------- Housekeeping

    public bool $loggingEnabled = true;

    /** @var bool Keep request and response bodies. They contain addresses, so this defaults off. */
    public bool $logPayloads = false;

    public int $logRetentionDays = 30;

    /** @var int How long an express session may stay open before it is refused. */
    public int $sessionLifetimeMinutes = 30;

    /**
     * @var bool Register `craft.webPayments.button()` as an alias, so templates written against
     *           `ether/web-payments` keep working. Skipped automatically if that plugin is present.
     */
    public bool $registerWebPaymentsAlias = true;

    // ---------------------------------------------------------------- Rules

    public function rules(): array
    {
        return [
            [['gatewayId', 'buttonHeight', 'logRetentionDays', 'sessionLifetimeMinutes'], 'integer'],
            [['buttonHeight'], 'integer', 'min' => 30, 'max' => 64],
            [['logRetentionDays'], 'integer', 'min' => 0, 'max' => 3650],
            [['sessionLifetimeMinutes'], 'integer', 'min' => 1, 'max' => 1440],
            [['merchantCountryCode'], 'match', 'pattern' => '/^[A-Z]{2}$/', 'skipOnEmpty' => true],
            [['buttonType'], 'in', 'range' => ['default', 'buy', 'donate', 'book', 'checkout', 'pay']],
            [['buttonTheme'], 'in', 'range' => ['dark', 'light', 'light-outline']],
            [['googlePayEnvironment'], 'in', 'range' => ['TEST', 'PRODUCTION']],
            [['tokenEncoding'], 'in', 'range' => [self::ENCODING_RAW, self::ENCODING_JSON, self::ENCODING_BASE64]],
            [['driver'], 'string', 'max' => 64],
            [['merchantName', 'applePayMerchantId', 'applePayCertPath', 'applePayKeyPath',
                'applePayKeyPassword', 'googlePayMerchantId', 'googlePayGateway',
                'googlePayGatewayMerchantId', 'tokenTargetAttribute'], 'string', 'max' => 255],
            [['allowedShippingCountries', 'allowedCardNetworks', 'allowedAuthMethods'], 'safe'],
            [['applePayDomainAssociation'], 'safe'],
        ];
    }

    // ---------------------------------------------------------------- Derived

    public function getMerchantName(): string
    {
        $name = App::parseEnv($this->merchantName);

        if ($name) {
            return $name;
        }

        return Craft::$app->getSystemName() ?: 'Store';
    }

    /**
     * The wallets the merchant has switched on, as driver-neutral handles.
     *
     * @return string[]
     */
    public function getEnabledWallets(): array
    {
        $map = [
            self::WALLET_APPLE_PAY => $this->enableApplePay,
            self::WALLET_GOOGLE_PAY => $this->enableGooglePay,
            self::WALLET_LINK => $this->enableLink,
            self::WALLET_PAYPAL => $this->enablePaypal,
            self::WALLET_AMAZON_PAY => $this->enableAmazonPay,
            self::WALLET_KLARNA => $this->enableKlarna,
        ];

        return array_keys(array_filter($map));
    }

    public function getApplePayCertPath(): ?string
    {
        return App::parseEnv($this->applePayCertPath) ?: null;
    }

    public function getApplePayKeyPath(): ?string
    {
        return App::parseEnv($this->applePayKeyPath) ?: null;
    }

    public function getApplePayKeyPassword(): ?string
    {
        return App::parseEnv($this->applePayKeyPassword) ?: null;
    }

    public function getApplePayMerchantId(): ?string
    {
        return App::parseEnv($this->applePayMerchantId) ?: null;
    }

    public function getApplePayDomainAssociation(): ?string
    {
        $value = App::parseEnv($this->applePayDomainAssociation);

        if (!$value) {
            return null;
        }

        // Merchants paste either the file's contents or a path to it. Apple's file is a single
        // long line of base64-ish text with no slashes at the start, so a leading `/` or a `.txt`
        // suffix is an unambiguous signal that this is a path.
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '/') && is_file($trimmed) && is_readable($trimmed)) {
            return (string)file_get_contents($trimmed);
        }

        return $trimmed;
    }

    /**
     * The domain Apple Pay is being initiated from. Apple wants the fully-qualified host, not a URL.
     */
    public function getInitiativeContext(): string
    {
        return (string)parse_url(UrlHelper::baseSiteUrl(), PHP_URL_HOST);
    }

    public function getEffectiveLogRetentionDays(): int
    {
        return max(0, $this->logRetentionDays);
    }
}
