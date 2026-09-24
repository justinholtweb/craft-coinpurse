---
title: Installation
slug: installation
order: 10
summary: Requirements, install, choosing a gateway, and the one screen to check before you put a button on a page.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- **HTTPS**, on every environment you want to see a button on

The HTTPS requirement is Apple's and Google's, not Coin Purse's. Both wallets refuse to run on an
insecure page, and they refuse silently: no error, no console message, just no button. Local
development over DDEV or Herd is fine, since both serve HTTPS.

## Install

```sh
composer require justinholtweb/craft-coinpurse
php craft plugin/install coinpurse
```

Or search for **Coin Purse** in the Plugin Store.

## Editions

| Edition | Price | What you get |
| --- | --- | --- |
| Coin Purse | $79 | Everything. Apple Pay and Google Pay, both drivers, the diagnostics screen, the log and the console commands. |

There is one edition. Nothing in the plugin is gated.

## Choose a gateway

Open **Coin Purse → Settings** and pick the Commerce gateway express payments should be charged
through. Coin Purse chooses a driver from it:

- **Stripe** (`craftcms/commerce-stripe`, Payment Intents): the Stripe driver. Register your
  domain for Apple Pay in the Stripe dashboard and you are done. Stripe holds the certificate.
- **Any other gateway**: the direct driver, which speaks Apple Pay JS and the Google Pay API
  itself. It needs an Apple merchant ID and certificate, and a Google Pay gateway identifier. See
  [Configuration](configuration).

## Check Diagnostics

Open **Coin Purse → Diagnostics** before you put a button on a template. It checks Commerce, HTTPS,
the gateway, the driver, the wallets, each driver's own configuration, the wallet token field,
your shipping methods and the Apple Pay domain association file, and says what to do about
anything that fails.

A misconfigured wallet button does not show an error. It simply does not appear, which is why this
screen exists.

The same checks run from the command line, and exit non-zero when something is broken:

```sh
php craft coinpurse/diagnose
```

That is worth adding to a deploy script. It stops a release that would ship a checkout with no
button on it.

## Put a button on a page

```twig
{{ craft.coinpurse.button({
    items: [{ id: product.defaultVariant.id, qty: 1 }],
    requestShipping: 'delivery',
    onComplete: { redirect: '/thanks?number={number}' },
}) }}
```

The page needs a normal `<html>` and `<body>`. Craft injects the button's script and styles into
them, and a template without them renders the button's markup with nothing to make it work.

See [Usage](usage) for every option.

## Scheduled tasks

Two commands are worth putting on a schedule:

```sh
php craft coinpurse/sessions/reap    # every 15 minutes or so
php craft coinpurse/log/prune        # daily
```

`reap` restores carts left addressed by wallet sheets that were abandoned by closing the tab, which
never sends a cancel. See [Usage](usage#the-cart-and-abandoned-sheets).
