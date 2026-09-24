---
title: Configuration
slug: configuration
order: 20
summary: Every setting, the Apple Pay and Google Pay setup for the direct driver, and overriding it all from a config file.
---

Settings live at **Coin Purse → Settings**. Anything that holds a secret or differs between
environments accepts an environment variable, written `$APPLE_PAY_MERCHANT_ID`.

## Gateway and driver

| Setting | Default | What it does |
| --- | --- | --- |
| Gateway | none | The Commerce gateway express payments are charged through. |
| Driver | Automatic | Automatic uses Stripe's Express Checkout Element when the gateway is Stripe and speaks Apple Pay and Google Pay directly for everything else. You can force either. |

## Wallets

| Setting | Default |
| --- | --- |
| Apple Pay | On |
| Google Pay | On |
| Link, PayPal, Amazon Pay, Klarna | Off |
| Let Stripe choose | Off |

The second group only applies to the Stripe driver, whose Express Checkout Element can show them
from the same button. They are off by default, because a PayPal button appearing on a product page
nobody configured is a support ticket, not a feature.

With **Let Stripe choose** off, Coin Purse filters the element down to exactly the wallets switched
on here. Turn it on to let Stripe decide per browser.

## The sheet

| Setting | Default | What it does |
| --- | --- | --- |
| Merchant name | Craft's system name | The name shown in the wallet sheet. |
| Merchant country | `US` | Your own country, as an ISO 3166-1 code. The wallets require it. |
| Allowed shipping countries | empty | Countries the wallet may ship to. Empty means wherever Commerce will. |
| Require name | On | |
| Require email | On | Commerce cannot complete an order without one, so this is always collected. |
| Require phone | Off | |

## Button defaults

| Setting | Default | Options |
| --- | --- | --- |
| Type | `buy` | `default`, `buy`, `donate`, `book`, `checkout`, `pay` |
| Theme | `dark` | `dark`, `light`, `light-outline` |
| Height | `48` | Pixels. Apple's guidelines allow 30 to 64. |

Any of these can be overridden per button with the `style` option.

## Apple Pay (direct driver)

Only needed when the gateway is not Stripe.

| Setting | What it is |
| --- | --- |
| Merchant ID | e.g. `merchant.com.example.store`, from your Apple developer account |
| Certificate path | Your merchant identity certificate, as a PEM file |
| Key path | Its private key, as a PEM file |
| Key password | If the key has one |
| Domain association | The contents of Apple's domain association file, or a path to it |

Coin Purse serves the domain association file at
`/.well-known/apple-developer-merchantid-domain-association`, so there is nothing to upload to your
web root. Register the domain in your Apple developer account once it is live.

Keep the certificate and key outside the web root and point to them with environment variables.

## Google Pay (direct driver)

| Setting | Default | What it is |
| --- | --- | --- |
| Environment | `TEST` | `TEST` or `PRODUCTION` |
| Merchant ID | none | Your Google Pay merchant ID. Only needed in `PRODUCTION`. |
| Gateway | none | The identifier Google should encrypt the token for, e.g. `braintree` |
| Gateway merchant ID | none | Your merchant ID *at that gateway* |
| Card networks | Amex, Discover, Mastercard, Visa | |
| Auth methods | `PAN_ONLY`, `CRYPTOGRAM_3DS` | |

Google Pay needs no certificate. Both wallets encrypt their token to the gateway, so it passes
through your site opaque.

## Wallet token

Gateway plugins disagree about what the field holding a wallet token is called.

| Setting | Default | What it does |
| --- | --- | --- |
| Token field | empty | The attribute on the gateway's payment form that receives the token. Empty means Coin Purse looks for a known name. |
| Token encoding | `raw` | `raw`, `json` or `base64`, whichever the gateway expects |

Diagnostics reports which field it found. Name it explicitly if the guess is wrong.

## Logging and sessions

| Setting | Default | What it does |
| --- | --- | --- |
| Logging | On | Records each express step to **Coin Purse → Log**. |
| Log payloads | Off | Keeps request and response bodies. They contain addresses, which is why this is off. |
| Log retention | 30 days | Applied by `coinpurse/log/prune`. |
| Session lifetime | 30 minutes | How long a wallet sheet may stay open before its session is refused. |

## Web Payments alias

| Setting | Default |
| --- | --- |
| Register `craft.webPayments.button()` | On |

Lets templates written for `ether/web-payments` keep working. It is skipped automatically if that
plugin is still installed.

## Config file

Every setting can be set in `config/coinpurse.php`, which takes precedence over the control panel:

```php
<?php

use craft\helpers\App;

return [
    '*' => [
        'buttonHeight' => 44,
        'allowedShippingCountries' => ['US', 'CA'],
    ],
    'production' => [
        'googlePayEnvironment' => 'PRODUCTION',
        'applePayMerchantId' => App::env('APPLE_PAY_MERCHANT_ID'),
        'applePayCertPath' => App::env('APPLE_PAY_CERT_PATH'),
        'applePayKeyPath' => App::env('APPLE_PAY_KEY_PATH'),
    ],
];
```
